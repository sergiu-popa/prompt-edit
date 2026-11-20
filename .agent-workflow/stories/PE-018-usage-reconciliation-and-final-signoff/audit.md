# Audit — PE-018-usage-reconciliation-and-final-signoff

**Verdict: PASS**

Run on branch `pe-018-usage-reconciliation-and-final-signoff` from `f847271` (QA recorded), in lane l18 with the fake driver. Prerequisites checked: story status `qa-passed`, `review.md` (round 2 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-018]` diff (`f75f12c..f847271`, 62 files) against `story.md` AC 1–18, `plan.md`, both review rounds, QA and D-72 to D-76. That covers:
- `UsageRowData`, `UsageReportData`, `InvalidUsageReportException`, `DateRangeTooLongException`, the `PixelforgeErrorCode` case and its `tryFromResponse()` arm, `SubmitAttemptOutcome::fromFailure()`;
- the contract method and its three implementations (`HttpPixelforgeClient`, `FakePixelforgeClient`, `ThrottledPixelforgeClient`), `FakeUsageReport`, `config/pixelforge.php`, `phpunit.xml`, `docker-compose.yml`, `.env.example`;
- `SumRecordedUsageAction`, `UsageFigures`, `UsageComparisonLine`, `UsageComparisonData`, `UsageAvailability`, `FetchPixelforgeUsageAction`, `CompareProviderUsageAction` and the controller;
- `UsageComparisonTable.vue`, `UsageComparisonCells.vue`, `UsageDelta.vue`, `Show.vue`, `types/usage.ts`;
- every new or changed test, `report-usage.spec.ts`, `tests/smoke/production.spec.ts`, the live smoke's usage test and the README.

I also read `Nanodollars::fromUsdString()`, `ReportRange`, `GenerationQueryBuilder::billedByPixelforge()` and `completedBetween()`, `HttpPixelforgeClient::request()` and `errorFrom()`, the exceptions' `context()`, `SubmitRetryPolicy`'s ambiguous branch, `HistoryItem.vue`'s `data-charge` and `playwright.production.config.ts`.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding.

## What was verified

- **Exact parsing (AC 1, 2, D-73).**
  - `cost_usd` must be a string without a leading `-`, then goes through `Nanodollars::fromUsdString()`, whose pattern `/^-?[0-9]+(?:\.[0-9]{1,9})?\z/` refuses more than 9 decimals, exponents, whitespace (`\z`, so no trailing newline either), `+`, empty and non-numeric values. A JSON number or null is refused before the parser. The refusal matrix lacked the `+`, space and newline rows; they are added (#2).
  - Every count goes through `is_int` and `>= 0`, so `"5"` and `5.0` are refused (tested).
  - `data` must be a list; each row's `date` must be one of the range's dates by strict string comparison, appear once, and every date must be present. A missing, repeated, out-of-range or malformed date throws.
  - **Total.** The comparison trusts Pixelforge's own `total` and compares it with the sum of *our* days, never with the sum of Pixelforge's rows. A Pixelforge total that disagrees with its own days therefore shows the total as Mismatch (`UsageComparisonDataTest`, inconsistent-total case). The live smoke separately checks that the real API's days sum to its total.
- **Comparison (AC 4–10, D-72).** `UsageFigures::matches()` is `Nanodollars::equals()` plus `===` on the four token counts: no tolerance; `generations` is carried and shown but never compared. Deltas are `pixelforge->minus(recorded)`, i.e. Pixelforge − ours. The total line uses the same `UsageComparisonLine::matches()`. Our side uses `billedByPixelforge()` (`output_tokens` not null, so D-09 provider-cost-only rows count and provider failures don't), `completedBetween()` on instants, and groups by the now-public `ShowOperatorReportAction::UTC_DATE`, so both queries bucket identically. In progress is `date === now->utc()` date.
- **Failure handling (AC 11, 13, 14, D-74).**
  - The usage call uses `pixelforge.usage.timeout_seconds` (default 5, 1–10 or the default) over the shared connect timeout; no `retry()`, so one call is one attempt.
  - `ThrottledPixelforgeClient::retrieveUsage()` takes exactly one unit before delegating; a cache hit takes none (tested on the `database` store).
  - Timeouts, HTML 5xx, 503, 429 (documented and bare), 401, a spent budget, no key and an unreadable 2xx all implement `ReportsPixelforgeContext`, so `CompareProviderUsageAction` renders our figures as `unavailable`; `422 date_range_too_long` renders `range_too_long`. The warning carries the range and `context()` only (`error_code`, `status`, `retry_after_seconds`), never the key, a header or a body; the tests grep the log for the sentinel and `Authorization`.
  - Failures are never cached: `remember()` stores only a returned value (tested with a 500 then a 200).
  - **Review S2.** A cached entry whose shape no longer reads threw `InvalidUsageReportException`, which is not a `ReportsPixelforgeContext`, so it would have 500'd the page for up to 60 s after a shape-changing deploy. Fixed with a parse fallback (#1).
- **Range (AC 11).** PE-016's request validates at most 92 days before the controller runs; `ShowOperatorReportUsageTest` asserts `Http::assertNothingSent()` for 93 days with a key configured. **`DateRangeTooLong` → `Ambiguous` at create is sound:** a create is never documented to get that code, so the answer doesn't prove nothing was created; `Ambiguous` retries with the same idempotency key (a free replay) and keeps the hold, exactly as a `404` at create does (D-31, D-47), and it keeps the `match` exhaustive so `make stan` would flag any future code.
- **Fake parity (AC 3, D-75).** The fake's usage comes from `fake_pixelforge_generations` in the app database, so `make fresh`, CI's fresh database and `RefreshDatabase` clear it with ours. Costs are `BigDecimal` with `RoundingMode::Unnecessary`, independent of our n$ code. `report-usage.spec.ts` asserts only today's row, via `toPass()` with reloads up to `POLLED_TERMINAL_TIMEOUT_MS` + margin × cache, so parallel specs (PE-014's `[no-webhooks]`) can't flake it.
- **Smoke and live checks (AC 17, D-58, D-76).** The production spec records every non-GET request and requires exactly `POST /login` and `POST /logout`; it creates no generation and buys nothing. Credentials come only from `E2E_REVIEWER_EMAIL`/`E2E_REVIEWER_PASSWORD`; no `expect()` runs while the password is in the form, and the production config has traces and video off. The live usage test sits in the `live` class behind the unchanged `LiveSmokeGuard` (D-58) and checks shape and day-sum only, never parity. Neither was run in the lane, as planned.
- **D-48 and decisions.** `PIXELFORGE_USAGE_TIMEOUT` is cleared in `phpunit.xml`'s `<env force>` and `<server>` in `00c11fa`, the commit whose config first reads it; `PIXELFORGE_USAGE_CACHE_SECONDS` likewise in `056ad87`. Only D-72 to D-76 were added, after D-65.
- **README final pass (AC 18).** Live URL, reviewer guide, how to read the report and its Match badge, stretch features and the D-01 to D-76 pointer are present and accurate. The only credentials are the documented local-only seeded accounts (PE-016); production passwords only ever come from `read -rs`. The PE-017 mentions named a section this branch doesn't have; they now point at the PE-017 story (#3).
- **Frontend.** Every amount goes through `MoneyAmount`, every count through `formatCount()`; non-zero deltas carry a sign and `data-nonzero`, not colour alone; AC 12 and AC 14 copy is verbatim; the table is labelled by its `h2` and scrolls inside its card.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | A cached usage entry that no longer parses (an earlier deploy's shape, a non-array, another range) threw `InvalidUsageReportException` past the report's fallback, so the page would 500 for up to the cache lifetime (review S2). | `app/Domain/Reporting/Actions/FetchPixelforgeUsageAction.php:35` | Warning | Fixed | On an unreadable hit the entry is forgotten and fetched again once; the parsed range must equal the asked range. New data-provider test `test_a_cached_entry_that_no_longer_reads_is_fetched_again` (three stale shapes): one request, the fresh answer returned and cached (`87825e9`). |
| 2 | The `cost_usd` refusal matrix didn't cover a `+` sign, leading or trailing whitespace, or a trailing newline. The parser already refused them. | `tests/Unit/Support/Pixelforge/DataTransferObjects/UsageReportDataTest.php:76` | Suggestion | Fixed | Four rows added (`48622a4`). |
| 3 | "Credit balances and Buy credits (PE-017) are described in their own section." names a section that doesn't exist on this branch, and the sign-off's Balance step relied on PE-017 without saying so (review S1). | `README.md:111`, `README.md:412` | Suggestion | Fixed | Both now link to the PE-017 story, which is true whichever PR merges first (`dcee296`). Once PE-017's README section lands, the orchestrator may swap the link for its anchor. |
| 4 | `FakeUsageReport` throws `InvalidArgumentException` or `RoundingNecessaryException` on a malformed fake row, which isn't a `ReportsPixelforgeContext`, so the local report would 500. | `app/Support/Pixelforge/Fake/FakeUsageReport.php:96` | Suggestion | Skipped | Only the fake writes those rows, always with integer usage and pinned rates; a throw there is a bug in the fake that should surface, not be hidden as "unavailable". It never runs in production. |
| 5 | `InvalidUsageReportException::invalidField()` repeats the offending scalar value, which could be long. | `app/Support/Pixelforge/Exceptions/InvalidUsageReportException.php:22` | Suggestion | Skipped | It is never logged: the HTTP driver wraps it as `previous` of `unreadableUsage()`, and the fallback logs only `context()`. The value is Pixelforge's data, not a secret. |
| 6 | The README Status row says all 18 stories are delivered while PE-017 is still in its lane. | `README.md:39` | Suggestion | Skipped | The plan's merge order is PE-016, PE-017, then this PR, so the row is true when this PR merges. |

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Targeted | `lane-make.sh l18 test FetchPixelforgeUsageActionTest`, `… test UsageReportDataTest`, `… test Reporting` | 7, 42 and 131 passed |
| Full | `lane-make.sh l18 check` | Exit 0. Pint clean (335 files), Larastan no errors, PHPUnit 1,978 passed (38,413 assertions), ESLint/Prettier/vue-tsc clean, Vitest 539 passed in 37 files |

No frontend, spec or worker-read code changed, so E2E was not re-run; QA's full run (48 passed, 2 skipped by design) stands. The `live` group and the production smoke stay with the orchestrator after merge (plan → Manual production steps), together with AC 15–17.

## Commits

- `87825e9` [PE-018] Fetch usage again when a cached entry no longer reads
- `48622a4` [PE-018] Refuse a signed or padded cost_usd in the usage parser tests
- `dcee296` [PE-018] Link the README's PE-017 mentions to its story
- `[PE-018] Record audit` (this file and the story status)
