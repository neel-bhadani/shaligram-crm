<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The two calls this CRM makes to 11za: send a template, and list the
 * account's templates for the Tags tab. Outbound only.
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
     * One page of the templates in the 11za account, for the Tags tab.
     *
     * The answer of getTemplatesAll, as seen from production (October 2026),
     * trimmed to what is read:
     *
     *   {"IsSuccess": true, "Data": {
     *     "docs": [{"name": "vanam_won", "category": "MARKETING",
     *               "localizations": [{"language": "en", "status": "APPROVED",
     *                                  "components": [{"type": "BODY", "text": "…"},
     *                                                 {"type": "FOOTER", "text": "…"}]}],
     *               "dynamicValues": [{"language": "en", "bodyDynamic": 0}],
     *               "variables": [{"localization": "en", "headerDynamics": [],
     *                              "bodyDynamics": [], "carouselDynamics": []}]}],
     *     "totalDocs": 1, "limit": 100, "page": 1, "hasNextPage": false}}
     *
     * Each localization is its own entry, with its own approval status and
     * wording — the same template in English and Hindi is two things a tag
     * can be sent as, and either can be pending while the other is approved.
     *
     * A field that is missing comes back null rather than throwing, so one odd
     * template cannot sink the list. `docs` itself missing means the answer is
     * not the list (null `templates`); an empty `docs` is a real, empty list.
     *
     * @return array{templates: ?list<array{name: string, language: ?string, status: ?string, category: ?string,
     *                                       body: ?string, variables: ?int, extra_variables: ?int}>,
     *               total: ?int, limit: ?int, has_next: bool, raw: string}
     *
     * @throws WhatsAppApiException
     */
    public function listTemplates(#[SensitiveParameter] string $authToken, string $baseUrl, int $limit = 100, int $page = 1): array
    {
        $url = rtrim($baseUrl, '/').config('automation.whatsapp.api.list_path');

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout((int) config('automation.whatsapp.api.timeout', 15))
                ->post($url, [
                    'authToken' => $authToken,
                    'limit' => $limit,
                    'page' => $page,
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
        $data = is_array($json) && ($json['IsSuccess'] ?? true) !== false ? ($json['Data'] ?? null) : null;
        $docs = is_array($data) && is_array($data['docs'] ?? null) && array_is_list($data['docs']) ? $data['docs'] : null;

        return [
            'templates' => $docs === null ? null : collect($docs)
                ->filter(fn ($doc) => is_array($doc))
                ->flatMap(fn (array $doc) => $this->entriesFor($doc))
                ->unique(fn (array $t) => $t['name'].'|'.$t['language'])
                ->values()
                ->all(),
            'total' => is_int($data['totalDocs'] ?? null) ? $data['totalDocs'] : null,
            'limit' => is_int($data['limit'] ?? null) ? $data['limit'] : null,
            'has_next' => ($data['hasNextPage'] ?? false) === true,
            'raw' => $raw,
        ];
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
     * One entry per localization of a template, or one with no language when
     * it lists none.
     *
     * `variables` is the body's count — what the `data` array of a send
     * fills. Read from `dynamicValues[].bodyDynamic` for that language; failing
     * that, the length of its `variables[].bodyDynamics`; failing that, the
     * highest {{n}} in the wording. `extra_variables` is the header and
     * carousel ones, which a send from this CRM does not fill.
     *
     * `body` is the BODY component's text — 11za's own wording, with its own
     * {{1}}, {{2}}. Kept in the list and copied onto the tags set up as it.
     *
     * @return list<array{name: string, language: ?string, status: ?string, category: ?string,
     *                    body: ?string, variables: ?int, extra_variables: ?int}>
     */
    private function entriesFor(array $doc): array
    {
        $name = is_string($doc['name'] ?? null) ? trim($doc['name']) : '';

        if ($name === '') {
            return [];
        }

        $category = is_string($doc['category'] ?? null) && trim($doc['category']) !== '' ? strtoupper(trim($doc['category'])) : null;

        $byLanguage = fn (string $key, string $languageKey) => collect(is_array($doc[$key] ?? null) ? $doc[$key] : [])
            ->filter(fn ($v) => is_array($v) && is_string($v[$languageKey] ?? null))
            ->keyBy(fn (array $v) => trim($v[$languageKey]));

        $dynamicValues = $byLanguage('dynamicValues', 'language');
        $dynamics = $byLanguage('variables', 'localization');

        $localizations = collect(is_array($doc['localizations'] ?? null) ? $doc['localizations'] : [])
            ->filter(fn ($l) => is_array($l) && is_string($l['language'] ?? null) && trim($l['language']) !== '');

        if ($localizations->isEmpty()) {
            return [['name' => $name, 'language' => null, 'status' => null, 'category' => $category,
                'body' => null, 'variables' => null, 'extra_variables' => null]];
        }

        return $localizations->map(function (array $localization) use ($name, $category, $dynamicValues, $dynamics) {
            $language = trim($localization['language']);
            $these = $dynamics->get($language);
            $body = $this->bodyText($localization['components'] ?? null);

            $count = $dynamicValues->get($language)['bodyDynamic'] ?? $localization['bodyDynamic'] ?? null;

            $variables = match (true) {
                is_int($count) && $count >= 0 => $count,
                is_array($these['bodyDynamics'] ?? null) => count($these['bodyDynamics']),
                $body !== null => preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $m) ? max(array_map('intval', $m[1])) : 0,
                default => null,
            };

            $extra = is_array($these)
                ? (is_array($these['headerDynamics'] ?? null) ? count($these['headerDynamics']) : 0)
                    + (is_array($these['carouselDynamics'] ?? null) ? count($these['carouselDynamics']) : 0)
                : null;

            $status = is_string($localization['status'] ?? null) && trim($localization['status']) !== ''
                ? strtoupper(trim($localization['status']))
                : null;

            return ['name' => $name, 'language' => $language, 'status' => $status, 'category' => $category,
                'body' => $body, 'variables' => $variables, 'extra_variables' => $extra];
        })->values()->all();
    }

    /** The text of the BODY component, or null. */
    private function bodyText(mixed $components): ?string
    {
        if (! is_array($components)) {
            return null;
        }

        $text = collect($components)
            ->first(fn ($c) => is_array($c) && strtoupper((string) ($c['type'] ?? '')) === 'BODY')['text'] ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
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
