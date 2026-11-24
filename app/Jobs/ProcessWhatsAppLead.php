<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use App\Models\LeadFormRoute;
use App\Models\Project;
use App\Models\User;
use App\Services\IncomingLeadService;
use App\Services\IntegrationLogger;
use App\Services\LeadFollowUpService;
use App\Services\LeadFormRouter;
use App\Services\MetaLeadNormaliser;
use App\Support\CrmTaxonomy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Everything that happens to an 11za WhatsApp contact after the webhook has
 * said 200.
 *
 * Queued for the same reason ProcessMetaLead is: 11za gives the webhook ten
 * seconds and retries anything slower, so the endpoint answers at once and the
 * lead is created here. It is also what "Send test lead" runs for WhatsApp.
 *
 * 11za sends the contact itself, so there is no API call to fail transiently
 * and nothing a retry could fix: one attempt, and a failure is logged and
 * parked in failed_jobs.
 *
 * The project is chosen by the contact's 11za tags and label, through the same
 * LeadFormRouter as Facebook forms — see LeadFormRouter::routeTags(). An
 * update that newly tags a number for a project either leaves it alone (it is
 * already there), moves the unrouted lead 11za created on the default project,
 * or opens a new enquiry on that project — see handleUpdate().
 *
 * All of that only while `integrations.providers.whatsapp.tag_routing` is on.
 * Off, tags and labels are ignored: every lead goes to the default project and
 * the configured telecaller, who moves it with Switch project, and a number
 * that is already a lead on any project is a repeat enquiry on that lead.
 */
class ProcessWhatsAppLead implements ShouldQueue
{
    use Queueable;

    public const PROVIDER = 'whatsapp';

    /** What a contact with no name is called until somebody rings it. */
    private const FALLBACK_NAME = 'WhatsApp Lead';

    public $tries = 1;

    /**
     * @param  string  $type  "insert" or "update", as 11za sent it
     * @param  array{name?: ?string, mobileNo?: ?string, updatedAt?: ?string, tags?: array|string|null, masterstatus?: ?string, teamMemberEmail?: ?string, teamMemberName?: ?string}  $data
     * @param  bool  $isTestRun  Send test lead: the lead is real, but it is not a lead received
     */
    public function __construct(
        public string $type,
        public array $data,
        public bool $isTestRun = false,
    ) {}

    public function handle(
        MetaLeadNormaliser $normaliser,
        IncomingLeadService $leads,
        IntegrationLogger $log,
        LeadFormRouter $router,
        LeadFollowUpService $followUps,
    ): void {
        $integration = Integration::forProvider(self::PROVIDER);
        $mobile = $normaliser->phone($this->data['mobileNo'] ?? null);
        $externalId = $this->externalId($mobile);

        try {
            if (! $integration->isReady()) {
                throw new \RuntimeException(
                    'The integration is switched off or not fully configured, so the lead was not imported.'
                );
            }

            if ($mobile === null) {
                throw new \RuntimeException(
                    'The mobile number from 11za ("'.($this->data['mobileNo'] ?? '').'") has fewer than ten digits.'
                );
            }

            // with tag routing off, no tags: the default project and telecaller
            $routing = $router->routeTags($integration, $this->tagRouting() ? $this->tags() : []);

            if ($this->type === 'update' && $this->handleUpdate($integration, $routing, $mobile, $externalId, $log, $followUps)) {
                return;
            }

            $this->import($normaliser, $leads, $log, $router, $integration, $routing, $mobile, $externalId);
        } catch (Throwable $e) {
            $log->failed(self::PROVIDER, $externalId, $e->getMessage());

            $this->fail($e);
        }
    }

    /**
     * An update for a number we may already have. True when it was dealt
     * with here; false when it should be imported like an insert.
     *
     *   a) no mapped project     today's behaviour: logged against the lead on
     *                            the default project, or imported if there is none
     *   b) already on P          nothing changes; logged
     *   c) unrouted 11za lead    still open on the default project → moved to P
     *   d) anything else         imported on P: a new enquiry for that project
     *
     * @param  array<string, mixed>  $routing  from LeadFormRouter::routeTags()
     */
    private function handleUpdate(
        Integration $integration,
        array $routing,
        string $mobile,
        string $externalId,
        IntegrationLogger $log,
        LeadFollowUpService $followUps,
    ): bool {
        $defaultProjectId = (int) $integration->setting('default_project_id');
        $form = $this->form($routing);

        if (! $routing['routed']) {
            $existing = Lead::withTrashed()
                ->where('mobile_number', $mobile)
                ->where('project_id', $defaultProjectId)
                ->first()
                // already moved off the default project with Switch project
                ?? ($this->tagRouting() ? null : $this->latestLiveLead($mobile));

            /*
             | An update for a number we already have, with no project to
             | route it to, changes nothing: it is only logged so it shows in
             | Recent activity. An unknown number falls through to insert.
             */
            if ($existing) {
                $log->updateReceived(self::PROVIDER, $externalId, $existing, "11za sent an update for lead #{$existing->id}; nothing changed.", $form);

                return true;
            }

            return false;
        }

        $projectId = $routing['project_id'];

        $onProject = Lead::where('mobile_number', $mobile)->where('project_id', $projectId)->orderBy('id')->first();

        if ($onProject) {
            $log->updateReceived(
                self::PROVIDER,
                $externalId,
                $onProject,
                "11za sent an update for lead #{$onProject->id}, which is already on {$onProject->project?->name}; nothing changed.",
                $form,
            );

            return true;
        }

        $unrouted = $projectId === $defaultProjectId ? null : $this->unroutedLeadOn($defaultProjectId, $mobile);

        if (! $unrouted) {
            return false;
        }

        $from = $unrouted->project?->name;
        $to = Project::findOrFail($projectId);

        $followUps->asSystem(function () use ($followUps, $unrouted, $to, $routing) {
            $followUps->switchProject($unrouted, $to);

            $unrouted->refresh();

            // the route's own telecaller, while calling it is still the work
            if ($routing['route_holder_id'] && $unrouted->stage === 'fresh') {
                $telecaller = User::find($routing['route_holder_id']);

                if ($telecaller) {
                    $followUps->assign($unrouted, $telecaller);
                }
            }
        });

        $log->movedToProject(
            self::PROVIDER,
            $externalId,
            $unrouted,
            "Lead #{$unrouted->id} moved from {$from} to {$to->name}: 11za tagged it \"{$routing['route']->label()}\".",
            $form,
        );

        return true;
    }

    /** Whether 11za tags choose the project; see config/integrations.php. */
    private function tagRouting(): bool
    {
        return (bool) config('integrations.providers.'.self::PROVIDER.'.tag_routing', false);
    }

    /** The most recently active live lead on this number, on any project. */
    private function latestLiveLead(string $mobile): ?Lead
    {
        return Lead::where('mobile_number', $mobile)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The lead 11za created on the default project without a project of its
     * own, if it is still there and still open — the one an update tagging it
     * for a project should move rather than duplicate.
     *
     * Read from the activity log: its `created` row with `routed` false. A row
     * with `routed` null was written before tag routing existed, when every
     * 11za lead went to the default project, so it counts as unrouted too.
     */
    private function unroutedLeadOn(int $projectId, string $mobile): ?Lead
    {
        return Lead::where('mobile_number', $mobile)
            ->where('project_id', $projectId)
            ->whereNotIn('stage', CrmTaxonomy::terminalStages())
            ->whereIn('id', IntegrationEvent::forProvider(self::PROVIDER)
                ->where('result', 'created')
                ->whereNotNull('lead_id')
                ->where(fn ($q) => $q->where('routed', false)->orWhereNull('routed'))
                ->select('lead_id'))
            ->orderBy('id')
            ->first();
    }

    /**
     * Create the lead on the routed project — or say why not — and log it.
     *
     * @param  array<string, mixed>  $routing  from LeadFormRouter::routeTags()
     */
    private function import(
        MetaLeadNormaliser $normaliser,
        IncomingLeadService $leads,
        IntegrationLogger $log,
        LeadFormRouter $router,
        Integration $integration,
        array $routing,
        string $mobile,
        string $externalId,
    ): void {
        [$first, $middle, $last] = $normaliser->splitNameParts((string) ($this->data['name'] ?? ''));

        if ($first === '') {
            [$first, $middle, $last] = $normaliser->splitNameParts(self::FALLBACK_NAME);
        }

        $outcome = $leads->import(
            integration: $integration,
            externalId: $externalId,
            attributes: [
                'first_name' => $first,
                // folded into the last name, as the Meta import does
                'last_name' => trim($middle !== '' ? $middle.' '.$last : $last),
                'mobile_number' => $mobile,
                'email' => null,
            ],
            source: self::PROVIDER,
            projectId: $routing['project_id'],
            holderId: $routing['holder_id'],
            // the tag that chose the project; nothing when none did
            formId: $routing['routed'] ? $routing['route']->form_id : null,
            // tag routing off: a number already on any project is that lead's repeat
            repeatOnAnyProject: ! $this->tagRouting(),
        );

        $form = $this->form($routing);

        match ($outcome['result']) {
            IncomingLeadService::DUPLICATE => $log->duplicate(self::PROVIDER, $externalId, $outcome['message'], $form),
            IncomingLeadService::REPEAT => $log->repeatEnquiry(self::PROVIDER, $externalId, $outcome['message'], $form, $this->tagRouting() ? null : $outcome['lead']),
            default => $log->created(self::PROVIDER, $externalId, $outcome['lead'], $form + ['note' => $this->routingNote($routing)], countAsReceived: ! $this->isTestRun),
        };

        if ($outcome['result'] === 'created' && ! $routing['routed']) {
            foreach ($routing['unmapped'] as $unmapped) {
                $router->noteUnrouted(self::PROVIDER, $unmapped);
            }
        }
    }

    /**
     * The contact's routing values: every tag, plus the label when the admin
     * has mapped it.
     *
     * 11za sends `tags` as a list or as one comma-separated string, and a
     * list item may be an object; its name, tag, label or title is used (the
     * first one present), and an item with none of them is ignored. Every
     * value is split on commas, so a normalised tag never holds one — the
     * activity log stores a lead's tags comma-joined. The first spelling of a
     * tag wins.
     *
     * The label (`masterstatus`) is a status such as "New", not a project, so
     * it never creates a row or an alert: it counts only when it matches a
     * row the admin added and mapped to an active project.
     *
     * @return array<string, string> normalised tag => the tag as received
     */
    private function tags(): array
    {
        $raw = $this->data['tags'] ?? [];
        $tags = [];

        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            $tags += $this->split($this->tagText($item));
        }

        foreach ($this->split($this->tagText($this->data['masterstatus'] ?? null)) as $key => $label) {
            $mapped = LeadFormRoute::forProvider(self::PROVIDER)
                ->where('form_id', $key)
                ->whereIn('project_id', Project::active()->select('id'))
                ->exists();

            if ($mapped) {
                $tags[$key] ??= $label;
            }
        }

        return $tags;
    }

    /** A tag's text: a scalar as it is, an object by its first naming key. */
    private function tagText(mixed $item): ?string
    {
        if (is_array($item)) {
            foreach (['name', 'tag', 'label', 'title'] as $field) {
                if (isset($item[$field])) {
                    $item = $item[$field];
                    break;
                }
            }
        }

        return is_scalar($item) ? (string) $item : null;
    }

    /**
     * @return array<string, string> normalised tag => the tag as received
     */
    private function split(?string $value): array
    {
        $tags = [];

        foreach (explode(',', (string) $value) as $tag) {
            $key = LeadFormRoute::normaliseTag($tag);

            if ($key !== '') {
                $tags[$key] ??= trim((string) preg_replace('/\s+/u', ' ', $tag));
            }
        }

        return $tags;
    }

    /**
     * What the activity log row records about the tags.
     *
     * @param  array<string, mixed>  $routing
     * @return array{form_id: ?string, form_name: ?string, routed: bool}
     */
    private function form(array $routing): array
    {
        return [
            // the column holds 255; whole tags only, so the list still parses
            'form_id' => $this->fitTags($routing['form_id'], ','),
            'form_name' => $this->fitTags($routing['form_name'], ', '),
            'routed' => $routing['routed'],
        ];
    }

    private function fitTags(?string $joined, string $glue): ?string
    {
        if ($joined === null || mb_strlen($joined) <= 255) {
            return $joined;
        }

        $kept = [];

        foreach (explode($glue, $joined) as $tag) {
            if (mb_strlen(implode($glue, [...$kept, $tag])) > 255) {
                break;
            }

            $kept[] = $tag;
        }

        return implode($glue, $kept);
    }

    /**
     * Why an unrouted lead is on the default project, in 11za's terms.
     *
     * @param  array<string, mixed>  $routing
     */
    private function routingNote(array $routing): ?string
    {
        if ($routing['routed']) {
            return null;
        }

        if (! $this->tagRouting()) {
            return 'Every WhatsApp lead goes to the default project; use Switch project to move it.';
        }

        if ($routing['conflict'] !== null) {
            return "Its 11za tags point to different projects ({$routing['conflict']}), so it was filed under the default project.";
        }

        if ($routing['form_id'] === null) {
            return 'It came with no 11za tag, so it was filed under the default project.';
        }

        return count($routing['unmapped']) === 1
            ? "Its 11za tag \"{$routing['form_name']}\" has no active project, so it was filed under the default project."
            : "None of its 11za tags (\"{$routing['form_name']}\") has an active project, so it was filed under the default project.";
    }

    /**
     * 11za's own dedupe key: mobile number plus updatedAt.
     *
     * When updatedAt is missing, a hash of the whole event stands in for it,
     * so a redelivery of the same event is still a duplicate while a
     * different event from the same number is not.
     */
    private function externalId(?string $mobile): string
    {
        $updatedAt = trim((string) ($this->data['updatedAt'] ?? ''));

        $suffix = $updatedAt !== ''
            ? $updatedAt
            : substr(sha1(json_encode([$this->type, $this->data])), 0, 16);

        return "11za:{$mobile}:{$suffix}";
    }
}
