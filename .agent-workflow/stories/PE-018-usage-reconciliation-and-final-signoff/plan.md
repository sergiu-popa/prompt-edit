# Plan — PE-018-usage-reconciliation-and-final-signoff

## Approach
The Pixelforge contract gains `retrieveUsage()` for an inclusive UTC date range. It returns `UsageReportData`, read strictly, with every `cost_usd` parsed by `Nanodollars::fromUsdString()`. The fake computes that report from its own table, in decimal arithmetic that never touches our n$ calculator. Beside PE-016's query, which stays as it is, a sibling query sums our recorded cost and tokens per UTC completion day, using D-64's scopes and its UTC date expression. `CompareProviderUsageAction` puts the two side by side by date, with deltas, an exact Match flag per day and for the total, and a fallback to our figures alone whenever Pixelforge fails. The report page shows this in a new table component. A read-only production smoke and the README's final pass then support the manual sign-off, which the orchestrator runs after merge.

## Tasks
Each task is one TDD commit. Write the failing test first, then the code, and get `make check` green before committing.
- The story adds no migration.
- Tasks 4 and 7 add config. Each new env name is cleared in `phpunit.xml`'s `<env force>` and `<server>` in the same commit (D-48).
- After task 7, run `make up` (the compose anchor changes), then `make restart-worker`. Run `make restart-worker` again before `make e2e` (task 13), because tasks 4, 7 and 8 change config and actions that the worker and the web container read.
- Tasks 11 and 12 use the `tailwindcss-development` skill and follow PE-016's report language: white `rounded-xl` cards, zinc text, right-aligned `tabular-nums`. There is no dark mode. Check them at 375 px.
- Task 15's test is in the `live` group: the orchestrator runs it from the main tree with the dev key (`make live-smoke`). Nobody runs it in a lane, and the Engineer only lints and type-checks it. The same goes for task 16's production smoke: `make js-check` type-checks it, and only the orchestrator runs it after merge.

Tasks 1–14 are the story's PE-018a: the usage operation, the fake usage, the parsing, the comparison, the report columns, the E2E spec and D-72 to D-75. Tasks 15–17 are PE-018b: the live check, the production smoke with D-76, and the README. The cut point is after task 14 (see Size).

- [x] 1. Add `UsageRowData`, `UsageReportData` and `InvalidUsageReportException`, plus the fixture `tests/fixtures/pixelforge/usage.json` (the docs' example) (Backend). Test-first: `UsageReportDataTest`. Commit: `[PE-018] Parse Pixelforge's usage report into exact integers`
- [x] 2. Add `FakeUsageReport` (Backend). Test-first: `FakeUsageReportTest`. Commit: `[PE-018] Compute the fake's usage per completed UTC day`
- [x] 3. Add `PixelforgeErrorCode::DateRangeTooLong` and `DateRangeTooLongException`, the match arms in `PixelforgeApiException::tryFromResponse()` and `SubmitAttemptOutcome::fromFailure()`, and the case in the test helper `pixelforgeErrorType()`. Test-first: rows in `PixelforgeApiExceptionTest` and `SubmitAttemptOutcomeTest`. Commit: `[PE-018] Type Pixelforge's date_range_too_long error`
- [x] 4. Add `PixelforgeClient::retrieveUsage()` and implement it in `HttpPixelforgeClient`, `FakePixelforgeClient` and `ThrottledPixelforgeClient`. Add `AmbiguousPixelforgeOutcomeException::unreadableUsage()` and the `pixelforge.usage.timeout_seconds` config, cleared in `phpunit.xml`. Add the usage answer helpers to `InteractsWithPixelforgeApi`. Pass the new timeout in `LiveSmokeGuard::client()`, and add the method to `ThrottledPixelforgeClientTest`'s test double. Test-first: `HttpPixelforgeUsageTest`, `FakePixelforgeUsageTest`, and rows in `ThrottledPixelforgeClientTest` and `PixelforgeConfigTest`. Commit: `[PE-018] Fetch usage for a UTC date range through the contract`
- [x] 5. Make `ShowOperatorReportAction::UTC_DATE` public, and add `UsageFigures` and `SumRecordedUsageAction` (Backend). Test-first: `SumRecordedUsageActionTest` and `UsageFiguresTest`. Commit: `[PE-018] Sum our recorded cost and tokens per UTC completion day`
- [x] 6. Add `UsageAvailability`, `UsageComparisonLine` and `UsageComparisonData` (Backend). Test-first: `UsageComparisonDataTest`. Commit: `[PE-018] Compare each day's usage exactly, with deltas and totals`
- [x] 7. Add `FetchPixelforgeUsageAction` and the `pixelforge.usage.cache_seconds` config, cleared in `phpunit.xml`. Set its dev and E2E value of 1 in the `x-dev-bounds` anchor and `.env.example` (Backend, Config). Test-first: `FetchPixelforgeUsageActionTest`, and rows in `PixelforgeConfigTest` and `LocalEnvTemplateTest`. Commit: `[PE-018] Cache successful usage answers briefly per range`
- [x] 8. Add `CompareProviderUsageAction` (Backend). Test-first: `CompareProviderUsageActionTest`. Commit: `[PE-018] Fall back to our figures when Pixelforge usage fails`
- [x] 9. Add `FakeUsageParityTest` (AC 3's parity). No production change is expected. Commit: `[PE-018] Prove the fake's usage matches what we recorded`
- [x] 10. The controller renders the `usage` prop. Add `types/usage.ts`. Test-first: `ShowOperatorReportUsageTest`. Commit: `[PE-018] Pass the usage comparison to the report page`
- [x] 11. Add `UsageDelta.vue` and `UsageComparisonTable.vue` in `components/report/` (Frontend). Test-first: `UsageComparisonTable.test.ts`. Commit: `[PE-018] Show the reconciliation table with badges and deltas`
- [x] 12. `Show.vue` takes the `usage` prop and renders `UsageComparisonTable` between the per-day and per-user tables. In `Show.test.ts`, give `mountShow()` a default all-Match `usage` and add its row. Commit: `[PE-018] Add the reconciliation to the operator report`
- [x] 13. Add `USAGE_MATCH_TIMEOUT_MS` to `tests/e2e/support/liveStatus.ts` and add `tests/e2e/report-usage.spec.ts` (Tests). Run the full `make e2e`. Commit: `[PE-018] Cover today's In progress Match end to end`
- [x] 14. Append D-72 to D-75 to `requirements.md` §5, after the last row. Commit: `[PE-018] Record the usage reconciliation decisions`
- [x] 15. Add `test_usage_reports_every_day_in_exact_integers` to `LivePixelforgeSmokeTest` (group `live`, orchestrator-run). Commit: `[PE-018] Check the real usage report's shape in the live smoke`
- [x] 16. Add the signed-in read-only check to `tests/smoke/production.spec.ts`, and extend the README's Deploy runbook sentence on what the smoke checks. Append D-76 to `requirements.md`. Commit: `[PE-018] Check History and the report's Match in the production smoke`
- [x] 17. Do the README's final pass (README). Commit: `[PE-018] Finish the README for hand-in`

## Story clarifications
- **PR size.** The story asks for a split into PE-018a and PE-018b. The project rule wins: one PR, with that split as the cut point after task 14.
- **Decision numbers.** New rows are D-72 to D-76, appended after the last row. They are recorded in tasks 14 and 16, so the plan commit touches only `plan.md` and `story.md`.
- **Building on PE-016.**
  - PE-016's query, DTOs, `ReportRange`, request and `ReportTable` are unchanged. Only four of its files change:
    - `ShowOperatorReportAction::UTC_DATE` goes from private to public;
    - the controller gets one more action and one more prop;
    - `Show.vue` gets one more component;
    - `Show.test.ts` gets the new prop.
  - The controller calls two actions. PE-016's plan named this hook ("PE-018 adds its own action for the same range and merges by date key"), and it keeps PE-016's internals stable while PE-016's audit can still add fixes.
- **"We recorded"** (AC 4).
  - Ours is one sibling aggregate query with D-64's scopes, `billedByPixelforge()->completedBetween()`, so D-09 rows count. It groups by `ShowOperatorReportAction::UTC_DATE`, so both queries put every generation on the same UTC day.
  - It sums the provider cost, the four token columns and the count, each cast to bigint, and zero-fills the days with `ReportRange::dates()`.
  - Its day cost equals PE-016's provider cost for the same rows, and a test pins that.
- **Match** (AC 5–8, 10).
  - A day is a Match when its provider cost and its four token counts are exactly equal integers. There is no tolerance (D-03).
  - The delta is Pixelforge − ours, per figure, signed.
  - Generation counts are carried and shown but never compared (requirements §6).
  - The total compares the sum of our days with Pixelforge's own `total` object, not with the sum of its rows.
  - `has_mismatch` is true when any day or the total mismatches. It shows the AC 12 copy.
- **In progress** (AC 9). A day is in progress when it is now's UTC date. The range never goes past today (D-65), so only `end_date` can be today.
- **Reading Pixelforge's answer** (AC 1, 2, D-73).
  - The answer is accepted only when it is exactly as documented:
    - `object` is `usage`;
    - its `start_date` and `end_date` are the ones we sent;
    - `data` holds each date of the range exactly once, in any order;
    - each row and the total have non-negative integer counts;
    - `cost_usd` is a string, parsed by `Nanodollars::fromUsdString()`, and a leading `-` is refused (so `-0.000000000` is refused too). The parser already refuses more than 9 decimals, non-numbers and exponents.
  - Unknown keys are ignored.
  - An answer that breaks any of these rules is unreadable, so the comparison is unavailable. A partial comparison is never shown.
- **Failures** (AC 11, 14).
  - Any failure the contract can throw (`ReportsPixelforgeContext`) renders our figures with the "unavailable" notice. That includes a transport timeout, any 5xx, any 429, a 401, a spent budget, a client without a key, and an unreadable 2xx.
  - A `DateRangeTooLongException` gets its own notice.
  - Each failure logs one warning: the range plus the exception's `context()`, which never holds the key, a header or a body. Nothing is rethrown.
  - Any other exception is a bug and surfaces.
- **Range** (AC 11). PE-016's request rejects more than 92 days before the controller runs, so nothing is sent to Pixelforge. A test asserts `Http::assertNothingSent()` with a key configured.
- **Cache** (AC 13, D-74).
  - A success is stored for `pixelforge.usage.cache_seconds` under `pixelforge-usage:{start}:{end}`, on the default cache store (database in production, D-20).
  - It is stored as `UsageReportData::toArray()` and parsed again on a hit, because `cache.serializable_classes` is false, so a cached object would come back incomplete.
  - A failure is never stored, because `remember()` stores only a returned value.
  - A hit sends nothing and takes no budget unit.
- **Budget.** Every sent call goes through `ThrottledPixelforgeClient`, so it takes one unit of D-44's budget. The reconciler's reserve (D-51) sees that unit too.
- **Timeout.** The usage call replaces the request timeout with `pixelforge.usage.timeout_seconds` (5 s). The connect timeout stays at 5 s, and Guzzle's total `timeout` bounds both. There is no retry inline: the next page load is the retry. The usage is fetched synchronously, not as a deferred prop (see Risks).
- **`date_range_too_long` at create.** A create is never documented to get it, so the submit path reads it as `Ambiguous`, like a 404 at create (D-47). That keeps `SubmitAttemptOutcome::fromFailure()`'s match exhaustive.
- **Fake usage** (AC 3, D-75).
  - It covers only generations whose snapshot `status` is `succeeded`, filtered in SQL on the snapshot's `status` and `completed_at` text.
  - The fake always writes `completed_at` as a fixed-width Zulu string, so text order is time order, and the day is its first 10 characters.
  - Each row costs Σ snapshot usage × the snapshot's own rate string ÷ 1,000,000, in `BigDecimal`, exact at scale 9 with `RoundingMode::Unnecessary`, so an inexact cost throws. It is written with `toScale(9)`, never through `Nanodollars` or `CalculateProviderCostAction`, so local parity is an independent check of our integer conversion.
  - Rows are ascending by date, zero days included. `generations` counts the succeeded rows. `total` has no `date`, like the docs.
  - More than 92 days answers `DateRangeTooLongException`. A malformed date, or a start after the end, answers `ValidationFailedException`. Its 92 is Pixelforge's limit, a constant of the fake's own, not `ReportRange::MAX_DAYS`.
- **Fake parity across resets.** The fake's state is `fake_pixelforge_generations` in the app database (D-34). `make fresh`, CI's fresh database and RefreshDatabase therefore clear it together with ours, and no reset change is needed.
- **Money display.**
  - Every amount goes through `MoneyAmount`, so `money.ts` stays the only formatter (D-25), and the exact value is reachable on hover, focus or tap. A +1 n$ delta reads `+<$0.0001`, and its exact value is `$0.000000001`.
  - A positive delta gets a `+` text node before it. A negative one keeps money.ts's or `formatCount()`'s own minus sign.
  - A zero delta reads `0`, muted.
  - A non-zero delta is `font-medium text-red-700` with `data-nonzero`. Its sign and digits differ from `0`, so colour is never the only cue.
- **Copy.**
  - Section title: "Pixelforge reconciliation (per UTC day)".
  - Intro: "Pixelforge's own usage report for this API key beside what we recorded. A day is a Match only when its provider cost and all four token counts are exactly equal; Δ is Pixelforge − ours."
  - Column groups: "Generations", "Provider cost", "Tokens (text / image / output / reasoning)" and "Status". The sub-columns are "Pixelforge says", "We recorded" and "Δ"; Generations has no Δ. The footer row is "Total".
  - Badges: "Match" (success), "Mismatch" (danger) and "In progress" (info).
  - Notes under the table:
    - "Today (UTC) is in progress: generations still processing may complete later today.", shown only when today is in range;
    - "Generation counts are shown but never compared: Pixelforge doesn't say whether it counts failed generations."
  - Unavailable notice: AC 14's text, verbatim.
  - Range-too-long notice: "Pixelforge refused this range as too long, so it isn't compared. Our recorded figures are shown."
  - Mismatch explanation: AC 12's text, verbatim.
  - While Pixelforge's figures are missing, its cells, the deltas and the status read "—".
- **Production sign-off (AC 15–17).** The orchestrator runs it after merge (Manual production steps). No agent uses SSH, `dep`, `make smoke-production` or `make live-smoke`.

## Backend
All classes are `final`, declare `strict_types` and have explicit types. DTOs are `readonly`.

| Class / file | Location | Role |
| --- | --- | --- |
| `UsageRowData` | `app/Support/Pixelforge/DataTransferObjects/` | Task 1. `int $generations`, `TokenUsage $tokens`, `Nanodollars $cost`.<br>- `from(array $row, string $field)`: ints ≥ 0, the tokens through `TokenUsage`'s constructor, and `cost_usd` as in Story clarifications;<br>- `toArray()`: the docs' field order (without `date`), with `cost_usd` from `toUsdString()`. |
| `UsageReportData` | same | Task 1. `string $startDate`, `string $endDate`, `array<string, UsageRowData> $days` (keyed by date, ascending) and `UsageRowData $total`.<br>- `from(array)`: the rules in Story clarifications, with the range checked by `ReportRange::fromDates()`;<br>- `toArray()`: the docs' shape, which `from()` reads back equal.<br>Consumers: both drivers (task 4) and the cache (task 7). |
| `InvalidUsageReportException` | `app/Support/Pixelforge/Exceptions/` | Task 1. Like `InvalidGenerationSnapshotException`: it names the field and the reason, and never repeats the body. |
| `FakeUsageReport` | `app/Support/Pixelforge/Fake/` | Task 2. `build(string $startDate, string $endDate): array`: the docs-shaped report from `FakePixelforgeGeneration` rows (Story clarifications). Consumer: `FakePixelforgeClient::retrieveUsage()`. |
| `PixelforgeErrorCode::DateRangeTooLong`, `DateRangeTooLongException` | `app/Support/Pixelforge/Enums/`, `…/Exceptions/` | Task 3. The case is `date_range_too_long` with status 422. The enum's docblock no longer leaves it to PE-018, and there is one more `tryFromResponse()` arm. `SubmitAttemptOutcome::fromFailure()` maps it to `Ambiguous`. |
| `PixelforgeClient::retrieveUsage(string $startDate, string $endDate): UsageReportData` | `app/Support/Pixelforge/Contracts/` | Task 4. `GET /v1/usage` for UTC `Y-m-d` dates, both inclusive. It documents `@throws` for `DateRangeTooLongException`, any other `PixelforgeApiException`, the ambiguous, not-configured and budget errors, and says each call takes one unit. |
| `HttpPixelforgeClient` | `app/Support/Pixelforge/Drivers/` | Task 4.<br>- a new promoted `#[Config('pixelforge.usage.timeout_seconds')] int $usageTimeoutSeconds`;<br>- `retrieveUsage()`: without a key it throws not-configured and sends nothing;<br>- it calls `request()->timeout($usageTimeoutSeconds)->get('usage', [start_date, end_date])`;<br>- a transport failure → `transportFailed()`;<br>- a non-2xx → `errorFrom()`;<br>- a 2xx → `UsageReportData::from()`, whose dates must equal the request's;<br>- a JSON or parse error, or another range → `unreadableUsage()`. |
| `AmbiguousPixelforgeOutcomeException::unreadableUsage(int $status, ?Throwable $previous)` | `app/Support/Pixelforge/Exceptions/` | Task 4. "Pixelforge answered HTTP %d without a readable usage report." The class docblock gains one line: for a read, it means no usable answer arrived. |
| `FakePixelforgeClient::retrieveUsage()` | `app/Support/Pixelforge/Drivers/` | Task 4. `MAX_USAGE_DAYS` = 92, counted with `ReportRange::inclusiveDayCount()`.<br>- More than that → `DateRangeTooLongException` (`invalid_request_error`, param `end_date`);<br>- an invalid date or a start after the end → `ValidationFailedException` (param `start_date`);<br>- otherwise `UsageReportData::from(FakeUsageReport::build())`.<br>It needs no secret, like retrieve. |
| `ThrottledPixelforgeClient::retrieveUsage()` | `app/Support/Pixelforge/` | Task 4. It takes one unit, then delegates. |
| `config/pixelforge.php` → `usage` | `config/` | Two production-default bounds:<br>- task 4: `timeout_seconds`, `PIXELFORGE_USAGE_TIMEOUT`, default 5; anything but an integer from 1 to 10 means the default, because a page waits on it;<br>- task 7: `cache_seconds`, `PIXELFORGE_USAGE_CACHE_SECONDS`, default 60; anything but a positive integer means the default. Dev and E2E use 1. |
| `ShowOperatorReportAction::UTC_DATE` | `app/Domain/Reporting/Actions/` | Task 5. Only `public` and a docblock line naming its second reader change. |
| `UsageFigures` | `app/Domain/Reporting/DataTransferObjects/` | Task 5. `int $generations`, `Nanodollars $providerCost`, and four `int` token counts.<br>- `zero()`, `plus()`, `fromPixelforge(UsageRowData)`;<br>- `matches(self)`: the five figures exactly equal, counts ignored;<br>- `minus(self)`: the five signed deltas as an array of ints, through `Nanodollars::minus()`;<br>- `toArray()`: `generations`, `provider_cost_nanodollars`, `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`. |
| `SumRecordedUsageAction` | `app/Domain/Reporting/Actions/` | Task 5. `execute(ReportRange): array<string, UsageFigures>`, keyed by every date, newest first: one aggregate base-builder query (Story clarifications), with a non-int figure throwing `UnexpectedValueException` as in PE-016. Consumer: `CompareProviderUsageAction`. |
| `UsageAvailability` | `app/Domain/Reporting/Enums/` | Task 6. String-backed `Available` (`available`), `Unavailable` (`unavailable`) and `RangeTooLong` (`range_too_long`). |
| `UsageComparisonLine` | `app/Domain/Reporting/DataTransferObjects/` | Task 6. `UsageFigures $recorded`, `?UsageFigures $pixelforge`.<br>- `matches(): ?bool`, null without Pixelforge;<br>- `toArray()`: `recorded`, `pixelforge`, `delta` (Pixelforge − ours) and `matches`, the last three null without Pixelforge. |
| `UsageComparisonData` | same | Task 6. `UsageAvailability $availability`, `string $today`, `array<string, UsageComparisonLine> $days` (newest first) and `UsageComparisonLine $total`.<br>- static `compared(string $today, array $recorded, UsageReportData $usage)`: each recorded date with Pixelforge's row of that date; the total is ours summed against Pixelforge's `total`;<br>- static `withoutPixelforge(string $today, array $recorded, UsageAvailability $availability)`;<br>- `hasMismatch()`;<br>- `toArray()`: `availability`, `has_mismatch`, `days` (each with `date`, `in_progress` and the line) and `total`. |
| `FetchPixelforgeUsageAction` | `app/Domain/Reporting/Actions/` | Task 7. `execute(ReportRange): UsageReportData`: the injected cache `Repository`'s `remember()` around `PixelforgeClient::retrieveUsage()`, as in Story clarifications. Consumer: `CompareProviderUsageAction`. |
| `CompareProviderUsageAction` | `app/Domain/Reporting/Actions/` | Task 8. `execute(ReportRange, CarbonImmutable $now): UsageComparisonData`.<br>- the recorded days, then the fetch, then `compared()`;<br>- a `DateRangeTooLongException` → `withoutPixelforge(RangeTooLong)`;<br>- any other `ReportsPixelforgeContext` → `withoutPixelforge(Unavailable)`;<br>- both log one warning, "Pixelforge usage is unavailable; the report shows our recorded figures only.", with `start_date`, `end_date` and `context()`. |
| `ShowOperatorReportController` | `app/Http/Controllers/Reporting/` | Task 10. It also takes `CompareProviderUsageAction` and renders `usage` from `execute($range, CarbonImmutable::now())->toArray()`. `@throws` is updated. |

## Frontend
`<script setup lang="ts">` throughout, with no `any`. Money is formatted only through `MoneyAmount`, and counts only through `formatCount()`.

| File | Role |
| --- | --- |
| `types/usage.ts` | Task 10: `UsageAvailability`, `UsageFigures`, `UsageDelta`, `UsageComparisonLine`, `UsageComparisonDay` (`date`, `in_progress` + line) and `UsageComparison`. It is a new file, so PE-016's `report.ts` and the shared `inertia.d.ts` stay untouched. |
| `components/report/UsageDelta.vue` | Task 11. Props `value: number` and `kind: 'usd' \| 'count'`. It renders zero, positive and negative values as in Story clarifications. |
| `components/report/UsageComparisonTable.vue` | Task 11. Prop `usage: UsageComparison`. It is a white `rounded-xl` card:<br>- the titled `h2`, which labels the table, and the intro;<br>- the amber `role="status"` notice for `unavailable` or `range_too_long`;<br>- the sky explanation while `has_mismatch`;<br>- the table in `overflow-x-auto`, so only its box scrolls sideways, with a two-row `thead` (`scope="colgroup"` groups, then the sub-columns);<br>- one row per day (`data-usage-day`, and `data-in-progress` with its badge in the date cell), with tokens written "a / b / c / d";<br>- a `tfoot` Total row with its own badge;<br>- the two notes.<br>Cells carry `data-pixelforge-cost`, `data-recorded-cost`, `data-cost-delta`, `data-pixelforge-tokens`, `data-recorded-tokens`, `data-tokens-delta`, `data-pixelforge-generations`, `data-recorded-generations` and `data-status`. |
| `pages/Reporting/Show.vue` | Task 12: one more prop, `usage`, and `UsageComparisonTable` between "Per day" and "Per user". |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-72 | The report compares each UTC day of its range with `GET /v1/usage` for the same `start_date` and `end_date`. It compares provider cost and the four token counts as exact integers, with no tolerance. Ours come from a sibling of D-64's query, with its scopes and its UTC date expression. The total compares our summed days with Pixelforge's own `total`. Generation counts are shown but never compared, and today (UTC) is labelled In progress | Pixelforge calls `/v1/usage` the source of truth, and n$ match its 9-decimal strings digit for digit (D-03), so any tolerance would hide a real difference. Sharing D-64's scopes and day expression means a difference can only come from the data, never from bucketing. Whether `generations` counts failures is undocumented (§6). Today's figures can still move. |
| D-73 | A usage answer is accepted only when it is exactly as documented: `object` `usage`, our dates, each date exactly once, non-negative integer counts, and a `cost_usd` string parsed by `Nanodollars::fromUsdString()` with no sign. Anything else is unreadable, so the comparison is unavailable. `date_range_too_long` is a typed error; a create is never documented to get it, so the submit path treats it as ambiguous, like a 404 at create (D-47) | A half-read answer could show a Match that isn't one. A float `cost_usd` would already have lost digits. Keeping the submit match exhaustive means a new code can never surprise a running job. |
| D-74 | A report load makes at most one `/v1/usage` call, through the contract and therefore the shared budget (D-44), with its own timeout of 5 s (`PIXELFORGE_USAGE_TIMEOUT`, at most 10) and no inline retry. A success is cached per range for 60 s (`PIXELFORGE_USAGE_CACHE_SECONDS`, 1 s in dev and E2E) on the default store, as the docs-shaped array, which is parsed again on a hit. A failure is never cached. Every failure the contract throws renders our figures with a notice and one warning that carries only its context. The fetch is synchronous | Reloads stay off Pixelforge's 60 rpm, and a catch-up still shows within a minute. `cache.serializable_classes` is false, so an object can't be cached. A failure is retried on the next load, never served stale. 5 s caps how long the page waits while Pixelforge is down. A deferred prop would need a second request path and a skeleton for a page one operator opens. |
| D-75 | The fake's `/v1/usage` comes from its own table: succeeded generations by the snapshot's `completed_at` UTC date, zero days included. Costs use decimal arithmetic on each snapshot's rate strings (brick/math, exact at 9 decimals), never our n$ calculator. `generations` counts succeeded rows, and more than 92 days answers 422. The table lives in the app database (D-34), so every reset clears it together with ours | The two sides are computed independently, so local and E2E parity really checks our integer conversion and our bucketing. No extra reset step is needed. |
| D-76 | The production smoke stays read-only: the only non-GET requests it allows are sign-in and sign-out. Signed in as the reviewer, it requires History to show a succeeded generation with a loaded image and its charge. It requires the default report to show every day and the total as Match, with no notice, and it reloads for up to 3 min, past the cache, before it fails. The demo set is refreshed shortly before hand-in so the images are less than 24 h old | It proves the brief's "leave a few generations on it" and the reconciliation on the real key without spending anything. A key used outside production then shows up on the next deploy. Image expiry is Pixelforge's, so the History check is only meaningful within 24 h of a refresh. |

## Tests
Shared helpers:
- `tests/fixtures/pixelforge/usage.json` (task 1) holds the docs' two-day example.
- `InteractsWithPixelforgeApi` gains `pixelforgeUsageAnswered(list $rows, ?array $total)`, a 200 with the docs' shape, and a static `usageRow()` builder (task 4). Four files use them.
- The PE-016 worked example's G1, G2 and G4 are a private `given…` helper in each file that needs them.

| Test | Location | Covers |
| --- | --- | --- |
| `UsageReportDataTest` | `tests/Unit/Support/Pixelforge/DataTransferObjects/` | Task 1 (AC 1, 2):<br>- the docs' example: 2 rows keyed by date, `0.169080000` → 169,080,000, and the total;<br>- `0.074595000` → 74,595,000, `0.373875000` → 373,875,000, `0.000000001` → 1, `0.000000000` → 0;<br>- each of these throws: 10 decimals, `-0.000000001`, `-0.000000000`, `abc`, `''`, `1e-9`, a JSON number, null, a negative or non-int count (`"5"`, `5.0`), a missing field, `object` other than `usage`, a missing, repeated or out-of-range date, a malformed date, a start after the end, and a missing total;<br>- unknown keys are ignored;<br>- `from(toArray())` is equal, and the costs come back with 9 decimals. |
| `FakeUsageReportTest` | `tests/Feature/Pixelforge/Fake/` | Task 2 (AC 3), through `createThroughFake()` and `runFakeLifecycle()` with travel:<br>- succeeded generations are summed by completed UTC day, and a completion at 23:59:59Z and one at 00:00:00Z the next day land on different days;<br>- failed, queued and processing rows count nowhere;<br>- zero days read `0.000000000`;<br>- each day's cost equals Σ usage × rates through `CalculateProviderCostAction` → `toUsdString()`;<br>- rows are ascending, and the total is their sum;<br>- the output passes `UsageReportData::from()`. |
| `PixelforgeApiExceptionTest`, `SubmitAttemptOutcomeTest` (existing) | `tests/Unit/…` | Task 3: a `date_range_too_long` 422 envelope → `DateRangeTooLongException`; the same code with another status → null; at create → `Ambiguous`. |
| `HttpPixelforgeUsageTest` | `tests/Feature/Pixelforge/` | Task 4 (AC 1):<br>- `GET …/v1/usage?start_date=2026-11-15&end_date=2026-11-18` with the sentinel bearer and no body, `timeout` 5 and `connect_timeout` 5 in the options, and no redirect followed;<br>- the docs' example maps;<br>- a 422 `date_range_too_long` → its typed error;<br>- a 401, a documented and a bare 429, and a 503 → their typed errors;<br>- an HTML 500 and a cURL 28 timeout → ambiguous;<br>- a 200 that is not JSON, has another range or holds a bad cost → `unreadableUsage` with its cause;<br>- without a key: not-configured and `Http::assertNothingSent()`;<br>- the sentinel is in no log and no exception. |
| `FakePixelforgeUsageTest` | `tests/Feature/Pixelforge/Fake/` | Task 4 (AC 3): 92 days is answered; 93 → `DateRangeTooLongException`; a start after the end and `2026-02-30` → `ValidationFailedException`; it works without a secret. |
| `ThrottledPixelforgeClientTest`, `PixelforgeConfigTest` (existing) | `tests/Unit/…`, `tests/Feature/Pixelforge/` | Task 4: usage takes one unit, and a spent budget never reaches the inner client. The usage timeout defaults to 5, a set value applies, 0, 11 and `abc` mean the default, and the suite runs with production's value. |
| `SumRecordedUsageActionTest` | `tests/Feature/Reporting/` | Task 5 (AC 4):<br>- succeeded and `providerCostOnly()` rows are summed per day, while failed, locally failed, pending, queued and processing rows count nowhere;<br>- every date is present, zeros included, newest first;<br>- the same `Pacific/Kiritimati` session and PHP timezone edge as PE-016 lands on the same day;<br>- each day's cost equals `ShowOperatorReportAction`'s provider cost for the same rows;<br>- every figure is an int, including sums of two rows. |
| `UsageFiguresTest` | `tests/Unit/Domain/Reporting/DataTransferObjects/` | Task 5: `matches()` is true when only the counts differ, and false for a 1 n$ difference or one token; `minus()` signs each delta; `plus()` and `fromPixelforge()`. |
| `UsageComparisonDataTest` | `tests/Unit/Domain/Reporting/DataTransferObjects/` | Task 6 (AC 5–10), no database, with the worked example's figures:<br>- every day and the total Match, and `has_mismatch` is false;<br>- `0.017480001` on 11-17 → that day and the total Mismatch, with delta +1 and the rest 0;<br>- AC 8 → deltas 15,125,000 / 5 / 0 / 280 / 312;<br>- token-only and count-only differences;<br>- zero days are 0 = 0 and Match;<br>- only today is in progress;<br>- the total uses Pixelforge's `total`, so an inconsistent total mismatches although every day matches;<br>- `withoutPixelforge()` keeps our figures, nulls the rest, and sets `has_mismatch` false;<br>- `toArray()` is all ints and bools. |
| `FetchPixelforgeUsageActionTest` | `tests/Feature/Reporting/` | Task 7 (AC 13), on the `database` cache store as in production:<br>- a second fetch within 60 s sends nothing, takes no unit and returns an equal report;<br>- 61 s later it sends again;<br>- another range is its own entry;<br>- a 500 then a 200: the first throws, the second sends and caches. |
| `PixelforgeConfigTest`, `LocalEnvTemplateTest` (existing) | `tests/Feature/Pixelforge/`, `tests/Unit/` | Task 7: the cache defaults to 60, a set value applies, 0 and `abc` mean the default, and the suite clears it; `.env.example` sets 1, and compose defaults to it. |
| `CompareProviderUsageActionTest` | `tests/Feature/Reporting/` | Task 8 (AC 11, 14):<br>- a matching answer → `available`;<br>- a 422 `date_range_too_long` → `range_too_long`, with our figures kept;<br>- a cURL 28 timeout, a 500, a 503, a 429, a 401, a spent budget (nothing sent), no key (nothing sent) and an unreadable 200 → each `unavailable`, our figures intact and no exception;<br>- each logs exactly one warning with the range and the error code, and never the sentinel or `Authorization`. |
| `FakeUsageParityTest` | `tests/Feature/Reporting/` | Task 9 (AC 3), with the fake on the `sync` queue:<br>- `[succeed]` generations at low and high quality, one completing just after UTC midnight (travel), one `[no-webhooks]` settled by the reconciler, and one `[fail]`;<br>- `CompareProviderUsageAction` → `available`, every day and the total Match, and the fake's figures equal ours exactly. |
| `ShowOperatorReportUsageTest` | `tests/Feature/Reporting/` | Task 10, frozen at 2026-11-18T12:00:00Z, range 2026-11-15 to 2026-11-18, with G1, G2 and G4:<br>- AC 6: the `usage` days and total Match with the table's figures;<br>- AC 7: `0.017480001` → `matches` false for 11-17 and the total, delta `provider_cost_nanodollars` 1, and `has_mismatch`;<br>- AC 8: its deltas;<br>- AC 9: only 11-18 has `in_progress`;<br>- AC 11: 93 days → a redirect and `Http::assertNothingSent()`, and a 422 → `range_too_long` with `report` unchanged;<br>- AC 13: two loads send one request;<br>- AC 14: a 503 → 200 with `pixelforge`, `delta` and `matches` null;<br>- `whereType` integer for every figure and boolean for every flag. |
| `UsageComparisonTable.test.ts` | `tests/js/integration/components/report/` | Task 11:<br>- the title labels the table, and the column groups are present;<br>- AC 6: Match on every row and on Total;<br>- AC 7: `+<$0.0001` with `data-nonzero`, `$0.000000001` on hover, and Mismatch on that day and on Total;<br>- AC 8: `+$0.0151` (exact `$0.015125000`), tokens `+5 / 0 / +280 / +312` with only the non-zero parts marked, and the AC 12 copy;<br>- no explanation while everything matches;<br>- unavailable: "—" cells, no badge, the AC 14 notice, no explanation;<br>- `range_too_long`: its notice;<br>- In progress on today's row only, and its note;<br>- counts in both columns, with no delta. |
| `Show.test.ts` (existing) | `tests/js/integration/pages/Reporting/` | Task 12: the reconciliation section renders between "Per day" and "Per user". |
| `report-usage.spec.ts` | `tests/e2e/` | Task 13, desktop and mobile:<br>- a registered user submits `[succeed] a calm harbour` (Low, Square) and waits for Succeeded;<br>- sign out, sign in as `OPERATOR_USER`, then open Report;<br>- `expect(…).toPass()` with reloads, up to `USAGE_MATCH_TIMEOUT_MS` (`POLLED_TERMINAL_TIMEOUT_MS` + MARGIN × the usage cache seconds, read as the other bounds are), until today's row shows In progress and Match;<br>- its two cost cells' exact values are equal;<br>- at 375 px, the page doesn't scroll sideways;<br>- it never asserts other days or the total, because parallel specs move them. |
| `LivePixelforgeSmokeTest` (existing, group `live`) | `tests/Feature/Pixelforge/Live/` | Task 15 (orchestrator, main tree, dev key): `retrieveUsage()` for the last 7 UTC days → 7 dates, each exactly once; the days' costs and tokens sum exactly to `total`. It never compares with our records. |
| `production.spec.ts` (existing) | `tests/smoke/` | Task 16 (AC 17, orchestrator only):<br>- it records every non-GET request and, at the end, requires exactly sign-in's `POST /login` and sign-out's `POST /logout`;<br>- it signs in as today (no `expect()` while the password is in the form);<br>- on `/generations`, at least one Succeeded item has a loaded image (`naturalWidth` > 0) and a charge, and every Succeeded item on the page has a charge;<br>- on Report, with reloads for up to 3 min: no notice, no Mismatch, every day row and Total Match;<br>- then it signs out. |

## Manual production steps (orchestrator, after merge)
Run these only after PE-016, PE-017 and this PR are merged in order and CI is green on `main`, within 24 h of hand-in. No password is ever on a command line: `E2E_REVIEWER_PASSWORD` comes from `read -rs`, as in the README's "Reviewer account". Results go into a PR comment as a ticked list, with no secrets.
1. **Live usage shape (dev key, main tree, any time before step 2).** Run `make live-smoke`: every test passes, including the task 15 usage test. Never run it with the production key.
2. **Deploy.**
    - Run `dep deploy`.
    - `curl -s -w ' %{http_code}\n' https://prompt.sergiupopa.dev/health/reconciler` must print `ok` and 200.
    - Run `make up`, then `export E2E_BASE_URL=https://prompt.sergiupopa.dev E2E_REVIEWER_EMAIL=<reviewer email>`, `read -rs E2E_REVIEWER_PASSWORD; export E2E_REVIEWER_PASSWORD`, and `make smoke-production`.
    - Every check passes, with one expected exception: the History image check fails while every image is older than 24 h. Step 9 runs the smoke again.
    - If the report check fails on a PE-015 day, stop and investigate before creating anything.
3. **Operator.** `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-operator '$E2E_REVIEWER_EMAIL'"` prints "… is already an operator." and exits 0.
4. **Balance (PE-017).** Sign in as the reviewer. If the account shows an available balance, it must be at least $0.7119, which covers the set's holds of 711,850,000 n$. If it is lower, buy one credits pack first.
5. **Note the instant.** `export SINCE=$(date -u +%FT%TZ)`.
6. **Demo set (AC 15).** As the reviewer, on Generate, submit PE-015's 8 rows. Every prompt is at most 40 code points:
    - `[succeed] a lighthouse at dawn`: Square, Low, 0 refs;
    - `[succeed] a red fox in fresh snow`: Landscape, Medium, refs `https://example.com/ref-1.png` and `https://example.com/ref-2.png`;
    - `[succeed] a mountain lake at noon`: Portrait, High, 0 refs;
    - `[fail] a broken pocket watch`, `[blocked] a forbidden scene`, `[overloaded] a busy harbour`, `[succeed] [duplicate] two moons` and `[succeed] [slow] a slow sunrise`: each Square, Low, 0 refs.
7. **Each status and a single charge.**
    - The three `[succeed]` rows are Succeeded with an image and a charge.
    - `[fail]` is Failed, "Not charged". `[blocked]` is rejected, "Not charged".
    - `[overloaded]` shows the retry note, then Failed after about 2 min, "Not charged".
    - `[duplicate]` is Succeeded, and the dashboard shows two deliveries per event.
    - `[slow]` is Processing for 60–90 s, then Succeeded without a reload.
    - Then `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:check-generation-money --since=$SINCE"` prints 8 rows, every check `OK`, and exits 0, with totals of at most 516,950,000 n$ provider cost and 646,187,500 n$ charged.
8. **Report parity (AC 16).** At least 60 s after the last generation is terminal, open Report as the reviewer.
    - The default 30-day range shows no notice.
    - Every day is Match, PE-015's days included, and today is In progress and Match.
    - Total is Match.
    - Hovering a "Pixelforge says" cost shows the same 9 decimals as "We recorded".
9. **Final smoke (AC 17).** Run `make smoke-production` again: every check passes, History's image check included.
10. **Record.** Post the ticked list of steps 1–9 as a PR comment, with dates and outcomes and no credentials.

## README
- **Reviewer guide (task 17):**
  - the Operator report bullet gains one sentence on the reconciliation and its Match badge, linking to the new section;
  - the decisions-log bullet names its span, D-01 to D-76;
  - the reviewer account's History line says the demo set is refreshed shortly before hand-in, because images expire after 24 h.
- **How the money works (task 17):** the "Reconciles with Pixelforge" bullet adds that the report checks every day against `/v1/usage`, exact to the nanodollar.
- **New "Reading the operator report" section, after "How it copes with Pixelforge" (task 17):**
  - the range rules;
  - the three tables;
  - Match, Mismatch and Δ = Pixelforge − ours;
  - In progress;
  - why a mismatch happens: the AC 12 reasons;
  - counts are not compared;
  - the 60 s cache and the unavailable notice;
  - local parity through the fake.
- **New "Stretch features" section, after it (task 17):**
  - the operator report (PE-016);
  - the reconciliation (PE-018);
  - one line: "Credit balances and Buy credits (PE-017) are described in their own section."
- **Production → Deploy runbook (task 16):** the smoke sentence adds the reviewer's read-only History and report checks, and notes that the History check needs an image less than 24 h old.
- **Production → new "Demo refresh and sign-off" subsection after "Go-live checks" (task 17):** steps 3–9 above in short form, with the same commands and no passwords.

## Size
The estimate is ≈ 3,700 changed lines, about 60 % of them tests. That is far above the ≈ 400 budget and the story's own ~450, which counted neither the strict parser's refusal matrix, nor the failure matrix, nor the two-sided fake parity. It ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–2 | Usage parsing, fake usage | 600 |
| 3–4 | Typed 422, contract, both drivers, timeout | 600 |
| 5–6 | Recorded usage, comparison | 750 |
| 7–9 | Cache, fallback, fake parity | 650 |
| 10–12 | Prop, types, table, page | 800 |
| 13–14 | E2E, D-72 to D-75 | 120 |
| 15–17 | Live check, production smoke, D-76, README | 230 |

**Cut point: after task 14.** PR A (≈ 3,500 lines) is the story's PE-018a: AC 1–14, the E2E spec and D-72 to D-75. PR B (≈ 230 lines) is PE-018b: the live check, AC 17's smoke, D-76 and AC 18's README. The manual sign-off (AC 15–17) follows B's merge.

## Risks
- **Parallel lanes.**
  - The shared files change only by appended lines or one new key: `requirements.md`, the README (new sections, plus a few sentences), the compose anchor (+1), `.env.example` (+2), `phpunit.xml` (+4) and `LocalEnvTemplateTest` (+2 rows).
  - `HandleInertiaRequests`, `inertia.d.ts`, `AppLayout.vue` and `DatabaseSeeder` are untouched.
  - The PE-016 files this story touches are listed in Story clarifications. An audit fix there rebases trivially.
  - No decision number outside D-72 to D-76 is used.
- **Transient mismatches.** A generation Pixelforge has completed but we haven't recorded yet (a webhook in flight, or a poll still due) mismatches until it lands, plus up to 60 s of cache. The AC 12 copy names it, and today's In progress label covers the commonest case. E2E polls with reloads past `POLLED_TERMINAL_TIMEOUT_MS`, for PE-014's `[no-webhooks]` spec.
- **Synchronous fetch.** While Pixelforge is down, each uncached load waits at most 5 s, because failures are not cached. A cached success is instant. A deferred prop was considered and left out (D-74).
- **Cache serialization.** Production's database store serializes, and `serializable_classes` is false, so caching the DTO itself would silently break on a hit. The array round trip is proven by task 1, and task 7 runs on the database store.
- **Exhaustive matches.** The new enum case reaches `PixelforgeApiException::tryFromResponse()`, `SubmitAttemptOutcome::fromFailure()` and the test helper `pixelforgeErrorType()`, all in task 3. `make stan` flags any match it misses.
- **Fake JSON filter.** It compares text, which is correct only because the fake alone writes `completed_at`, always as fixed-width Zulu. There is no index; the dev table holds thousands of rows at most.
- **The production smoke depends on time.** Its History check needs an image less than 24 h old, and its report check needs the key used only by production. Both are deliberate (D-76), and the README says so.
- **Budget.** Each uncached load takes 1 of the 50 units a minute. The cache caps one operator at about one unit a minute per range.

## Rebase (orchestrator, 2026-11-20)
This story was built on PE-016's reviewed tip `f75f12c`. PE-016 then merged (#26) and PE-017 was rebased onto it, so this branch now sits on PE-017's final tip `4f138a0`, and the notes cite the rebased SHAs. Two places met PE-017's changes, and both kept both stories' additions: `tests/Unit/LocalEnvTemplateTest.php` (the starter credit and usage cache rows) and requirements.md (D-67–D-71, then D-72–D-76). No code or test needed changing.
