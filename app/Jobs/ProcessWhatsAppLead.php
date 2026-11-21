<?php

namespace App\Jobs;

use App\Models\Integration;
use App\Models\Lead;
use App\Services\IncomingLeadService;
use App\Services\IntegrationLogger;
use App\Services\MetaLeadNormaliser;
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
     * @param  array{name?: ?string, mobileNo?: ?string, updatedAt?: ?string, tags?: array, masterstatus?: ?string, teamMemberEmail?: ?string, teamMemberName?: ?string}  $data
     */
    public function __construct(
        public string $type,
        public array $data,
    ) {}

    public function handle(
        MetaLeadNormaliser $normaliser,
        IncomingLeadService $leads,
        IntegrationLogger $log,
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

            if ($this->type === 'update') {
                $existing = Lead::withTrashed()
                    ->where('mobile_number', $mobile)
                    ->where('project_id', (int) $integration->setting('default_project_id'))
                    ->first();

                /*
                 | An update for a number we already have changes nothing yet:
                 | the client has not decided what an 11za update should sync
                 | (status, tags, owner). It is only logged so it shows in
                 | Recent activity. An unknown number falls through to insert.
                 */
                if ($existing) {
                    $log->updateReceived(
                        self::PROVIDER,
                        $externalId,
                        $existing,
                        "11za sent an update for lead #{$existing->id}; nothing changed.",
                    );

                    return;
                }
            }

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
            );

            match ($outcome['result']) {
                IncomingLeadService::DUPLICATE => $log->duplicate(self::PROVIDER, $externalId, $outcome['message']),
                IncomingLeadService::REPEAT => $log->repeatEnquiry(self::PROVIDER, $externalId, $outcome['message']),
                default => $log->created(self::PROVIDER, $externalId, $outcome['lead']),
            };
        } catch (Throwable $e) {
            $log->failed(self::PROVIDER, $externalId, $e->getMessage());

            $this->fail($e);
        }
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
