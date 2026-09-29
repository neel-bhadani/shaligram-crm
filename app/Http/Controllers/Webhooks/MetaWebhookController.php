<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMetaLead;
use App\Models\Integration;
use App\Services\IntegrationLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta's two calls, and the only part of this application reachable without
 * signing in.
 *
 * Both routes live outside the `web` middleware group entirely — see
 * routes/webhooks.php — so there is no session, no cookie and no CSRF token
 * involved. Meta has no way to hold one, and a stateless endpoint is a smaller
 * thing to leave open than an exempted stateful one.
 *
 * What replaces the session as the trust boundary is the signature: every POST
 * carries an HMAC of its own body keyed with the app secret, and a body that
 * does not match it is refused before anything reads it.
 */
class MetaWebhookController extends Controller
{
    /**
     * The verification handshake.
     *
     * Meta calls this once when the webhook is saved in the app dashboard, and
     * again whenever the subscription is re-verified. It sends a token the
     * admin pasted in and a challenge; echoing the challenge back as plain text
     * — and ONLY when the token matches — is what proves the endpoint is under
     * the same person's control.
     *
     * Plain text, no JSON, no trailing newline: Meta compares the body exactly.
     */
    public function verify(Request $request, string $provider): Response
    {
        $integration = Integration::forProvider($provider);
        $expected = (string) $integration->setting('verify_token');

        /*
         | `hub_verify_token`, not `hub.verify_token`. Meta sends the dotted
         | name, and PHP rewrites a dot in a query-string key to an underscore
         | before Laravel ever sees it. Both spellings are read so that a
         | future PHP that stops doing that does not silently break the
         | handshake.
         */
        $presented = (string) ($request->query('hub_verify_token')
            ?? $request->query('hub.verify_token', ''));

        if ($expected === '' || ! hash_equals($expected, $presented)) {
            /*
             | Not logged to integration_events: this endpoint is public, so
             | anyone can make it fire, and a log an anonymous visitor can fill
             | is not an activity log any more. The application log is the right
             | place for it.
             */
            Log::warning("[integration:{$provider}] webhook verification refused", ['ip' => $request->ip()]);

            return response('Verification token mismatch.', 403)
                ->header('Content-Type', 'text/plain');
        }

        $challenge = (string) ($request->query('hub_challenge')
            ?? $request->query('hub.challenge', ''));

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * A lead notification.
     *
     * Answer 200 as fast as possible and do the work in a queued job. Meta
     * treats a slow response as a failure and redelivers, so processing inline
     * would turn one lead into several — the retry arriving while the first
     * delivery was still fetching from Graph. The 200 means "received", not
     * "imported"; the Integrations page is where the second question is
     * answered.
     */
    public function handle(Request $request, string $provider, IntegrationLogger $log): Response
    {
        $integration = Integration::forProvider($provider);
        $secret = (string) $integration->setting('app_secret');

        if ($secret === '' || ! $this->signatureIsValid($request, $secret)) {
            Log::warning("[integration:{$provider}] webhook signature rejected", ['ip' => $request->ip()]);

            return response('Invalid signature.', 403);
        }

        /*
         | Signed, so it is Meta — but not necessarily switched on. A payload
         | that arrives while the integration is inactive is recorded rather
         | than dropped silently, because "Meta is delivering and we are
         | ignoring it" is precisely the state an admin needs to be able to see.
         */
        foreach ($this->leadgens($request) as $leadgenId => $formId) {
            // a numeric id comes back out of an array key as an int
            $leadgenId = (string) $leadgenId;

            if (! $integration->isReady()) {
                $log->failed($provider, $leadgenId, 'Received while the integration was switched off or incomplete.');

                continue;
            }

            ProcessMetaLead::dispatch($provider, $leadgenId, formId: $formId);
        }

        return response('', 200);
    }

    /**
     * Is this body the one Meta signed?
     *
     * The HMAC is over the RAW body — the exact bytes on the wire, not the
     * re-encoded result of decoding them, which would differ in key order and
     * unicode escaping and never match.
     *
     * hash_equals(), not `===`: a normal string comparison returns as soon as
     * two bytes differ, and the time it takes leaks how much of a guess was
     * right. That is a real attack against a signature an attacker can retry
     * freely, and this endpoint is public.
     */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $header = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /**
     * Every leadgen_id in the payload, with the lead form it came from.
     *
     * Meta batches: one delivery can carry several entries, each with several
     * changes, and a page that also subscribes to other fields will send those
     * down the same pipe. Anything that is not a `leadgen` change with an id is
     * skipped rather than treated as a malformed lead.
     *
     * The form id is what LeadFormRouter files the lead by. Null when Meta
     * left it out, which sends the lead to the fallback project.
     *
     * @return array<string, ?string> leadgen_id => form_id
     */
    private function leadgens(Request $request): array
    {
        $ids = [];

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }

                $id = $change['value']['leadgen_id'] ?? null;

                if ($id !== null && $id !== '') {
                    $formId = $change['value']['form_id'] ?? null;

                    // keyed by id: the same id twice inside one delivery is one lead
                    $ids[(string) $id] = filled($formId) ? (string) $formId : null;
                }
            }
        }

        return $ids;
    }
}
