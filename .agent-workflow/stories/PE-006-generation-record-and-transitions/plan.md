# Plan — PE-006-generation-record-and-transitions

## Approach
A `generations` table keyed by a ULID holds the request exactly as given, the server-side PE-002 user estimate, and every provider field PE-007 to PE-016 read. Pixelforge instants are stored as `timestamptz` and always written with their offset, so the session timezone never shifts them. `GenerationSnapshot::from()` in `app/Support/Pixelforge` turns a generation object (API response or webhook `data.object`) into typed values, reusing PE-001's `TokenUsage` and the Shared enums, and throws one typed exception for anything it cannot read exactly. One `TransitionGenerationAction` takes either a snapshot or a local failure. It re-reads the row under `lockForUpdate`, classifies the pair by `GenerationStatus` rank into a reported outcome, and writes only on `Applied`, through an UPDATE that is conditional on the status it read (D-24).

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and run `chain-make.sh chain check` green before committing. Tasks 1–6 are the record, the create action and the snapshot parser. Tasks 7–12 are the transition action. The cut point is after task 6 (see Risks).

- [x] 1. Add `GenerationStatus` with `rank()`, `isTerminal()` and `label()`, test-first for AC8's rank order, the terminal set and the labels. Commit: `[PE-006] Add the generation status enum with its forward-only rank`
- [x] 2. Add the `generations` migration (every column below), the `Generation` model, `LocalFailureReason` and the factory's pending definition. Test-first for AC4, AC5 and the storage half of AC15. Commit: `[PE-006] Add the generations table and model`
- [x] 3. Add the factory's `queued`, `processing`, `succeeded` and `failed` states, test-first for AC16's invariants. Commit: `[PE-006] Add per-status generation factory states`
- [x] 4. Add `NewGenerationData` and `CreateGenerationAction`, test-first for AC1 (worked example), AC2 (rollback) and AC3 (byte-identical round trip). Commit: `[PE-006] Create the pending generation row with its server-side estimate`
- [x] 5. Add `GenerationSnapshot::from()` with the shape and type rules, plus `InvalidGenerationSnapshotException`, the three docs fixtures and the `InteractsWithGenerationSnapshots` trait with `pixelforgeFixture()`. Test-first for AC6, the shape rows of AC7 and the parsing half of AC15. Commit: `[PE-006] Parse Pixelforge's generation object into a typed snapshot`
- [x] 6. Add the status-dependent rules: a complete succeeded snapshot, usage and rates keys under PE-001's rules, and a failed snapshot with a null error. Test-first for the remaining AC7 rows. Commit: `[PE-006] Reject incomplete succeeded snapshots and unpriced token keys`
- [x] 7. Add `TransitionGenerationAction` for snapshot input and `GenerationTransitionOutcome`: lock, rank classification, and the AC9 write. Add `generationObjectFor()` and `snapshotFor()` to the trait. Test-first with the AC8 table over the four provider-status columns and AC9, and record decision D-24. Commit: `[PE-006] Apply generation snapshots forward-only under a row lock`
- [x] 8. Pin the AC10 webhook-first sequences and the AC11 replays. No code change is expected; if a sequence fails, fix it in this commit. Commit: `[PE-006] Pin webhook-first and replayed snapshot sequences`
- [x] 9. Refuse AC12 identity mismatches before any rank check, and log every conflict at error level, test-first. Commit: `[PE-006] Refuse and log snapshots that conflict with the generation`
- [x] 10. Accept `LocalGenerationFailureData` as the action's second input kind, test-first for the AC8 local-failure column and AC13, including the logged refusal. Commit: `[PE-006] Fail pending generations locally and refuse once accepted`
- [x] 11. Make the write conditional on the status read under the lock, and add `ConcurrentGenerationTransitionException`, test-first with the simulated missed lock. Commit: `[PE-006] Guard the status write against a missed row lock`
- [x] 12. Add the child script `tests/scripts/transition-generation.php` and the AC14 two-process test. Commit: `[PE-006] Prove concurrent appliers of one snapshot write once`

## Domain
All PHP classes are `final`, and DTOs are `readonly`. Namespaces: `App\Domain\Generation\…` and `App\Support\Pixelforge\…`.

| Class / file | Location | Role |
| --- | --- | --- |
| `GenerationStatus` | `app/Domain/Generation/Enums/` | String-backed. `Pending` = `pending` (local, before submit), then `Queued`, `Processing`, `Succeeded` and `Failed`, spelled as Pixelforge spells them. `rank()` returns 0 / 1 / 2 / 3 / 3. `isTerminal()` is true for succeeded and failed. `label()` returns "Queued" for both pending and queued, then "Processing", "Succeeded" and "Failed". Consumers: the model cast, the snapshot DTO and the transition action. PE-011 and PE-012 use the label. |
| `GenerationTransitionOutcome` | `app/Domain/Generation/Enums/` | Values are snake_case, for logs. `Applied`, `IgnoredDuplicate`, `IgnoredStale` and `IgnoredTerminal`. `IgnoredConflict` is a terminal row that receives the opposite terminal status. `RefusedConflict` is an AC12 identity mismatch, or a local failure on a row Pixelforge accepted. PE-007 settles on a terminal `Applied`, and runs D-09 only on an `IgnoredConflict` over a locally failed row. `RefusedConflict` never touches money. |
| `LocalFailureReason` | `app/Domain/Generation/Enums/` | String-backed, with one case for now: `ContentPolicyViolation` = `content_policy_violation` (AC13). PE-009 appends `validation_failed` and `idempotency_key_reused`, and PE-013 and PE-014 append theirs. Its creation moves here from PE-009's scope because the local-failure input needs a type. |
| `Generation` | `app/Domain/Generation/Models/` | `HasUlids`, `HasFactory` with `#[UseFactory(GenerationFactory::class)]`, and `#[Fillable]` listing every column the two actions write. `#[DateFormat('Y-m-d H:i:sP')]` makes every date the model writes carry its offset. Casts only: the enums, `array` for `reference_images` and `rates`, `integer` for the n$ and token columns, and `immutable_datetime` for the dates. No relations (none has a consumer yet) and no methods. |
| `GenerationFactory` | `database/factories/` | The definition is a pending row: the AC1 worked example with owner `User::factory()`. That is `A lighthouse on a cliff at dawn, in oils` (40 code points), medium, 1536x1024, `["https://example.com/style.png"]`, and the estimate 95,237,500 / 109,312,500 / 123,387,500. The states build on each other: `queued()` adds a unique `gen_…` provider id and `provider_created_at`; `processing()` adds `started_at`; `succeeded()` adds usage 10 / 765 / 1,650 / 1,100 (inside the definition's estimate), the published rate strings, an image URL, an expiry of `completed_at` + 24 h, and `completed_at`; `failed()` adds error `generation_failed` with a message, and `completed_at`. |
| `NewGenerationData` | `app/Domain/Generation/DataTransferObjects/` | `string $prompt`, `ImageSize $size`, `ImageQuality $quality`, `list<string> $referenceImages`. It has no estimate field, because the price is always recomputed. PE-011 builds it from its Form Request. |
| `CreateGenerationAction` | `app/Domain/Generation/Actions/` | The constructor takes `EstimateGenerationCostAction`. `execute(User $user, NewGenerationData $data): Generation` runs the estimate (prompt, quality, size, reference count), then inserts the pending row with the estimate's `userPrice` min, typical and max in one `DB::transaction`. Nothing is trimmed or normalised. Consumers: PE-007 adds the hold inside the transaction, PE-009 dispatches after commit, and PE-011 calls it over HTTP. |
| `GenerationSnapshot` | `app/Support/Pixelforge/DataTransferObjects/` | `from(array $generation)` is the only constructor. Fields: `id`, `GenerationStatus $status`, `ImageSize $size`, `ImageQuality $quality`, `?string $generationId` (from `metadata.generation_id`), `?string $errorCode`, `?string $errorMessage`, `?TokenUsage $usage`, `?array<string, string> $rates`, `?string $imageUrl`, `?CarbonImmutable $imageUrlExpiresAt`, `CarbonImmutable $createdAt`, `?CarbonImmutable $startedAt` and `?CarbonImmutable $completedAt`, with all instants in UTC. It reuses the domain types (the Shared enums, Billing's `TokenUsage`, `GenerationStatus`) instead of mirroring them, so there is one vocabulary. |
| ↳ shape rules (task 5) | | **Required:** `id` (a non-empty string), `object` = `generation` (so passing a whole webhook envelope fails), `status`, `size`, `quality`, `metadata` (an object) and `created_at`. `status` must be a Pixelforge status: `pending` is ours, so it counts as unknown. **`metadata.generation_id`:** a string, or absent. Absent becomes null, which the transition refuses (AC12). **Nullable fields:** `error`, `usage`, `rates`, `image_url`, `image_url_expires_at`, `started_at` and `completed_at`. Absent means the same as null; when present, each must have the right type. `error` is `{code, message}`, and both are strings. **Timestamps:** ISO-8601 in whole seconds, with `Z` or an offset, converted to UTC. Any parse warning rejects the value: `createFromFormat` silently rolls Feb 30 over to March. **Unknown top-level fields** are ignored. |
| ↳ status rules (task 6) | | **Succeeded** requires `usage`, `rates`, `image_url` and `completed_at`. **`usage`** goes through `TokenUsage::from()`: PE-001's exact keys, an optional total that must match, non-negative integers. Its exception is wrapped. **`rates`** must have exactly the `TokenType` keys, with string values kept verbatim; conversion to n$ and its whole-n$ rule stay at PE-007's settlement. **`image_url`** may be any non-empty string, because PE-010's AC20 placeholder is app-relative. **A failed snapshot** with a null `error` parses with both error fields null. |
| `InvalidGenerationSnapshotException` | `app/Support/Pixelforge/Exceptions/` | Extends `\InvalidArgumentException`. Every rejection throws this one type, so callers catch one thing. Named constructors cover a missing field, an invalid field, an unknown status, an incomplete success, invalid usage (wrapping PE-001's exception as `previous`) and invalid rates. The message names the field path, e.g. `metadata.generation_id` or `usage.reasoning_tokens`. |
| `LocalGenerationFailureData` | `app/Domain/Generation/DataTransferObjects/` | `LocalFailureReason $reason`, `?string $errorCode`, `?string $errorMessage`, `?string $errorParam`. Consumers: the action, then PE-009, PE-013 and PE-014. |
| `TransitionGenerationAction` | `app/Domain/Generation/Actions/` | `execute(Generation $generation, GenerationSnapshot\|LocalGenerationFailureData $change): GenerationTransitionOutcome`. It is the single transition path (D-06), so it has one public method, one lock and one place for PE-007's money writes. The steps are in the rows below. |
| ↳ lock | | One `DB::transaction`, which becomes a savepoint inside PE-008's event transaction. It re-reads the row by key with `lockForUpdate()`. The given model is used only for its key and is never mutated; callers `refresh()` it. |
| ↳ classify a snapshot | | 1. Identity: if `generationId` is missing or is not the row's ULID, or `id` differs from a stored provider id, the outcome is `RefusedConflict`, whatever the status. 2. Terminal row: the same status is `IgnoredDuplicate`, the other terminal status is `IgnoredConflict`, and a non-terminal status is `IgnoredTerminal`. 3. Otherwise compare ranks: higher is `Applied`, equal is `IgnoredDuplicate`, lower is `IgnoredStale`. |
| ↳ classify a local failure | | A pending row is `Applied`, a queued or processing row is `RefusedConflict`, and a terminal row is `IgnoredTerminal`. |
| ↳ write on `Applied` | | **A snapshot writes** status, provider id, `provider_created_at`, `started_at` and `completed_at` as received. On succeeded it adds the four usage counts, the rates, the image URL and its expiry; on failed, the error code and message. **A local failure writes** status failed, the reason, and the optional error code, message and param. The values are filled onto the locked model so the casts apply. Task 11 makes the write an UPDATE conditional on the key and the status read under the lock. Anything other than one affected row throws `ConcurrentGenerationTransitionException`. Ignored and refused outcomes issue no UPDATE. |
| ↳ log conflicts | | Every `IgnoredConflict` and `RefusedConflict` calls `Log::error` with `generation_id`, `status`, `provider_id`, `incoming_status`, `incoming_provider_id` and `outcome`. For a local failure, the incoming status is `failed` and the incoming provider id is null. Each cause gets its own message. Duplicates and stale snapshots are not logged. |
| `ConcurrentGenerationTransitionException` | `app/Domain/Generation/Exceptions/` | Extends `\RuntimeException`, and the message carries the ULID and both statuses. It is thrown only when the row changed under the lock, which rolls the transaction back. The callers' retry paths (webhook redelivery, job retry, the next poll) then classify the change correctly. |

## Data model
One migration, made with `php artisan make:migration create_generations_table`. Postgres 17.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `ulid()->primary()`, `char(26)` | The lowercase ULID from `HasUlids`. It is the idempotency key and `metadata.generation_id` (D-05). There is no sequence anywhere on the table (AC4). |
| `user_id` | `foreignId()->constrained()->restrictOnDelete()` | The only integer key. Generations are billing records, so deleting an owner is refused. There is no index here; PE-011 adds the history index. |
| `status` | string | `GenerationStatus`. No DB default: the create action sets `pending`. |
| `prompt` | text | Exactly as given, with no length cap here. |
| `size`, `quality` | string | `ImageSize`, `ImageQuality`. |
| `reference_images` | `json`, not null | `[]` when there are none. Plain `json` keeps the text and order exactly. |
| `user_estimate_min_nanodollars`, `user_estimate_typical_nanodollars`, `user_estimate_max_nanodollars` | bigint, not null | D-03. PE-007's hold reads the max. |
| `provider_id` | string, nullable, unique | Postgres treats NULLs as distinct, so any number of pending rows can exist (AC5). |
| `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens` | bigint, nullable | Set on succeeded. PE-016 and PE-018 sum them in SQL. |
| `rates` | `json`, nullable | The four strings as received; PE-007 passes them to `TokenRates::from()`. `json`, not `jsonb`, keeps key order, so read-backs can compare with `assertSame`. |
| `error_code`, `error_param` | string, nullable | The provider's `error.code`. On a local failure, the optional provider details. |
| `error_message` | text, nullable | |
| `local_failure_reason` | string, nullable | `LocalFailureReason`. |
| `image_url` | text, nullable | A signed URL, which can be long. |
| `image_url_expires_at`, `provider_created_at`, `started_at`, `completed_at` | `timestampTz`, nullable | Pixelforge instants (AC15, D-10). |
| `created_at`, `updated_at` | `timestampsTz()` | Every date column on the table follows the same rule. |

**AC15 mechanism.** `#[DateFormat('Y-m-d H:i:sP')]` writes `…+00:00`, so Postgres stores the exact instant whatever the session timezone. On read, Postgres returns the instant in the session's offset (e.g. `+02`), which PHP's `P` parses; this was checked on PHP 8.4.26 in the chain container, with `Z` too. A read-back therefore carries the session offset. Compare instants, or call `utc()` before taking a date. PE-016 buckets in SQL with `AT TIME ZONE 'UTC'`.

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-24 | The generation lifecycle is a string-backed `GenerationStatus` enum with a numeric rank, applied by one `TransitionGenerationAction`. The action reads the row under `lockForUpdate` and writes with an UPDATE conditional on the status it read. `spatie/laravel-model-states` is not used. | It adds no dependency. The rule is one rank comparison plus a terminal check, and a table test proves it for every pair. The conditional write means even a missed lock cannot double-apply. Model-states' transition classes add nothing this needs. |

## Tests
All tests are unit tests in `tests/Unit`, mirroring `app/`.
- **Database:** DB-backed files extend `Tests\TestCase` with `RefreshDatabase`; the only exception is the concurrency test.
- **Time:** tests that assert "writes nothing" travel time (`$this->travel(1)->minutes()`) between arrange and act, so a stray write would change `updated_at`. They compare the raw `DB::table('generations')` row before and after with `assertSame`.
- **Logs:** asserted through `MessageLogged`, as `BackupDatabaseCommandTest` does.

`InteractsWithGenerationSnapshots` (`tests/Concerns/`):
- `pixelforgeFixture(name)` decodes `tests/fixtures/pixelforge/<name>.json` with `JSON_THROW_ON_ERROR`.
- `generationObjectFor(Generation, GenerationStatus, overrides)` builds a docs-shaped object for a row. It echoes the request, sets `metadata.generation_id` to the ULID and fills the fields that status requires. Overrides are applied with `array_replace`.
  - The `id` is the row's provider id, or `gen_` plus the upper-cased ULID when the row has none. Successive snapshots of one row therefore share an id, as AC10 needs.
- `snapshotFor(...)` parses `generationObjectFor()`.

Five test files use the trait, and PE-007 to PE-009 reuse it.

Fixtures (`tests/fixtures/pixelforge/`) are copied verbatim from the docs, including `signature=…` and `"order_id": "1042"`:
- `generation-queued.json`: the create response.
- `generation-succeeded.json`: the succeeded example.
- `event-generation-succeeded.json`: the docs envelope, with `data.object` set to the succeeded example.

| Test | Covers |
| --- | --- |
| `Domain/Generation/Enums/GenerationStatusTest` | Rank order, terminal set and labels (task 1) |
| `Domain/Generation/Models/GenerationTest` | **AC4:** `Schema::getColumns` shows `id` as `char(26)` with no `nextval` default on any column; `Schema::getForeignKeys` lists only `user_id`; the model is non-incrementing with a string key. **AC5:** two null provider ids insert, and a duplicate raises `UniqueConstraintViolationException`. Assert the duplicate last, because Postgres aborts the transaction. **AC15:** with `date_default_timezone_set('Europe/Bucharest')` and `SET TIME ZONE 'Europe/Bucharest'` (restore PHP's timezone in `tearDown`), `completed_at` = `2026-11-18T23:59:59Z` reads back equal to that instant, and its `utc()` date is 2026-11-18. SQL `(completed_at AT TIME ZONE 'UTC')::date` gives the same date. |
| `Database/Factories/GenerationFactoryTest` | AC16: every state's invariants |
| `Domain/Generation/Actions/CreateGenerationActionTest` | **AC1:** the worked example's three stored amounts, status pending, every provider column null, and the owner. **AC2:** a throwing `created` model listener leaves no row. **AC3:** a data provider of prompts and references, each read back through a fresh query with `assertSame`. |
| `Support/Pixelforge/DataTransferObjects/GenerationSnapshotTest` | **AC6:** both docs fixtures, and the envelope's `data.object`. That gives usage 12 / 765 / 6,450 / 3,184, rates `"5.00"` / `"10.00"` / `"40.00"` / `"12.50"` and UTC instants. **AC7:** every rejection, with the boundary rows below. **AC15:** the parsing half. |
| `Domain/Generation/Actions/TransitionGenerationActionTest` | **AC8:** a data provider over all 25 row × incoming pairs. The local-failure column is added in task 10. Each row asserts the outcome; ignored and refused rows assert that the row is untouched. **AC9:** each applied status's recorded columns. **AC13:** the recorded local-failure fields. **Task 11:** the simulated missed lock. |
| `Domain/Generation/Actions/TransitionGenerationActionSequencesTest` | **AC10:** pending → succeeded, then queued gives `IgnoredTerminal` and the full result stays. Pending → processing, then queued gives `IgnoredStale`. **AC11:** a late processing after succeeded; failed then succeeded, and succeeded then failed; the same snapshot applied five times, giving one `Applied` and four `IgnoredDuplicate`. |
| `Domain/Generation/Actions/TransitionGenerationActionConflictsTest` | **AC12:** each identity row, including on a terminal row and on a pending row, each with its error log context. Both `IgnoredConflict` pairs are logged. **AC13:** the refusal is logged for both queued and processing. |
| `Domain/Generation/Actions/TransitionGenerationActionConcurrencyTest` | AC14, in the rows below |
| ↳ setup | Uses `DatabaseTruncation`, because the children must see committed rows. It truncates `generations` and `users` again before the app is destroyed, so later `RefreshDatabase` tests start empty. A committed queued row is the target. |
| ↳ run | The test holds the row with `lockForUpdate` in an open transaction. It starts two children with `Process::start()`, each running `tests/scripts/transition-generation.php` with the ULID as an argument and the same succeeded object on stdin. The DB settings are passed explicitly from `config('database.connections.pgsql')`. The test polls `pg_stat_activity` until both children wait on a lock (deadline ≈15 s), then commits, in a `finally` block. |
| ↳ assert | Both children exit 0. The outcomes are exactly {`applied`, `ignored_duplicate`}. The applier issued one UPDATE on `generations` and the other child none; each child counts its own through `DB::listen`. The row holds the snapshot. |
| `tests/scripts/transition-generation.php` | Not a PHPUnit file. It boots the app as artisan does, resolves the action, applies the stdin object to the ULID's row, and prints `{outcome, generation_updates}` as JSON. |

**Simulated missed lock (task 11).** A `DB::listen` on the same connection fires once, right after the action's `for update` select, and sets the row's status to succeeded. The action, which read processing, then has its conditional UPDATE match nothing. Expect `ConcurrentGenerationTransitionException`. The savepoint rollback restores the row, so it is unchanged.

Boundary rows to add beyond the ACs:

| Subject | Row | Expect |
| --- | --- | --- |
| Snapshot | the whole webhook envelope instead of `data.object` | throws (`object` is `event`) |
| Snapshot | `status` `pending`, `QUEUED`, `cancelled` | throws, unknown status |
| Snapshot | `size` `512x512`; `quality` `ultra`; `id` `""` or `42`; `metadata` `"x"`; `metadata.generation_id` `42` | throws, naming the field |
| Snapshot | `metadata` `{}` | parses, `generationId` null |
| Snapshot | `created_at` `2026-11-04 14:03:11`, `2026-11-04T14:03:11.250Z`, `2026-02-30T00:00:00Z`, `1762265000` | throws |
| Snapshot | `completed_at` `2026-11-19T01:59:59+02:00` | `2026-11-18T23:59:59Z`, UTC |
| Snapshot | extra top-level `livemode: true` | ignored |
| Snapshot | `error` `{"code": "internal_error"}`, without a message | throws |
| Snapshot | succeeded with `usage` null, `rates` absent, `image_url` null or `completed_at` null | throws, one row each |
| Snapshot | succeeded with `image_url` `/fake-pixelforge/images/gen_x.png` | parses (PE-010) |
| Snapshot | usage with an extra `cached_tokens`, a missing `reasoning_tokens`, a `total_tokens` mismatch or `"12"` | throws, wrapping PE-001's exception |
| Snapshot | rates with an extra `cached_tokens: "1.00"`, a missing `output_tokens`, or `5.00` as a number | throws |
| Snapshot | rate `"5.0001"` | parses: the whole-n$ check is PE-007's |
| Create | prompts `"  a cat  "`, `🎨 👍🏽`, decomposed `café` (escaped U+0301), `東京の夜景`, `a\nb` | byte-identical |
| Create | refs `["https://b.example/x.png?s=1&t=%20", "https://a.example/y.png"]`, and `[]` | the same order; `[]`, not null |
| Transition | a terminal row receiving a snapshot whose `generation_id` is another row's | `RefusedConflict`, not `IgnoredTerminal` |
| Transition | a pending row receiving `generation_id` missing | `RefusedConflict`, still pending |
| Transition | a local failure carrying only a reason | `Applied`, with every `error_*` column null |

## Risks
- **PR size is far over budget.** The estimate is ≈2,300 changed lines: ≈900 production, ≈1,250 tests and ≈150 fixtures and the child script. The story's 600–700 leaves out the fixtures, the AC7 rejection rows, the 25-row table and the two-process test. The user accepts one PR of 12 self-contained commits; the largest are tasks 5 and 7, at ≈350–400 lines. If a cut is asked for, cut after task 6:
  - PR A (tasks 1–6, ≈1,300): the record, the create action and the parser.
  - PR B (tasks 7–12, ≈1,000): the transition action.
- **D-24 numbering.** PE-005 is being planned in parallel from the same base. If it also claims D-24, renumber at merge.
- **Scope moves.**
  - `LocalFailureReason` is created here with one case; PE-009 appends to it rather than creating it.
  - `image_url` has no absolute-URL rule, which reconciles PE-010's AC20.
- **Support depends on domain types.** The snapshot DTO uses `GenerationStatus` and Billing's `TokenUsage`, deliberately, so there is one vocabulary; Support already uses the Shared enums (PE-002). Review should not split them into mirrored provider enums.
- **Raw writes bypass `DateFormat`.** A query-builder `update()` with a Carbon binding is formatted without an offset, which shifts the instant under a non-UTC session. The action must fill the model first (casts apply) and write its dirty attributes. Task 11's conditional UPDATE does exactly that.
- **Postgres aborts the transaction on a unique violation.** In AC5 the duplicate insert is the test's last statement. A duplicate provider id on apply, which never happens with Pixelforge's unique ids, would surface as an exception and roll back.
- **Concurrency test hygiene.**
  - Committed rows must not leak into later `RefreshDatabase` tests, so it truncates on teardown too.
  - The lock-holding transaction must end in `finally`, or the children hang until their timeout.
  - The children inherit the environment. There is no `bootstrap/cache/config.php`, so env DB settings apply; pass them explicitly anyway.
  - The children run the transition only, so the `.env` Pixelforge key is never used.
- **Prompt bytes Postgres cannot store.** Invalid UTF-8 makes the insert throw and roll back, which satisfies AC2. A NUL byte does not throw: pdo_pgsql silently cuts the text at it. `CreateGenerationAction` therefore refuses a prompt or reference image URL containing NUL with `InvalidGenerationRequestException` before the insert (QA round 1). Rejecting both politely with a field error is PE-011's validation.
- **Hooks for later stories.**
  - PE-007 writes money inside this action's transaction on a terminal `Applied`, and on an `IgnoredConflict` over a row with `local_failure_reason`.
  - PE-014 stamps its last-provider-update column on `Applied` only.
  - Neither may write on ignored or refused outcomes (AC9).

## Engineer notes
Where the implementation differs from the plan, and why:
- **Child input (task 12).** The child script takes the generation object as its second argument, not on stdin. Symfony's Process only feeds a string stdin while the parent waits on the child, and the parent has to see both children wait on the lock first.
- **Lock-wait poll (task 12).** Inside the lock-holding transaction, Postgres keeps one snapshot of `pg_stat_activity` from the first read. Each poll therefore calls `pg_stat_clear_snapshot()` first.
- **Model properties (task 3).** `Generation` declares `@property` types. Larastan read the `timestampTz` columns as strings and ignored the `immutable_datetime` casts.
- **Usage errors (task 6).** `InvalidGenerationSnapshotException::invalidUsage()` wraps PE-001's exception as `previous`. Its message names the token key PE-001 reports (e.g. `reasoning_tokens`), not a `usage.` path, because PE-001's exception does not expose the key. An extra or missing rate key names its `rates.` path.
- **Extra test (task 11).** `TransitionGenerationActionTest` also checks that the conditional UPDATE keeps `completed_at` exact under a `Europe/Bucharest` session. This covers the "raw writes bypass `DateFormat`" risk.
- **Local failure and `completed_at`.** A local failure leaves `completed_at` null. No AC asks for it, and Pixelforge never completed anything.
