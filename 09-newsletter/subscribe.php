<?php
/**
 * Question 9: a newsletter signup that survives timeouts, rate limits and
 * invalid addresses without losing or duplicating a subscriber.
 *
 * Collaborators (duck-typed; in-memory versions live in tests/run-checks.php):
 *
 *   $store   find($key): ?array, upsertPending($key, $email),
 *            markConfirmed($key, $providerId), markRejected($key),
 *            recordAttempt($key, $outcome)
 *   $client  createSubscriber($email, $idempotencyKey) returns
 *            ['status' => int, 'id' => ?string, 'retry_after' => ?int]
 *            201 created, 200 replayed key, 429 rate limited,
 *            400/422 invalid, 5xx unavailable, 0 timeout
 *   $log     fn (string $event, array $context): void
 *
 * Returns ['status' => 'confirmed'|'pending_retry'|'rejected', 'key' => string,
 *          'reason' => ?string, 'retry_after' => ?int]. A pending_retry is
 * picked up by a worker after retry_after seconds; this never sleeps inline.
 */

declare(strict_types=1);

function subscribe(string $email, string $scope, object $store, object $client, callable $log): array
{
    $email = strtolower(trim($email));

    // Deterministic, not per attempt: a retry presents the same key, so the
    // provider replays the original record instead of creating a second one.
    $key = hash('sha256', $scope . "\0" . $email);

    $result = static fn (string $status, ?string $reason = null, ?int $retryAfter = null): array =>
        ['status' => $status, 'key' => $key, 'reason' => $reason, 'retry_after' => $retryAfter];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $log('signup.rejected', ['key' => $key, 'reason' => 'invalid_email_local']);

        return $result('rejected', 'invalid_email');
    }

    $state = $store->find($key)['status'] ?? null;

    if ($state === 'confirmed') {
        $log('signup.noop_already_confirmed', ['key' => $key]);

        return $result('confirmed');
    }

    if ($state === 'rejected') {
        return $result('rejected', 'previously_rejected');
    }

    // Persist intent before any I/O, so a crash or timeout can't lose it.
    $store->upsertPending($key, $email);

    $response = $client->createSubscriber($email, $key);
    $status = $response['status'];

    if ($status === 200 || $status === 201) {
        $store->markConfirmed($key, $response['id']);
        $store->recordAttempt($key, $status === 201 ? 'created' : 'replayed');
        $log('signup.confirmed', ['key' => $key, 'created' => $status === 201, 'provider' => $response['id']]);

        return $result('confirmed');
    }

    if ($status === 400 || $status === 422) {
        // Terminal: the same payload will be rejected every time.
        $store->markRejected($key);
        $store->recordAttempt($key, 'rejected');
        $log('signup.rejected', ['key' => $key, 'reason' => 'invalid_email_remote']);

        return $result('rejected', 'invalid_email');
    }

    if ($status === 429) {
        $store->recordAttempt($key, 'rate_limited');
        $log('signup.rate_limited', ['key' => $key, 'retry_after' => $response['retry_after']]);

        return $result('pending_retry', 'rate_limited', $response['retry_after']);
    }

    // Timeout or 5xx: the provider may or may not have stored it. The row
    // stays pending and the retry reuses the key, which makes that harmless.
    $store->recordAttempt($key, 'retryable_failure');
    $attempts = $store->find($key)['attempts'];
    $log('signup.retry_scheduled', [
        'key'      => $key,
        'reason'   => $status === 0 ? 'timeout' : 'unavailable',
        'attempts' => $attempts,
    ]);

    // Exponential backoff capped at five minutes. Jitter is derived from the
    // key so retries after an outage spread out but stay reproducible.
    $base = min(300, 2 ** min($attempts, 8));
    $jitter = hexdec(substr($key, 0, 4)) % max(1, intdiv($base, 2));

    return $result('pending_retry', 'transient', $base + $jitter);
}
