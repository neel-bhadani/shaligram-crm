<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\LeadFormRoute;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which project an incoming lead belongs to, decided by the form it was
 * submitted on.
 *
 * Meta's `leadgen` notification carries the `form_id`, and a client running
 * one form per project is the normal case — so the form is the answer, and the
 * integration's default project is only the fallback for a form nobody has
 * mapped yet.
 *
 * An unmapped form is never an error. Its lead is still created, in the
 * fallback project, and the form is written into `lead_form_routes` with no
 * project so it appears in the settings modal waiting to be assigned. The
 * admins are alerted once, on the first such lead; every lead after that
 * rewrites the same alert's count rather than ringing the bell again.
 *
 * 11za WhatsApp leads are routed the same way, by tag instead of form: every
 * tag (and the label) of a contact is one route — see routeTags().
 *
 * Who gets the lead is still LeadAssignmentService's decision. The route's
 * telecaller — or the integration's — is only the holder handed to it, exactly
 * as the integration's configured user always was.
 */
class LeadFormRouter
{
    public function __construct(
        private MetaGraphClient $graph,
        private AlertService $alerts,
    ) {}

    /**
     * Where this form's lead goes.
     *
     * `$formName` names a row this call creates — an 11za tag as 11za wrote
     * it. A Meta form is named from Graph instead, see formName().
     *
     * @return array{project_id: int, holder_id: int, form_id: ?string, form_name: ?string, routed: bool, route: ?LeadFormRoute}
     */
    public function route(Integration $integration, ?string $formId, ?string $formName = null): array
    {
        $fallback = [
            'project_id' => (int) $integration->setting('default_project_id'),
            'holder_id' => (int) $integration->setting('assign_to_user_id'),
            'form_id' => $formId,
            'form_name' => null,
            'routed' => false,
            'route' => null,
        ];

        // a test lead, or a delivery from before Meta sent form ids
        if ($formId === null || $formId === '') {
            return ['form_id' => null] + $fallback;
        }

        $route = LeadFormRoute::firstOrCreate(
            ['provider' => $integration->provider, 'form_id' => $formId],
            ['form_name' => $formName],
        );

        $fallback['route'] = $route;
        $fallback['form_name'] = $this->formName($route, $integration);

        // a project that has since been archived is no better than none: its
        // leads would land somewhere nobody is working
        if (! $route->project_id || ! Project::active()->whereKey($route->project_id)->exists()) {
            return $fallback;
        }

        $holderId = $route->assign_to_user_id && User::whereKey($route->assign_to_user_id)->exists()
            ? $route->assign_to_user_id
            : $fallback['holder_id'];

        return ['project_id' => $route->project_id, 'holder_id' => $holderId, 'routed' => true] + $fallback;
    }

    /**
     * Where an 11za contact goes, decided by its tags.
     *
     * Each tag is routed exactly as a form is, so an unseen tag is written to
     * the table for the admin to map. Then:
     *
     *   one active project among the mapped tags   that project, and the
     *                                              route's telecaller
     *   none                                       the default project, unrouted
     *   two or more different projects             the default project,
     *                                              unrouted, and `conflict`
     *                                              says which
     *
     * `form_id` is every tag, normalised and comma-joined, so the activity log
     * and the settings list can find a lead by any of its tags; `unmapped` is
     * the routes noteUnrouted() should alert about.
     *
     * @param  array<string, string>  $tags  normalised tag => the tag as received
     * @return array{project_id: int, holder_id: int, form_id: ?string, form_name: ?string, routed: bool, route: ?LeadFormRoute, route_holder_id: ?int, unmapped: list<array{form_id: string, form_name: string, route: LeadFormRoute}>, conflict: ?string}
     */
    public function routeTags(Integration $integration, array $tags): array
    {
        $mapped = [];
        $unmapped = [];

        foreach ($tags as $key => $label) {
            $routing = $this->route($integration, (string) $key, $label);

            if ($routing['routed']) {
                $mapped[$routing['project_id']] ??= $routing;
            } else {
                $unmapped[] = $routing;
            }
        }

        $result = $this->route($integration, null) + [
            'route_holder_id' => null,
            'unmapped' => $unmapped,
            'conflict' => null,
        ];

        $result['form_id'] = $tags === [] ? null : implode(',', array_map('strval', array_keys($tags)));
        $result['form_name'] = $tags === [] ? null : implode(', ', $tags);

        if (count($mapped) === 1) {
            $chosen = reset($mapped);
            $route = $chosen['route'];

            return [
                'project_id' => (int) $chosen['project_id'],
                'holder_id' => $chosen['holder_id'],
                'routed' => true,
                'route' => $route,
                // only a telecaller of the route's own, not the default one
                'route_holder_id' => $route->assign_to_user_id && (int) $chosen['holder_id'] === (int) $route->assign_to_user_id
                    ? (int) $route->assign_to_user_id
                    : null,
            ] + $result;
        }

        if (count($mapped) > 1) {
            $result['conflict'] = collect($mapped)
                ->map(fn (array $r) => '"'.$r['form_name'].'" → '.Project::whereKey($r['project_id'])->value('name'))
                ->implode(', ');
        }

        return $result;
    }

    /**
     * Tell the admins a form is sending leads to the fallback project — once.
     *
     * Called after the lead is created and logged, so the count includes it.
     *
     * Once per table row, not once per form id ever: a form the admin removed
     * and that then sends another lead comes back as a new row, and that is
     * news — so it gets a new alert, counting only the leads since it came
     * back.
     *
     * @param  array{form_id: ?string, form_name: ?string, route: ?LeadFormRoute}  $routing
     */
    public function noteUnrouted(string $provider, array $routing): void
    {
        $route = $routing['route'];

        if ($route === null) {
            return;
        }

        $type = self::alertType($route);

        if ($provider === 'whatsapp') {
            $this->alertUnmappedTag($type, $route);

            return;
        }

        $count = IntegrationEvent::forProvider($provider)
            ->where('form_id', $route->form_id)
            ->where('result', 'created')
            ->where('routed', false)
            ->where('created_at', '>=', $route->created_at)
            ->count();

        $title = "Leads from form \"{$routing['form_name']}\" are going to the fallback project";
        $body = ($count === 1 ? '1 lead' : "{$count} leads").' from this form went to the fallback project because '
            .'the form has no active project. Open Integrations → Facebook settings → Lead forms and choose one.';

        if ($this->alerts->everRaised($type)) {
            $this->alerts->revise($type, $title, $body);

            return;
        }

        $this->alerts->raiseMany(
            recipients: $this->alerts->admins(),
            type: $type,
            title: $title,
            body: $body,
            severity: 'warning',
            actionUrl: route('integrations.index'),
        );
    }

    /**
     * The 11za version of the alert: once per tag row, worded for WhatsApp.
     * No running count — a lead carries several tags, so "leads from this
     * tag" is not one number the way "leads from this form" is.
     */
    private function alertUnmappedTag(string $type, LeadFormRoute $route): void
    {
        if ($this->alerts->everRaised($type)) {
            return;
        }

        $tag = $route->label();

        $this->alerts->raiseMany(
            recipients: $this->alerts->admins(),
            type: $type,
            title: "11za tag \"{$tag}\" has no project",
            body: "Leads tagged \"{$tag}\" in 11za are going to the default project. "
                .'Open Integrations → WhatsApp (11za) → Projects by 11za tag and choose a project.',
            severity: 'warning',
            actionUrl: route('integrations.index'),
        );
    }

    /**
     * One alert per table row, so mapping one form clears only its own, and a
     * removed form that returns is a new row with a new alert.
     */
    public static function alertType(LeadFormRoute $route): string
    {
        return "lead_form_unmapped.{$route->provider}.{$route->form_id}.{$route->id}";
    }

    /**
     * The stored name, else Meta's, else the raw id. Only a Meta form has a
     * name to look up; anything else is called by its id.
     *
     * Never throws. A lead that cannot be named is still a lead, and an
     * expired token or a Graph outage here must not cost the customer.
     */
    private function formName(LeadFormRoute $route, Integration $integration): string
    {
        if (filled($route->form_name)) {
            return $route->form_name;
        }

        if (config("integrations.providers.{$integration->provider}.family") !== 'meta') {
            return $route->form_id;
        }

        try {
            $name = $this->graph->formName($route->form_id, (string) $integration->setting('page_access_token'));
        } catch (Throwable $e) {
            Log::warning("[integration:{$integration->provider}] form name lookup failed", [
                'form_id' => $route->form_id,
                'message' => $e->getMessage(),
            ]);

            $name = null;
        }

        if ($name !== null) {
            $route->update(['form_name' => $name]);
        }

        return $name ?? $route->form_id;
    }
}
