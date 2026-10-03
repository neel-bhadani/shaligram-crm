<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * 11za said no, or did not answer.
 *
 * 11za's error shape is not documented, so nothing here reads a code out of
 * the response. What is kept is the HTTP status and the raw body, with the
 * auth token already removed by ElevenZaClient — the body is shown to the
 * admin verbatim, because an unknown error is better stored as it came than
 * paraphrased wrongly.
 *
 * Two reactions:
 *
 *   retryable  nothing came back (timeout, DNS, TLS), or 11za is busy or
 *              down: 408, 429, 5xx. Worth another go after a pause.
 *   final      any other 4xx. Trying again changes nothing — a wrong
 *              template name stays wrong.
 *
 * A 2xx is never one of these, with or without a message id: see
 * ElevenZaClient::sendTemplate().
 */
class WhatsAppApiException extends RuntimeException
{
    /** How much of 11za's response goes into the sentence the admin reads. */
    private const EXCERPT = 300;

    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $raw = null,
        private readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    /** A non-2xx answer. $raw must already be redacted. */
    public static function fromResponse(int $status, string $raw): self
    {
        return new self(
            "11za answered HTTP {$status}.",
            $status,
            $raw,
            $status === 408 || $status === 429 || $status >= 500,
        );
    }

    /** Nothing came back at all. $why must already be redacted. */
    public static function unreachable(string $why): self
    {
        return new self("Could not reach 11za: {$why}", retryable: true);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /** What the admin reads: our sentence, then the start of 11za's own words. */
    public function explain(): string
    {
        if ($this->raw === null || trim($this->raw) === '') {
            return $this->getMessage();
        }

        $excerpt = mb_strlen($this->raw) > self::EXCERPT
            ? mb_substr($this->raw, 0, self::EXCERPT).'…'
            : $this->raw;

        return "{$this->getMessage()} 11za said: {$excerpt}";
    }
}
