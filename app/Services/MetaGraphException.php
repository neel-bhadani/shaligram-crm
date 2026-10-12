<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Graph said no, or did not answer.
 *
 * Two reactions:
 *
 *   retryable  nothing came back (timeout, DNS, TLS), Meta is down (5xx), or
 *              Meta is rate limiting — which Graph reports as a 429 or as a
 *              400/403 carrying a throttling code. Worth another go later.
 *   final      everything else. "Object with ID … does not exist" is a 400 and
 *              stays one: Meta's test leads can be fetched exactly once and
 *              are then deleted, so a retry is only another failure row for a
 *              lead that will never come back.
 */
class MetaGraphException extends RuntimeException
{
    /**
     * Graph error codes that mean "slow down", whatever HTTP status they arrive on.
     *
     * 4 app, 17 user, 32 page, 613 call-rate limits; 1 and 2 are Meta's own
     * "unknown error" and "service temporarily unavailable".
     */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 32, 613];

    public function __construct(string $message, private readonly bool $retryable = false)
    {
        parent::__construct($message);
    }

    /**
     * A non-2xx answer, with Meta's own message — "Error validating access
     * token: Session has expired" is the difference between an admin who knows
     * to paste a new token and an admin who files a bug.
     */
    public static function fromResponse(string $what, Response $response): self
    {
        $error = $response->json('error.message') ?? $response->body();

        return new self(
            "Graph API refused the {$what} ({$response->status()}): {$error}",
            self::isTransient($response),
        );
    }

    /** Nothing came back at all. */
    public static function unreachable(string $what, string $why): self
    {
        return new self("Graph API did not answer the {$what}: {$why}", retryable: true);
    }

    public static function isTransient(Response $response): bool
    {
        $status = $response->status();

        if ($status === 429 || $status >= 500 || $response->json('error.is_transient') === true) {
            return true;
        }

        return in_array((int) $response->json('error.code'), self::TRANSIENT_CODES, true);
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
