<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Meta said no, or did not answer. Carries Meta's own code and words.
 *
 * Three kinds of failure, because they want three different reactions:
 *
 *   final      the message cannot go and trying again changes nothing: the
 *              token expired (190), the number is not on WhatsApp (131026),
 *              the customer is outside the 24-hour window (131047). Failed
 *              once, with a sentence the admin can act on.
 *   retryable  Meta is busy or down: rate limits, throughput caps, 5xx, a
 *              timeout. Worth another go after a pause.
 *   anything   else is failed once, with Meta's raw error kept — an unknown
 *              code is better stored verbatim than paraphrased wrongly.
 */
class WhatsAppApiException extends RuntimeException
{
    /** Codes with a known, plain-English meaning. Never retried. */
    public const EXPLAINED = [
        190 => 'The WhatsApp access token has expired or been revoked. Paste a new one in the WhatsApp settings.',
        131026 => 'This number is not on WhatsApp, or cannot receive messages from businesses.',
        131047 => 'The customer has not messaged in the last 24 hours, so only an approved template can be sent.',
    ];

    /** Rate limits and Meta-side outages: pause and try again. */
    public const RETRYABLE = [1, 2, 4, 80007, 130429, 131000, 131016, 131048, 131056];

    public function __construct(
        string $message,
        public readonly ?int $metaCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $raw = null,
    ) {
        parent::__construct($message);
    }

    /** From a failed Graph response, keeping Meta's message, code and detail. */
    public static function fromResponse(Response $response): self
    {
        $code = $response->json('error.code');
        $text = (string) ($response->json('error.message') ?? '');
        $detail = (string) ($response->json('error.error_data.details') ?? '');

        if ($detail !== '' && ! str_contains($text, $detail)) {
            $text = trim("{$text} {$detail}");
        }

        return new self(
            $text !== '' ? $text : "Meta answered HTTP {$response->status()} with no error message.",
            is_numeric($code) ? (int) $code : null,
            $response->status(),
            $response->body(),
        );
    }

    /** Nothing came back at all: DNS, TLS, a timeout. */
    public static function unreachable(string $why): self
    {
        return new self("Could not reach Meta: {$why}");
    }

    public function isRetryable(): bool
    {
        if ($this->metaCode !== null && isset(self::EXPLAINED[$this->metaCode])) {
            return false;
        }

        return $this->httpStatus === null
            || $this->httpStatus >= 500
            || in_array($this->metaCode, self::RETRYABLE, true);
    }

    /**
     * What the admin reads: the plain-English sentence where there is one,
     * always followed by Meta's own words and code.
     */
    public function explain(): string
    {
        $meta = $this->metaCode !== null
            ? "Meta said (error {$this->metaCode}): {$this->getMessage()}"
            : $this->getMessage();

        $plain = self::EXPLAINED[$this->metaCode] ?? null;

        return $plain ? "{$plain} {$meta}" : $meta;
    }
}
