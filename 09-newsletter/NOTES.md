# Question 9: APIs and data reliability

`subscribe.php` is the one function. Its store and HTTP client are duck-typed and
described in its docblock; the client returns the provider's status code (0 for
a timeout) rather than throwing. `tests/run-checks.php` has in-memory fakes and
44 assertions.

```bash
php 09-newsletter/tests/run-checks.php
```

## Approach

A timeout isn't a failure, it's unknown: the request may have landed. Blind
retries can duplicate; treating it as success can lose the signup. The design
makes retries safe instead of trying to guess.

**Deterministic idempotency key:**

```php
$key = hash('sha256', $scope . "\0" . strtolower(trim($email)));
```

Derived from the normalised email, not generated per attempt, so a retry sends
the same key and the provider returns the existing record (`created: false`). A
per-attempt UUID would create duplicates. Scoping by list lets one person join
multiple lists.

**Local record first.** `upsertPending()` runs before the network call, so the
intent survives a crash and a timeout leaves the row `pending`.

## Error handling

| Case | Treatment |
| --- | --- |
| Timeout / 5xx | Retryable. Row stays `pending`; retry with the same key. |
| 429 | Retryable. Honour `Retry-After`. |
| 422 / 400 invalid email | Terminal. Row marked `rejected`; later calls short-circuit. |

Obviously invalid emails are rejected locally before any request.

Backoff is exponential, capped at five minutes, with jitter so retries don't all
hit the provider at once after an outage. Jitter is derived from the key, so
schedules are reproducible.

## Storage

Subscriptions table keyed on the idempotency key: key, normalised email, status
(`pending`/`confirmed`/`rejected`), attempt count, last outcome, provider record
id, created/last-attempted timestamps.

## Logging

Log per attempt: event, idempotency key, outcome, attempt number, provider
request id. The key correlates attempts and contains no PII.

Don't log:

- **Email addresses.** PII, and logs are widely readable and retained. Use the
  key; look up the address in the DB if needed. A check asserts no address
  reaches the log.
- **Credentials / `Authorization` headers.**
- **Full request/response bodies.** They contain both of the above. Log status,
  provider id and error code as fields.

## Testing "accepted, then timed out"

`MockNewsletterClient` keeps a provider-side table keyed by idempotency key. On a
scripted `timeout` it writes the record and then returns status 0, which is the
scenario in the brief.

Swapping the key for a random per-attempt value makes 10 of the 44 checks fail,
including "exactly one provider record", so the tests catch the original bug.

```
PASS  provider did create a record we never heard about
PASS  retry reused the same idempotency key
PASS  the two calls presented identical keys
PASS  STILL exactly one provider record, no duplicate
PASS  exactly one confirmed subscriber
PASS  the replay was recorded as a replay, not a create
```

Also covered: three timeouts then success; calls after confirmation skip the
provider; rate limit then success; local and remote invalid email are terminal;
different spellings of one address collapse to one subscriber.

## Caveats

- **Assumes the provider supports idempotency keys.** If not, fall back to
  lookup-before-create with a per-key lock, and rely on the provider's unique
  email constraint. Weaker, since concurrent requests can both see "not found".
  First thing to check in their docs.
- **Retries belong in a worker.** `subscribe()` returns `pending_retry` with a
  delay rather than sleeping in the request.
- **Needs a reconciliation job.** If the process dies after `upsertPending()`,
  the row stays `pending` with nothing retrying it. A periodic sweep of stale
  pending rows fixes that; not implemented here.
