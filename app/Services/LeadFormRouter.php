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
     * @return array{project_id: int, holder_id: int, form_id: ?string, form_name: ?string, routed: bool, route: ?LeadFormRoute}
     */
    public function route(Integration $integration, ?string $formId): array
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

        $route = LeadFormRoute::firstOrCreate(['provider' => $integration->provider, 'form_id' => $formId]);

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
     * One alert per table row, so mapping one form clears only its own, and a
     * removed form that returns is a new row with a new alert.
     */
    public static function alertType(LeadFormRoute $route): string
    {
        return "lead_form_unmapped.{$route->provider}.{$route->form_id}.{$route->id}";
    }

    /**
     * The stored name, else Meta's, else the raw id.
     *
     * Never throws. A lead that cannot be named is still a lead, and an
     * expired token or a Graph outage here must not cost the customer.
     */
    private function formName(LeadFormRoute $route, Integration $integration): string
    {
        if (filled($route->form_name)) {
            return $route->form_name;
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
