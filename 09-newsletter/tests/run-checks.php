<?php
/**
 * Checks for question 9, driven by an in-memory mock of the provider.
 *
 * The mock models the one behaviour that matters: it keeps a table keyed by
 * idempotency key, so a replayed key returns the existing record with
 * created=false rather than inserting a second row. That is what lets us test
 * "retry after the service has already accepted the request" honestly: the
 * provider really did accept it, and the client really didn't hear back.
 *
 * Run: php 09-newsletter/tests/run-checks.php
 */

declare(strict_types=1);

require __DIR__ . '/../subscribe.php';

// ------------------------------------------------------------------ fakes --

final class InMemoryStore
{
    /** @var array<string, array{key: string, email: string, status: string, attempts: int, provider_id: ?string}> */
    public array $rows = [];

    /** @var array<int, array{key: string, outcome: string}> */
    public array $attempts = [];

    public function find(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    public function upsertPending(string $key, string $email): void
    {
        if (isset($this->rows[$key])) {
            return; // Already present; never overwrite a confirmed row.
        }

        $this->rows[$key] = [
            'key' => $key, 'email' => $email,
            'status' => 'pending', 'attempts' => 0, 'provider_id' => null,
        ];
    }

    public function markConfirmed(string $key, ?string $providerId): void
    {
        $this->rows[$key]['status'] = 'confirmed';
        $this->rows[$key]['provider_id'] = $providerId;
    }

    public function markRejected(string $key): void
    {
        $this->rows[$key]['status'] = 'rejected';
    }

    public function recordAttempt(string $key, string $outcome): void
    {
        $this->rows[$key]['attempts'] = ($this->rows[$key]['attempts'] ?? 0) + 1;
        $this->attempts[] = ['key' => $key, 'outcome' => $outcome];
    }

    public function countConfirmedFor(string $email): int
    {
        return count(array_filter(
            $this->rows,
            static fn (array $r): bool => $r['email'] === $email && $r['status'] === 'confirmed'
        ));
    }
}

/**
 * Provider mock. `$script` is a queue of behaviours consumed one per call:
 *   'ok' | 'timeout' | 'rate_limit' | 'invalid' | 'unavailable'
 * A 'timeout' still writes the subscriber on the provider side first, then
 * reports status 0: the scenario the brief describes.
 */
final class MockNewsletterClient
{
    /** @var array<string, string> idempotency key => provider id */
    public array $provider = [];

    public int $calls = 0;

    /** @var array<int, string> */
    public array $seenKeys = [];

    /** @param array<int, string> $script */
    public function __construct(private array $script = [])
    {
    }

    public function createSubscriber(string $email, string $idempotencyKey): array
    {
        $this->calls++;
        $this->seenKeys[] = $idempotencyKey;
        $behaviour = array_shift($this->script) ?? 'ok';

        if ($behaviour === 'invalid') {
            return ['status' => 422, 'id' => null, 'retry_after' => null];
        }

        if ($behaviour === 'rate_limit') {
            return ['status' => 429, 'id' => null, 'retry_after' => 30];
        }

        if ($behaviour === 'unavailable') {
            return ['status' => 503, 'id' => null, 'retry_after' => null];
        }

        // Both 'ok' and 'timeout' reach the provider and persist the record.
        $created = !isset($this->provider[$idempotencyKey]);

        if ($created) {
            $this->provider[$idempotencyKey] = 'sub_' . substr($idempotencyKey, 0, 8);
        }

        if ($behaviour === 'timeout') {
            // Written on their side; the response never reaches us.
            return ['status' => 0, 'id' => null, 'retry_after' => null];
        }

        return ['status' => $created ? 201 : 200, 'id' => $this->provider[$idempotencyKey], 'retry_after' => null];
    }

    public function subscriberCount(): int
    {
        return count($this->provider);
    }
}

// ------------------------------------------------------------- harness ----

$checks = 0;
$failures = 0;
$logLines = [];

$log = function (string $event, array $context) use (&$logLines): void {
    $logLines[] = ['event' => $event, 'context' => $context];
};

function check(string $label, bool $passed, string $detail = ''): void
{
    global $checks, $failures;
    $checks++;

    if ($passed) {
        echo "  PASS  {$label}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$label}" . ($detail !== '' ? ": {$detail}" : '') . "\n";
}

function section(string $name): void
{
    echo "\n=== {$name} ===\n";
}

$EMAIL = 'Ada@Example.COM';
$NORMALISED = 'ada@example.com';

// ---------------------------------------------------------------- happy ---

section('happy path');
{
    $store = new InMemoryStore();
    $client = new MockNewsletterClient(['ok']);

    $result = subscribe($EMAIL, 'weekly', $store, $client, $log);

    check('returns confirmed', $result['status'] === 'confirmed');
    check('one provider record', $client->subscriberCount() === 1);
    check('one local record', count($store->rows) === 1);
    check('normalises the address before storing',
        $store->rows[$result['key']]['email'] === $NORMALISED);
    check('stores the provider id',
        str_starts_with((string) $store->rows[$result['key']]['provider_id'], 'sub_'));
}

// ---------------------------------------- the scenario from the brief -----

section('timeout, then retry (provider already accepted)');
{
    $store = new InMemoryStore();
    // First call: the provider WRITES the subscriber, then we time out.
    // Second call: ordinary success.
    $client = new MockNewsletterClient(['timeout', 'ok']);

    $first = subscribe($EMAIL, 'weekly', $store, $client, $log);

    check('first attempt reports pending_retry, not failure',
        $first['status'] === 'pending_retry');
    check('local record survives the timeout as pending',
        $store->rows[$first['key']]['status'] === 'pending');
    check('provider did create a record we never heard about',
        $client->subscriberCount() === 1);
    check('a retry delay was computed', $first['retry_after'] > 0);

    // The retry. Same email, so the same deterministic key.
    $second = subscribe($EMAIL, 'weekly', $store, $client, $log);

    check('retry confirms', $second['status'] === 'confirmed');
    check('retry reused the same idempotency key', $first['key'] === $second['key']);
    check('the two calls presented identical keys',
        $client->seenKeys[0] === $client->seenKeys[1]);
    check('STILL exactly one provider record, no duplicate',
        $client->subscriberCount() === 1, "got {$client->subscriberCount()}");
    check('exactly one local record', count($store->rows) === 1);
    check('exactly one confirmed subscriber',
        $store->countConfirmedFor($NORMALISED) === 1);
    check('the replay was recorded as a replay, not a create',
        in_array('replayed', array_column($store->attempts, 'outcome'), true));
}

section('timeout retried many times');
{
    $store = new InMemoryStore();
    $client = new MockNewsletterClient(['timeout', 'timeout', 'timeout', 'ok']);

    for ($i = 0; $i < 4; $i++) {
        $result = subscribe($EMAIL, 'weekly', $store, $client, $log);
    }

    check('eventually confirms', $result['status'] === 'confirmed');
    check('four calls were made', $client->calls === 4);
    check('still exactly one provider record', $client->subscriberCount() === 1);
    check('still exactly one local record', count($store->rows) === 1);

    // A further call after success must not touch the provider at all.
    $callsBefore = $client->calls;
    $again = subscribe($EMAIL, 'weekly', $store, $client, $log);
    check('a call after confirmation short-circuits', $client->calls === $callsBefore);
    check('and still reports confirmed', $again['status'] === 'confirmed');
}

// ---------------------------------------------------------- rate limit ---

section('rate limit');
{
    $store = new InMemoryStore();
    $client = new MockNewsletterClient(['rate_limit', 'ok']);

    $first = subscribe($EMAIL, 'weekly', $store, $client, $log);

    check('reports pending_retry', $first['status'] === 'pending_retry');
    check('reason is rate_limited', $first['reason'] === 'rate_limited');
    check("honours the provider's Retry-After", $first['retry_after'] === 30);
    check('signup is not lost, record is pending',
        $store->rows[$first['key']]['status'] === 'pending');
    check('no provider record yet', $client->subscriberCount() === 0);

    $second = subscribe($EMAIL, 'weekly', $store, $client, $log);
    check('retry after the limit clears confirms', $second['status'] === 'confirmed');
    check('exactly one provider record', $client->subscriberCount() === 1);
}

// ------------------------------------------------------- invalid email ---

section('invalid email');
{
    // Caught locally: no network call at all.
    $store = new InMemoryStore();
    $client = new MockNewsletterClient(['ok']);

    $result = subscribe('not-an-email', 'weekly', $store, $client, $log);

    check('rejected locally', $result['status'] === 'rejected');
    check('no provider call was made', $client->calls === 0);
    check('nothing written to the store', count($store->rows) === 0);

    // Rejected by the provider instead (passes our check, fails theirs).
    $store2 = new InMemoryStore();
    $client2 = new MockNewsletterClient(['invalid', 'ok']);

    $remote = subscribe('ada@example.invalid', 'weekly', $store2, $client2, $log);

    check('provider rejection is terminal', $remote['status'] === 'rejected');
    check('recorded as rejected locally',
        $store2->rows[$remote['key']]['status'] === 'rejected');

    $retry = subscribe('ada@example.invalid', 'weekly', $store2, $client2, $log);
    check('a retry of a rejected address does not call the provider again',
        $client2->calls === 1, "calls={$client2->calls}");
    check('and stays rejected', $retry['status'] === 'rejected');
}

// -------------------------------------------------------- normalisation --

section('normalisation and scope');
{
    $store = new InMemoryStore();
    $client = new MockNewsletterClient(['ok', 'ok', 'ok']);

    subscribe('  Ada@Example.COM ', 'weekly', $store, $client, $log);
    subscribe('ada@example.com', 'weekly', $store, $client, $log);
    subscribe('ADA@EXAMPLE.COM', 'weekly', $store, $client, $log);

    check('three spellings of one address produce one record',
        count($store->rows) === 1, 'got ' . count($store->rows));
    check('and one provider record', $client->subscriberCount() === 1);
    check('only the first call reached the provider', $client->calls === 1);

    // A different list is a different signup and must not be deduplicated away.
    subscribe('ada@example.com', 'daily', $store, $client, $log);
    check('a different scope is a separate subscription', count($store->rows) === 2);
}

// ------------------------------------------------------------- logging ---

section('logging hygiene');
{
    $serialised = json_encode($logLines);

    check('no raw email address appears in any log line',
        !str_contains(strtolower($serialised), 'ada@example.com'),
        'an address leaked into the logs');
    check('idempotency keys are logged', str_contains($serialised, 'key'));
    check('retry decisions are logged',
        str_contains($serialised, 'signup.retry_scheduled'));
    check('rate limits are logged with their delay',
        str_contains($serialised, 'retry_after'));
}

echo "\n{$checks} checks, {$failures} failed\n";
echo $failures === 0 ? "ALL CHECKS PASSED\n" : "SOME CHECKS FAILED\n";
exit($failures === 0 ? 0 : 1);
