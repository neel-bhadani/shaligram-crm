<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The calls this CRM makes to Meta's WhatsApp Cloud API. Outbound only.
 *
 * Its own class so everything above it can be tested with Http::fake(), and
 * so the Graph version is in exactly one place (config, v26.0). Facebook Lead
 * Ads has its own version in config/integrations.php and is not touched by
 * this.
 *
 * Every method returns what Meta said or throws WhatsAppApiException carrying
 * Meta's code and message. Nothing here retries — that is the job's decision,
 * made from the code.
 */
class WhatsAppCloudClient
{
    /**
     * Send one template message.
     *
     * @param  list<string>  $params  {{1}}, {{2}}… in that order, already
     *                                decided when the message was queued
     * @return string the wamid Meta assigned
     *
     * @throws WhatsAppApiException
     */
    public function sendTemplate(
        string $token,
        string $phoneNumberId,
        string $to,
        string $templateName,
        string $language,
        array $params,
    ): string {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];

        if ($params !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    array_values($params),
                ),
            ]];
        }

        $response = $this->call(fn () => $this->request($token)->post($this->url("{$phoneNumberId}/messages"), [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]));

        $wamid = $response->json('messages.0.id');

        if (! $wamid) {
            throw new WhatsAppApiException('Meta accepted the request but returned no message id.', null, $response->status(), $response->body());
        }

        return (string) $wamid;
    }

    /**
     * The number a phone number id sends as.
     *
     * @return array{display_phone_number: ?string, verified_name: ?string, quality_rating: ?string}
     *
     * @throws WhatsAppApiException
     */
    public function phoneNumber(string $token, string $phoneNumberId): array
    {
        $response = $this->call(fn () => $this->request($token)->get($this->url($phoneNumberId), [
            'fields' => 'display_phone_number,verified_name,quality_rating',
        ]));

        return [
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
            'quality_rating' => $response->json('quality_rating'),
        ];
    }

    /**
     * The phone number ids on a WhatsApp Business Account.
     *
     * @return list<string>
     *
     * @throws WhatsAppApiException
     */
    public function phoneNumberIds(string $token, string $wabaId): array
    {
        $response = $this->call(fn () => $this->request($token)->get($this->url("{$wabaId}/phone_numbers"), [
            'fields' => 'id',
        ]));

        return array_map('strval', array_column($response->json('data') ?? [], 'id'));
    }

    /**
     * Every template on the account, following Meta's paging.
     *
     * @return list<array<string, mixed>>
     *
     * @throws WhatsAppApiException
     */
    public function templates(string $token, string $wabaId): array
    {
        $url = $this->url("{$wabaId}/message_templates");
        $query = ['fields' => 'id,name,status,category,language,components', 'limit' => 100];
        $all = [];

        // a ceiling, not an expectation: 50 pages is 5,000 templates
        for ($page = 0; $url && $page < 50; $page++) {
            $response = $this->call(fn () => $this->request($token)->get($url, $query));

            array_push($all, ...($response->json('data') ?? []));

            // `next` is a complete URL with the cursor and fields already on it
            $url = $response->json('paging.next');
            $query = [];
        }

        return $all;
    }

    /* ---------------- internals ---------------- */

    private function request(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('automation.whatsapp.api.timeout', 15));
    }

    private function url(string $path): string
    {
        $base = rtrim((string) config('automation.whatsapp.api.base'), '/');
        $version = config('automation.whatsapp.api.version');

        return "{$base}/{$version}/{$path}";
    }

    /**
     * @param  callable(): Response  $send
     *
     * @throws WhatsAppApiException
     */
    private function call(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::unreachable($e->getMessage());
        }

        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response);
        }

        return $response;
    }
}
