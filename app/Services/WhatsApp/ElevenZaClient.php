<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The two calls this CRM makes to 11za: send a template, and list the
 * account's templates for the Messages tab. Outbound only.
 *
 * Its own class so everything above it can be tested with Http::fake().
 * Nothing here retries — that is the job's decision, made from the exception.
 *
 * THE TOKEN. 11za takes the auth token in the JSON body, not a header, so the
 * body is a secret in its own right. It is built here, posted here, and never
 * leaves this class:
 *
 *   - nothing that comes back (a response body, an exception message) is
 *     handed upward until redact() has removed the token from it — 11za, or a
 *     proxy in front of it, may echo the request back in an error;
 *   - every Throwable from the HTTP layer is caught and replaced with a
 *     WhatsAppApiException carrying only the redacted message and no
 *     `previous`. A Guzzle exception keeps the request object, body included,
 *     and would otherwise end up in failed_jobs or the log;
 *   - the token parameter is #[SensitiveParameter], so a stack trace taken
 *     anywhere below this shows it as a placeholder, not the value.
 *
 * THE RESPONSE. 11za's success and error shapes are not documented. The raw
 * response (redacted) is returned on success and carried on failure so it can
 * be stored and read; messageIdIn() is a best guess at where the id lives,
 * to be tightened once the first real response has been seen.
 *
 * Because it is a guess, a 2xx without an id it recognises is NOT a failure:
 * it comes back with `id` null and the caller records it as sent but
 * unconfirmed. Calling it failed would invite a resend of a message that may
 * well have been delivered.
 */
class ElevenZaClient
{
    /**
     * Key names, lowercased with underscores removed, that are taken to hold
     * the message id wherever they appear in the response.
     */
    private const MESSAGE_ID_KEYS = ['messageid', 'msgid', 'wamid'];

    /** Keys, normalised the same way, read from each entry of the template list. */
    private const TEMPLATE_NAME_KEYS = ['templatename', 'elementname', 'name'];

    private const TEMPLATE_LANGUAGE_KEYS = ['language', 'languagecode', 'lang'];

    private const TEMPLATE_BODY_KEYS = ['body', 'bodytext', 'templatebody', 'text', 'content'];

    private const TEMPLATE_COUNT_KEYS = ['dynamicvaluecount', 'dynamiccount', 'variablecount', 'variables', 'count'];

    /**
     * Send one template message.
     *
     * @param  list<string>  $data  {{1}}, {{2}}… in that order, already decided
     *                              when the message was queued
     * @return array{id: ?string, status: int, raw: string} raw is redacted;
     *                                                      id null when none was recognised
     *
     * @throws WhatsAppApiException
     */
    public function sendTemplate(
        #[SensitiveParameter] string $authToken,
        string $baseUrl,
        string $originWebsite,
        string $to,
        string $name,
        string $templateName,
        string $language,
        array $data,
    ): array {
        $url = rtrim($baseUrl, '/').config('automation.whatsapp.api.send_path');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('automation.whatsapp.api.timeout', 15))
                ->post($url, [
                    'authToken' => $authToken,
                    'name' => $name,
                    'sendto' => $to,
                    'originWebsite' => $originWebsite,
                    'templateName' => $templateName,
                    'language' => $language,
                    'data' => array_values($data),
                ]);
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::unreachable($this->redact($e->getMessage(), $authToken));
        } catch (Throwable $e) {
            throw WhatsAppApiException::unreachable($this->redact(class_basename($e).': '.$e->getMessage(), $authToken));
        }

        $raw = $this->redact($response->body(), $authToken);

        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response->status(), $raw);
        }

        return ['id' => $this->messageIdIn($response), 'status' => $response->status(), 'raw' => $raw];
    }

    /**
     * The templates in the 11za account, for the Messages tab's dropdown.
     *
     * 11za documents the request and not the answer, so the answer is read
     * defensively: the first list in it whose entries carry a name is the
     * template list, and from each entry the name, language, wording and
     * variable count are taken from whichever key looks like one. Anything
     * missing comes back null rather than guessed. The raw response (token
     * removed) always comes back too, so the shape can be seen and this
     * tightened.
     *
     * @return array{templates: list<array{name: string, language: ?string, body: ?string, variables: ?int}>, raw: string}
     *
     * @throws WhatsAppApiException
     */
    public function listTemplates(#[SensitiveParameter] string $authToken, string $baseUrl): array
    {
        $url = rtrim($baseUrl, '/').config('automation.whatsapp.api.list_path');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('automation.whatsapp.api.timeout', 15))
                ->post($url, [
                    'authToken' => $authToken,
                    'limit' => (int) config('automation.whatsapp.api.list_limit', 100),
                    'page' => 1,
                    'search' => '',
                ]);
        } catch (ConnectionException $e) {
            throw WhatsAppApiException::unreachable($this->redact($e->getMessage(), $authToken));
        } catch (Throwable $e) {
            throw WhatsAppApiException::unreachable($this->redact(class_basename($e).': '.$e->getMessage(), $authToken));
        }

        $raw = $this->redact($response->body(), $authToken);

        if ($response->failed()) {
            throw WhatsAppApiException::fromResponse($response->status(), $raw);
        }

        $json = $response->json();

        return ['templates' => is_array($json) ? $this->templatesIn($json) : [], 'raw' => $raw];
    }

    /**
     * Remove the token from anything about to be stored or shown.
     *
     * An echo does not come back as typed. JSON escapes a slash as "\/", and
     * JSON inside JSON escapes it again as "\\\/", so the token is matched with
     * any run of backslashes before each punctuation character. URL-encoded
     * copies go too. Then, whatever the token's value, anything sitting under
     * an `authToken` key — a token rotated since, or encoded some other way —
     * is blanked as well.
     */
    public function redact(string $text, #[SensitiveParameter] string $authToken): string
    {
        if ($authToken !== '') {
            $pattern = implode('', array_map(
                fn (string $char) => ctype_alnum($char) ? $char : '(?:\\\\)*'.preg_quote($char, '/'),
                mb_str_split($authToken),
            ));

            $text = (string) preg_replace("/{$pattern}/", '[redacted]', $text);
            $text = str_replace(array_unique([rawurlencode($authToken), urlencode($authToken)]), '[redacted]', $text);
        }

        return (string) preg_replace(
            '/(auth_?token(?:\\\\*["\'])?\s*[:=]\s*(?:\\\\*["\'])?)(?!\[redacted\])[^"\'\\\\&\s,}]+/i',
            '$1[redacted]',
            $text,
        );
    }

    /**
     * The first list of named entries anywhere in the response, read as
     * templates. One entry per name and language.
     *
     * @return list<array{name: string, language: ?string, body: ?string, variables: ?int}>
     */
    private function templatesIn(array $json): array
    {
        if (array_is_list($json) && $json !== [] && collect($json)->contains(fn ($item) => is_array($item) && $this->field($item, self::TEMPLATE_NAME_KEYS) !== null)) {
            return collect($json)
                ->filter(fn ($item) => is_array($item) && is_string($this->field($item, self::TEMPLATE_NAME_KEYS)))
                ->map(fn (array $item) => $this->templateFrom($item))
                ->unique(fn (array $t) => $t['name'].'|'.$t['language'])
                ->values()
                ->all();
        }

        foreach ($json as $value) {
            if (is_array($value) && ($found = $this->templatesIn($value)) !== []) {
                return $found;
            }
        }

        return [];
    }

    /** @return array{name: string, language: ?string, body: ?string, variables: ?int} */
    private function templateFrom(array $item): array
    {
        $language = $this->field($item, self::TEMPLATE_LANGUAGE_KEYS);

        if (is_array($language)) {
            $language = $this->field($language, ['code', 'language']);
        }

        $body = $this->field($item, self::TEMPLATE_BODY_KEYS);

        // WhatsApp's own shape: components[{type: BODY, text}]
        if (! is_string($body) && is_array($components = $this->field($item, ['components']))) {
            $body = collect($components)
                ->first(fn ($c) => is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'BODY')['text'] ?? null;
        }

        $body = is_string($body) && trim($body) !== '' ? $body : null;

        $variables = $body !== null && preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m)
            ? max(array_map('intval', $m[1]))
            : ($body !== null ? 0 : null);

        if ($variables === null && is_numeric($count = $this->field($item, self::TEMPLATE_COUNT_KEYS))) {
            $variables = (int) $count;
        }

        return [
            'name' => trim((string) $this->field($item, self::TEMPLATE_NAME_KEYS)),
            'language' => is_string($language) && $language !== '' ? $language : null,
            'body' => $body,
            'variables' => $variables,
        ];
    }

    /**
     * The value under the first key that, lowercased with underscores and
     * spaces removed, is one of these. One level deep only.
     *
     * @param  list<string>  $keys
     */
    private function field(array $item, array $keys): mixed
    {
        foreach ($keys as $wanted) {
            foreach ($item as $key => $value) {
                if (str_replace(['_', ' '], '', strtolower((string) $key)) === $wanted && $value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** The first non-empty scalar under a message-id-looking key, anywhere in the response. */
    private function messageIdIn(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        $found = null;

        array_walk_recursive($json, function (mixed $value, int|string $key) use (&$found) {
            $normalised = str_replace('_', '', strtolower((string) $key));

            if ($found === null && in_array($normalised, self::MESSAGE_ID_KEYS, true)
                && is_scalar($value) && (string) $value !== '') {
                $found = (string) $value;
            }
        });

        return $found;
    }
}
