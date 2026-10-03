<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * The one call this CRM makes to 11za: send a template. Outbound only.
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
