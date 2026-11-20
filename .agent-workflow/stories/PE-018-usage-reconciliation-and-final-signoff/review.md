# Review — PE-018-usage-reconciliation-and-final-signoff

## Round 1

**Verdict: REQUEST CHANGES**

Scope: the 20 `[PE-018]` commits `365ef5a..b68739a` on `pe-018-usage-reconciliation-and-final-signoff`, checked against `story.md` and `plan.md`. `origin/main..HEAD` holds only `[PE-018]` commits. They are the plan, 17 task commits, one follow-up UI commit (`5582acc`) and the bookkeeping. All 17 plan tasks are checked.

Checks run on HEAD (`b68739a`) with `lane-make.sh l18`:
- `check`: Pint is clean (335 files), and so is Larastan. PHPUnit passes 1,971 tests (38,403 assertions). ESLint, Prettier and vue-tsc are clean. Vitest passes 539 tests in 37 files.
- `e2e "operator"`: 4 passed. `report-usage.spec.ts` and PE-016's `report.spec.ts` pass on desktop and on mobile.
- I did not run the `live` group or `tests/smoke/production.spec.ts`. The plan leaves both to the orchestrator.

There is no Critical finding. One Warning must change, and two optional Suggestions follow.

### Summary

The implementation follows the plan class by class. D-72 to D-76 are appended after D-65 and clash with no other number.

- **Contract and parsing (AC 1, 2, D-73):**
  - `UsageReportData::from()` reads strictly:
    - `object` must be `usage`;
    - the range goes through `ReportRange::fromDates()`;
    - `data` must be a list with each date exactly once (an unexpected date, a repeat or a missing date throws);
    - the total must be an object.
  - `UsageRowData` requires `is_int` and ≥ 0 for every count. It refuses a leading `-` before `Nanodollars::fromUsdString()`, so `-0.000000000` is refused too, and the parser refuses floats, exponents and more than 9 decimals.
  - The `InvalidUsageReportException` messages name the path and never repeat the body.
  - `UsageReportDataTest` covers the four worked values and the whole refusal matrix.
  - `HttpPixelforgeClient::retrieveUsage()`:
    - with no key, it throws before anything is sent;
    - it replaces the request timeout with `usage.timeout_seconds` (Guzzle's total timeout bounds the connect too);
    - a non-2xx answer goes through the shared `errorFrom()`;
    - a 2xx for another range, or one it can't read, becomes `unreadableUsage()`, with the cause kept.
  - `DateRangeTooLong` reaches `tryFromResponse()`, `httpStatus()` and the test helper. At create, `SubmitAttemptOutcome::fromFailure()` maps it to `Ambiguous`, which keeps the match exhaustive.
  - `ThrottledPixelforgeClient::retrieveUsage()` takes one unit before it delegates. The tests prove that a spent budget sends nothing.
- **Fake (AC 3, D-75):**
  - `FakeUsageReport` sums the succeeded snapshots by the date in `completed_at` (the first 10 characters of the text), zero days included.
  - Costs are computed in `BigDecimal` with `RoundingMode::Unnecessary` and written with `toScale(9)`, never through our n$ code, so `FakeUsageParityTest` is an independent check.
  - The fake answers more than 92 days with the typed 422, and a bad date or an inverted range with `ValidationFailedException`.
  - The state lives in the app database (D-34), so no reset step is needed.
- **Recorded side and comparison (AC 4–10, D-72):**
  - `SumRecordedUsageAction` is a sibling of the PE-016 query. It uses the same `billedByPixelforge()->completedBetween()` scopes and the now-public `UTC_DATE`, casts every sum to bigint, refuses non-int figures and zero-fills the range newest first.
  - `UsageFigures::matches()` compares exactly the five figures and ignores counts. `minus()` is Pixelforge − ours, through `Nanodollars::minus()`.
  - `UsageComparisonData::compared()` compares the total with Pixelforge's own `total`, not with the sum of its rows. It refuses a report for another range. `has_mismatch` covers the days and the total.
  - In progress is `date === today` (UTC). `UsageComparisonDataTest` and `ShowOperatorReportUsageTest` reproduce AC 6, 7 and 8 to the n$. They also cover only today in progress, zero days and integer- and boolean-only props.
- **Range, cache and failure (AC 11–14, D-74):**
  - A 93-day range is redirected by PE-016's request with `Http::assertNothingSent()`.
  - `FetchPixelforgeUsageAction` caches `toArray()` under `pixelforge-usage:{start}:{end}` and parses it again on a hit. It is tested on the `database` store, as in production: a hit takes no unit, the entry expires after 61 s, each range is its own entry, and a failure is not cached.
  - `CompareProviderUsageAction` catches `DateRangeTooLongException` first, then any `ReportsPixelforgeContext`. Every exception the contract throws implements that interface. It logs one warning with the range and `context()`. The failure matrix (timeout, HTML 500, 503, 429, 401, unreadable 200, spent budget, no key) proves that our figures stay, that no exception escapes and that neither the sentinel key nor `Authorization` reaches a log.
- **Page (AC 5–14 UI):**
  - `UsageComparisonTable` and `UsageComparisonCells` format every amount through `MoneyAmount` and every count through `formatCount()`.
  - A non-zero delta gets `data-nonzero`, red and a `+` or `−` sign, so it never relies on colour alone.
  - While Pixelforge's figures are missing, its cells, the deltas and the status read "—", with no badge.
  - The AC 12 and AC 14 copy is verbatim.
  - The table is `aria-labelledby` its `h2`, and only its own box scrolls sideways. The E2E checks 375 px.
  - Extracting `UsageComparisonCells.vue`, which the plan doesn't list, is a sound change: the day rows and the Total row share one cell template.
- **Sign-off support (AC 15–18):**
  - The live usage test sums the days against the total and never compares them with our records.
  - The production smoke records every non-GET request and requires exactly `POST /login` and `POST /logout`. The frontend has no other non-GET call on these pages: `AppLayout` only posts the logout.
  - The README covers the reconciliation, how to read the report, the stretch features, D-01 to D-76 and the demo refresh runbook, with no credentials.

Security:
- The report stays behind PE-016's gate.
- The new raw SQL fragments are constants.
- The usage call sends only two validated dates.
- No key, header or body reaches a log or an exception message, and the tests assert it.

Performance: one aggregate query is added. The synchronous call is bounded at 5 s, and a success is cached for 60 s.

The constant-field check finds no constant field in the `usage` prop. `availability` varies with Pixelforge's answer.

### Commit hygiene

- Every task commit carries its code with its tests. The commit that changes a shared helper (`InteractsWithPixelforgeApi`, `ThrottledPixelforgeClientTest`'s double, `LiveSmokeGuard`, `Show.test.ts`'s `mountShow()`) is the commit that needs it.
- The plan (`365ef5a`), the decisions (`28f7957`) and the bookkeeping (`b68739a`) each have their own commit. D-76 sits with the smoke it describes (`43d4566`), as the plan says.
- `5582acc` is a self-contained layout follow-up to `be99c30`, with a body that explains why.
- All 20 subjects start with `[PE-018]`, are imperative and are at most 72 characters, and every message carries the `Co-Authored-By` trailer.
- The cut point after task 14 (`28f7957`) is clean.

### Warnings

- [x] **W1. The README's Status table still says the product is planned, not delivered (AC 18).**
  - `README.md:39` reads "Product features | Planned as 18 stories (below); each is delivered as one reviewable PR".
  - This PR is the last of the 18, and the story calls it the README's "final pass" for hand-in. A grader who reads the Status table first is told the features are only planned. The table sits right above the list of stories that the rest of the README describes as live.
  - **Fix:** change the row to "Delivered as 18 stories (below), each as one reviewable PR". `README.md:38` says "Done as stacked PRs, to merge in order: #3 → #2 → …". If those bootstrap PRs are merged, drop "to merge in order" in the same edit. Use one commit, for example `[PE-018] Mark the product features delivered in the README`.

### Suggestions

- **S1. The PE-017 pointer in "Stretch features" names a section without linking it.** `README.md:111` says "Credit balances and Buy credits (PE-017) are described in their own section." That section doesn't exist on this branch yet, because PE-017 is built in a parallel lane. Once this branch is rebased onto a `main` that has PE-017, turn the sentence into a link to that section's anchor, so it can't go stale unnoticed.
- **S2. A corrupted cache entry would surface as a 500.** On a hit, `FetchPixelforgeUsageAction::execute()` (`app/Domain/Reporting/Actions/FetchPixelforgeUsageAction.php:45`) re-parses the stored array. An `InvalidUsageReportException` there is not a `ReportsPixelforgeContext`, so it bypasses the fallback in `CompareProviderUsageAction`. Today only our own `toArray()` writes that key, so this is a bug path, as documented. If the docs-shaped array ever changes between deploys, though, a 60 s-old entry would fail the page. A version segment in the key (`pixelforge-usage:v1:{start}:{end}`) would make that impossible at no cost. This is optional.
