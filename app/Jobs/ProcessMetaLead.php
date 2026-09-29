<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Services\IncomingLeadService;
use App\Services\IntegrationLogger;
use App\Services\LeadFormRouter;
use App\Services\MetaGraphClient;
use App\Services\MetaLeadNormaliser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Everything that happens to a Facebook lead after the webhook has said 200.
 *
 * Queued, because Meta gives a webhook a few seconds and retries anything it
 * considers slow — and this does a Graph round trip, so a synchronous webhook
 * would time out under exactly the load that matters and every retry would be
 * another delivery of a lead already being imported. The endpoint answers
 * immediately and this runs after.
 *
 * This is also what the "Send test lead" button runs. Not a copy of it and not
 * a shortcut through it: the same class, the same normalising, the same
 * idempotency checks, the same lead creation, the same log line. The single
 * difference is `$fieldData` — a test supplies the answers directly because
 * Meta has no leadgen_id for a lead nobody filled in, and cannot reach a
 * laptop to be asked. Everything after that point is byte-for-byte the path a
 * real lead takes.
 */
class ProcessMetaLead implements ShouldQueue
{
    use Queueable;

    /**
     * Meta redelivers on any non-2xx, so the job's own retries are kept small:
     * a Graph outage is better answered by Meta's redelivery an hour later than
     * by this job sitting in the queue.
     */
    public $tries = 3;

    public $backoff = [10, 60];

    /**
     * @param  string  $provider  which card on the Integrations page this is
     * @param  string  $leadgenId  Meta's id for the enquiry, and our external_id
     * @param  ?array  $fieldData  pre-supplied answers; null means fetch them from Graph
     * @param  ?string  $formId  the lead form it was submitted on, from the webhook payload
     */
    public function __construct(
        public string $provider,
        public string $leadgenId,
        public ?array $fieldData = null,
        public ?string $formId = null,
    ) {}

    public function handle(
        MetaGraphClient $graph,
        MetaLeadNormaliser $normaliser,
        IncomingLeadService $leads,
        IntegrationLogger $log,
        LeadFormRouter $router,
    ): void {
        $integration = Integration::forProvider($this->provider);

        try {
            if (! $integration->isReady()) {
                throw new \RuntimeException(
                    'The integration is switched off or not fully configured, so the lead was not imported.'
                );
            }

            /*
             | The Graph call, unless the answers came with the job.
             |
             | A real webhook carries only an id — Meta never sends the form
             | answers — so this is where a lead actually becomes readable, and
             | it is the step that fails when a page access token expires.
             */
            $fieldData = $this->fieldData
                ?? $graph->fieldData($this->leadgenId, $integration->setting('page_access_token'));

            $normalised = $normaliser->normalise($fieldData, $this->leadgenId);

            // the form decides the project; an unmapped one falls back to the
            // integration's default and is recorded as waiting to be mapped
            $routing = $router->route($integration, $this->formId);
            $form = array_intersect_key($routing, array_flip(['form_id', 'form_name', 'routed']));

            $outcome = $leads->import(
                integration: $integration,
                externalId: $this->leadgenId,
                attributes: [
                    'first_name' => $normalised['first_name'],
                    'last_name' => $normalised['last_name'],
                    'mobile_number' => $normalised['mobile_number'],
                    'email' => $normalised['email'],
                ],
                source: $this->provider,
                projectId: $routing['project_id'],
                holderId: $routing['holder_id'],
                formId: $routing['form_id'],
            );

            match ($outcome['result']) {
                IncomingLeadService::DUPLICATE => $log->duplicate($this->provider, $this->leadgenId, $outcome['message'], $form),
                IncomingLeadService::REPEAT => $log->repeatEnquiry($this->provider, $this->leadgenId, $outcome['message'], $form),
                default => $log->created($this->provider, $this->leadgenId, $outcome['lead'], $form),
            };

            if ($outcome['result'] === 'created' && ! $routing['routed']) {
                $router->noteUnrouted($this->provider, $routing);
            }
        } catch (Throwable $e) {
            /*
             | Logged as a readable failure and then rethrown.
             |
             | The row is what the admin reads on the Integrations page — an
             | expired token has to be visible there, not only in a log file
             | nobody opens. The rethrow is what lets the queue retry it and,
             | after $tries, put it in failed_jobs where it can be replayed once
             | the token is fixed.
             */
            $log->failed($this->provider, $this->leadgenId, $e->getMessage());

            throw $e;
        }
    }
}
