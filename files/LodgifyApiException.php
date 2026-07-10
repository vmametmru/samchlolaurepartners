<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Thrown when the Lodgify API returns an HTTP error response. Carries the
 * upstream HTTP status code so callers can distinguish a genuine "resource
 * does not exist" (404) from transient failures (timeouts, 5xx, rate
 * limiting, auth errors, ...) instead of treating every failure the same way.
 */
final class LodgifyApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
