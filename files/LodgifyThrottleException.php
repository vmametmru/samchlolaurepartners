<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Thrown when a Lodgify call is refused *locally*, before hitting the API:
 * either the per-minute call budget (LODGIFY_MAX_CALLS_PER_MINUTE) is
 * already used up, or Lodgify answered a previous call with HTTP 429 and we
 * are still inside the resulting cooldown window.
 *
 * Callers should treat it like any other transient failure; LodgifyClient
 * itself catches it in remember() and serves the last known (stale) cached
 * value whenever one exists, so a burst of visitors degrades to slightly
 * older availability/prices instead of taking the whole site down.
 */
final class LodgifyThrottleException extends RuntimeException
{
}
