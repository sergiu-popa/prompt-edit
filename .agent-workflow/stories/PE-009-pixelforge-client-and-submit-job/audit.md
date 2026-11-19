# Audit — PE-009-pixelforge-client-and-submit-job

**Verdict: PASS**

Run on branch `pe-009-pixelforge-client-and-submit-job` from `f7d5c81` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 2) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-009]` diff (`git diff 350236a..f7d5c81`, 39 files outside `.agent-workflow`) against `story.md` AC 1–21, `plan.md`, both review rounds, QA and requirements §2.1, §2.3, §2.4, §4 and D-31 to D-33. That covers:
- `config/pixelforge.php`, `.env.example`, `phpunit.xml`, `Tests\TestCase` and the `AppServiceProvider` binding;
- the `PixelforgeClient` and `ReportsPixelforgeContext` contracts, `HttpPixelforgeClient`, both DTOs, `PixelforgeErrorCode`, `PixelforgeApiException` and its nine subclasses, and the ambiguous and not-configured errors;
- `SubmitGenerationAction`, `SubmitGenerationJob`, the dispatch in `CreateGenerationAction`, `LocalFailureReason`, and how the action uses PE-006's `TransitionGenerationAction`;
- the two test concerns and every new or changed test file.

The Laravel internals the design relies on were read in `vendor/`: `PendingRequest::marshalTransportException*()`, `RequestException::prepareMessage()`, `Job::fail()` and `CallQueuedHandler::failed()`. Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no page or prop, so they have nothing to check. PR size is not a finding (`.agent-workflow/CLAUDE.md` § Git hygiene).

## What was verified (provider-integration safety)

- **The key never leaves the request.**
  - The key is passed only through the constructor, which is `#[SensitiveParameter]`, and then through `withToken()`, which has returned before anything can throw. So no frame left on the stack holds it as a string. This matters because the dev container runs with `zend.exception_ignore_args=0`, where string traces show a 15-character preview of each argument.
  - Exception messages are fixed text plus a status. `RequestException`'s own message summarises only the response body. `ConnectionException` carries cURL's message, which names the URL but not the headers. Monolog renders an exception's trace as `file:line` only.
  - The sentinel is asserted absent from logs, JSON-encoded log context, `(string)` exceptions (with their previous chain and traces) and the queued payload, for a 202, a 401, a read timeout, a 503 and a 503 cut off mid-body.
  - Nothing in the app listens to the HTTP client's events (there is no Telescope, Pulse, Sentry or Flare in `composer.json`).
  - Every `PIXELFORGE_*` name in `.env.example` is empty, and `phpunit.xml` forces the key to `''` (now pinned, #2).
- **Redirects and timeouts.** `withoutRedirecting()` is set, and a 302 is ambiguous with one request recorded. Connect 5 s ≤ request 30 s < job 60 s < `retry_after` 90 s, pinned by a test. A timeout that is not a positive integer falls back to the default.
- **A stable idempotency key.** The ULID is both `Idempotency-Key` and `metadata.generation_id`. Each attempt reloads the row and rebuilds the body with one `json_encode` and fixed flags. A test proves byte-identical bodies across a reloaded Unicode row with two references.
- **Strict error parsing.**
  - A typed error needs all of these:
    - a JSON object whose `error` is an object;
    - string `type`, `code` and `message`;
    - a `param` of at most 255 characters, the size of `error_param`;
    - `errors` as string lists;
    - no NUL byte in `code`, `message` or `param`;
    - a documented code sent with its own status.
  - Every other answer is ambiguous: invalid UTF-8, a lone surrogate or nesting deeper than 512 all fail `json_decode`.
  - `code` is bounded by the enum, and `error_message` is a `text` column. `type` and `errors` are never stored or logged.
- **Exact `Retry-After`.** Only 1 to 9 digits count. `''`, `1.5`, `-1`, an HTTP-date and a repeated header (Laravel joins the values as `5, 5`) all count as absent. The job does not act on the value yet: PE-013 owns the wait, and the story accepts the default backoff until then.
- **An idempotent submit job.**
  - The job carries only the ULID. It is a no-op when the row has a provider id or a terminal status.
  - The `try` covers only the client call.
  - A failure in the transition is rethrown and retried under the same key, so the replay is free.
  - Two overlapping runs share the key, so the second one gets a replay, and the transition ignores it as a duplicate.
  - Out of attempts, `failed()` logs and never touches the row.
- **The job and the webhook race through one action.**
  - Every answer goes through `TransitionGenerationAction`, which re-reads the row under `lockForUpdate`.
  - A local failure is applied only while the row is `pending`. It is refused once Pixelforge has accepted the generation, and ignored once the row is terminal.
  - AC 16 delivers a signed webhook inside the fake's callback. The `queued` answer that follows is ignored, and the row keeps its single charge.
- **No real API call in any test.** `Http::preventStrayRequests()` runs in `Tests\TestCase::setUp()`, and every feature test extends it. The child scripts (`tests/scripts/*`) never create a generation, so they never dispatch the job. This is now pinned (#2).
- **Production without `PIXELFORGE_*`.** With every name removed, the app boots, the client resolves lazily to the HTTP driver with a null key, and the job throws not-configured with `Http::assertNothingSent()`. The row stays pending with its hold. Resolution is lazy, so an unknown driver fails only when a job runs, and `failed()` logs it with `error_code: not_configured`.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | `failed(Throwable $exception)` did not accept null. `Job::fail()` passes `null` on to `CallQueuedHandler::failed()` when a job is failed by hand (`$this->fail()`, which `Queueable` provides), and Laravel documents the hook as `?Throwable`. A manual fail would have thrown a `TypeError` and skipped the give-up line. | `app/Jobs/SubmitGenerationJob.php:48` | Suggestion | Fixed | It is now `failed(?Throwable)`, and it logs `exception: null` with the ULID. The new test `test_a_give_up_without_an_exception_is_still_logged_and_leaves_the_generation_waiting` failed with the `TypeError` before the change and passes after it (`43506f3`). |
| 2 | The two guards that keep tests away from the real API had no test: `Http::preventStrayRequests()` in `Tests\TestCase` and the forced-empty key in `phpunit.xml`. Deleting either line would leave the suite green. QA probed the first one only in a scratch test that was not committed. | `tests/TestCase.php:21`, `phpunit.xml:34` | Suggestion | Fixed | `test_an_unfaked_create_is_refused_before_it_leaves_the_suite` asserts a `StrayRequestException` whose string does not contain the key. `test_the_suite_never_sees_a_developer_key` asserts the empty env and the null config. It uses `assertTrue` with a fixed message, so a failure can never print a real key from `.env` (`dd454ca`). |
| 3 | `Retry-After` accepts up to 9 digits, which is about 31 years. | `app/Support/Pixelforge/Exceptions/PixelforgeApiException.php:100` | Suggestion | Deferred | This story only parses the value, and nothing waits on it yet. PE-013, which releases the job after `Retry-After`, must clamp it. The docs give 2 s and 5 s. |
| 4 | When the transition does not apply a definitive rejection (the row is no longer pending), the info or error line still says "Failed a generation at no charge". | `app/Domain/Generation/Actions/SubmitGenerationAction.php:105-112` | Suggestion | Skipped | The line carries the transition `outcome`, and a `RefusedConflict` is already logged at error level by the transition. Reaching this needs two overlapping runs that both get a 400, and the 400 creates nothing, so no money is affected. |
| 5 | `PIXELFORGE_BASE_URL` is not required to be `https`. | `config/pixelforge.php:21` | Suggestion | Skipped | The default is the docs' `https` URL, and the value is operator configuration, as the webhook secret is. Redirects are never followed, so a server cannot downgrade the request. PE-015 pins the production settings. |
| 6 | The response body is read with no size cap. | `app/Support/Pixelforge/Drivers/HttpPixelforgeClient.php:74,91` | Suggestion | Skipped | The only host is the configured Pixelforge URL over TLS, and redirects are never followed. A generation object is a few KB. The 30 s request timeout bounds the transfer. |
| 7 | If the after-commit push itself fails (for example, the `jobs` insert), the generation is committed without a job. | `app/Domain/Generation/Actions/CreateGenerationAction.php:70` | Suggestion | Deferred | This is accepted by design (D-32): pushing inside the transaction could hand a worker an uncommitted row on a non-database queue. Requirements §4 ("Worker died before submit") gives the recovery to PE-014, which re-dispatches pending rows. |
| 8 | Production's `zend.exception_ignore_args` is not verified. The dev container has it off, so string traces show 15-character previews. | `deploy.php` | Suggestion | Deferred | The key is never a string argument on the stack when anything throws (see above), so this setting only adds defence in depth. PE-015's go-live checklist should confirm `On`, the `php.ini-production` default. |

No Critical or Warning issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `chain-make.sh chain check` | Exit 0. Pint passes on 178 files, and Larastan reports no errors. PHPUnit: 919 passed (4,483 assertions), up from 916 by the three new tests. ESLint, Prettier and vue-tsc are clean, and Vitest runs 107 passed in 9 files. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-009/audit-fix2.log`. Every test used `Http::fake`, and no real Pixelforge request was made.
