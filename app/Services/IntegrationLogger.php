<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one place an integration outcome is recorded.
 *
 * Two destinations, deliberately: a row in `integration_events` for the admin,
 * and a line in the application log for whoever is debugging. The first is the
 * feature — an integration that quietly stops delivering is invisible without
 * it — and the second is what survives a database that has been reseeded.
 */
class IntegrationLogger
{
    /**
     * `$form` is what LeadFormRouter decided. `routed => false` keeps the
     * result `created` — the lead exists — but says in the message where it
     * went and why, and the activity log flags the row, so a form with no
     * project is visible on every lead it sends rather than only in an alert.
     *
     * @param  array{form_id?: ?string, form_name?: ?string, routed?: ?bool}  $form
     */
    public function created(string $provider, string $externalId, Lead $lead, array $form = []): IntegrationEvent
    {
        // the only outcome that means a lead actually arrived, so it is the
        // only one that moves the card's "Last lead received"
        Integration::where('provider', $provider)->update(['last_received_at' => now()]);

        $message = "Lead #{$lead->id} created.";

        if (($form['routed'] ?? null) === false) {
            $message .= ($form['form_id'] ?? null) === null
                ? ' It came with no lead form, so it was filed under the fallback project.'
                : " Form \"{$form['form_name']}\" has no active project, so it was filed under the fallback project.";
        }

        return $this->write($provider, 'created', $externalId, $message, $lead->id, $form);
    }

    /**
     * The same leadgen_id twice: a Meta retry, or a redelivery. Nothing to do.
     *
     * @param  array{form_id?: ?string, form_name?: ?string}  $form
     */
    public function duplicate(string $provider, string $externalId, string $message, array $form = []): IntegrationEvent
    {
        return $this->write($provider, 'duplicate', $externalId, $message, form: $form);
    }

    /**
     * A real second enquiry from a number already on this project.
     *
     * Not an error and not a duplicate delivery — somebody has filled in a
     * second form. The lead already exists and already has an owner and a
     * pending follow-up, so making another would split one conversation across
     * two rows. Worth seeing, which is why it is its own result.
     *
     * @param  array{form_id?: ?string, form_name?: ?string}  $form
     */
    public function repeatEnquiry(string $provider, string $externalId, string $message, array $form = []): IntegrationEvent
    {
        return $this->write($provider, 'repeat_enquiry', $externalId, $message, form: $form);
    }

    public function failed(string $provider, ?string $externalId, string $message): IntegrationEvent
    {
        return $this->write($provider, 'failed', $externalId, $message);
    }

    /**
     * @param  array{form_id?: ?string, form_name?: ?string, routed?: ?bool}  $form
     */
    private function write(string $provider, string $result, ?string $externalId, string $message, ?int $leadId = null, array $form = []): IntegrationEvent
    {
        Log::log(
            $result === 'failed' ? 'error' : 'info',
            "[integration:{$provider}] {$result}",
            ['external_id' => $externalId, 'lead_id' => $leadId, 'message' => $message],
        );

        return IntegrationEvent::create([
            'provider' => $provider,
            'result' => $result,
            'external_id' => $externalId,
            'lead_id' => $leadId,
            'form_id' => $form['form_id'] ?? null,
            'form_name' => $form['form_name'] ?? null,
            // only a created lead was routed anywhere; null everywhere else
            'routed' => $result === 'created' ? ($form['routed'] ?? null) : null,
            // one line in a table cell; the full text is in the application log
            'message' => Str::limit($message, 500),
        ]);
    }
}
