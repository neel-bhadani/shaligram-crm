<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The two calls this CRM makes to Meta's WhatsApp Cloud API, and nothing else.
 *
 *   POST {phone-number-id}/messages           send one message
 *   GET  {waba-id}/message_templates          list the account's templates
 *
 * Authenticated with a System User token, not a Page token. Holds no state and
 * reads no settings: WhatsAppSender decides whether a call should be made at
 * all, and passes in the ids and the token it decided with.
 */
class WhatsAppCloudClient
{
    /**
     * Send one message payload.
     *
     * Never throws. Every outcome — sent, refused, unreachable — comes back as
     * the same shape, because every caller needs to record it on the message
     * row either way. `retryable` is true for the failures that might go
     * through on a second try (no answer, 5xx, rate limit) and false for the
     * ones that will not (bad number, template not approved, expired token),
     * so the queue does not spend three attempts on a refusal.
     *
     * @param  array<string, mixed>  $payload  everything after messaging_product
     * @return array{ok: bool, message_id: ?string, error: ?string, reason: ?string, retryable: bool}
     */
    public function send(string $phoneNumberId, string $token, array $payload): array
    {
        try {
            $response = $this->request($token)->post(
                $this->url($phoneNumberId.'/messages'),
                ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual'] + $payload,
            );
        } catch (ConnectionException $e) {
            return [
                'ok' => false,
                'message_id' => null,
                'error' => $e->getMessage(),
                'reason' => 'WhatsApp did not answer: '.$e->getMessage(),
                'retryable' => true,
            ];
        }

        if ($response->successful() && $response->json('messages.0.id')) {
            return [
                'ok' => true,
                'message_id' => (string) $response->json('messages.0.id'),
                'error' => null,
                'reason' => null,
                'retryable' => false,
            ];
        }

        return [
            'ok' => false,
            'message_id' => null,
            // the whole body as Meta sent it — code, subcode, error_data and
            // fbtrace_id are what Meta support asks for
            'error' => $response->body(),
            'reason' => $this->reason($response, 'WhatsApp refused the message'),
            'retryable' => $response->serverError() || $response->status() === 429,
        ];
    }

    /**
     * Every template on the WhatsApp Business Account, following Meta's paging.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException with Meta's own explanation when it refuses
     */
    public function templates(string $wabaId, string $token): array
    {
        $templates = [];
        $url = $this->url($wabaId.'/message_templates');
        $query = ['fields' => 'name,status,category,language,components', 'limit' => 100];

        // a guard, not a limit anyone should reach: 50 pages is 5,000 templates
        for ($page = 0; $url && $page < 50; $page++) {
            $response = $this->request($token)->get($url, $query);

            if ($response->failed()) {
                throw new RuntimeException($this->reason($response, 'Meta refused to list the templates'));
            }

            array_push($templates, ...(array) $response->json('data', []));

            // `next` already carries the query and the cursor
            $url = $response->json('paging.next');
            $query = [];
        }

        return $templates;
    }

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('automation.whatsapp.api.timeout', 15));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('automation.whatsapp.api.base'), '/')
            .'/'.config('automation.whatsapp.api.version')
            .'/'.$path;
    }

    /** Meta's explanation in one line, for a person to read. */
    private function reason(Response $response, string $prefix): string
    {
        $message = $response->json('error.error_data.details')
            ?? $response->json('error.message')
            ?? $response->body();

        $code = $response->json('error.code');

        return $prefix.': '.$message.($code ? " (code {$code})" : '');
    }
}
