# Audit — PE-016-operator-report

**Verdict: PASS**

Run on branch `pe-016-operator-report` from `fce88c1` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (round 2 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-016]` diff (`52e0515..fce88c1`, 59 files) against `story.md` AC 1–16, `plan.md`, both review rounds, QA and D-62 to D-65. That covers:
- the migration, `User`, `UserFactory::operator()`, `GrantOperatorAccessAction`, `GrantOperatorAccessCommand`, `OperatorUserSeeder`, `DatabaseSeeder` and their tests;
- the gate in `AppServiceProvider`, the `report` route, `HandleInertiaRequests`' `operator_navigation`, `ShowOperatorReportController`, `ShowOperatorReportRequest` and their tests;
- `ReportRange`, `ShowOperatorReportAction`, the three DTOs, the two `GenerationQueryBuilder` scopes, `GenerationFactory::providerCostOnly()` and their tests, the worked example and `BillingTypesDeclareNoFloatsTest`;
- `Show.vue`, the four `components/report/` files, `AppLayout.vue`, `paths.ts`, `types/report.ts`, `types/navigation.ts`, `inertia.d.ts`, their Vitest rows and `report.spec.ts`;
- the README (local accounts, Operator access, Reviewer guide), the plan's manual production steps and D-62 to D-65.

I also read the `generations` and `credit_ledger_entries` migrations, `TransitionGenerationAction`'s usage writes, `GenerationFactory::configure()`, `AuthenticatedUserResource`, `bootstrap/app.php`, `config/fortify.php`, `app/Actions/Fortify` and Laravel's `ValidatesAttributes::getDateTimeWithOptionalFormat`.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding.

## What was verified

- **Money exactness (AC 9–14, D-03, D-08, D-64).**
  - Every SQL sum is cast to `bigint` (`count(*)::bigint`, `coalesce(-sum(charges.amount_nanodollars), 0)::bigint`, `coalesce(sum(generations.provider_cost_nanodollars), 0)::bigint`), and `ShowOperatorReportAction::integer()` throws on any non-int. From there the figures are `Nanodollars`, whose `plus()` and `minus()` check overflow. There is no float, division or rounding anywhere in the path, and `BillingTypesDeclareNoFloatsTest` now scans `app/Domain/Reporting` too.
  - Spend is the left-joined ledger entry with `type = 'charge'` and the generation's id. `unique(generation_id, type)` makes double counting impossible. Holds, releases, `grant`, `purchase` and charges with no generation are never read (tested). I added a test that proves the generation's own `user_charge_nanodollars` column is never read either: a row whose column disagrees still shows its ledger charge (#1).
  - Profit is `spend − provider cost` through `Nanodollars::minus()`, per day, per user and overall, and is negative where D-09 rows outweigh charges.
  - `OperatorReportWorkedExampleTest` reproduces every AC 11, 13 and 14 number exactly, after checking each cost and charge through `CalculateProviderCostAction` and `CalculateUserChargeAction`. It also proves that the totals equal both the sum of the days and the sum of the users, and that each user's totals equal the sum of their single-day reports.
- **UTC bucketing (AC 8, D-10).**
  - `generations.completed_at` is `timestamptz` (`timestampTz('completed_at')`). On a `timestamptz`, `AT TIME ZONE 'UTC'` yields the UTC wall-clock `timestamp`, and `to_char` on a `timestamp` ignores the session's timezone. That is the right direction for this column type. On a plain `timestamp` the same expression would mean the opposite, which is not the case here.
  - The range filter compares `completed_at` with instants bound with an explicit `+00:00` offset (`GenerationQueryBuilder::instant()`), so it doesn't depend on the session either.
  - `ShowOperatorReportActionTest` runs both AC 8 examples under `SET TIME ZONE` and PHP's default timezone set to `Pacific/Kiritimati` (UTC+14) and `Pacific/Pago_Pago` (UTC−11), and the range edges under UTC+14. **Mutation check:** I replaced the expression with `to_char(generations.completed_at, 'YYYY-MM-DD')`, and both timezone rows failed. Then I restored the file. So the test fails whenever the bucketing follows the session's timezone.
  - The billed filter is `output_tokens IS NOT NULL`. `TransitionGenerationAction` writes usage only from a succeeded snapshot, either on success or through `recordProviderCostOnly()` (D-09), and writes `FREE` costs on every failure. So D-09 rows count, and generations without recorded usage never do (tested: provider-failed, locally failed, pending, queued and processing).
- **Authorization (AC 1, 2, D-62, D-63).**
  - The route is `auth`, then `can:view-operator-report`, then the Form Request. A guest is redirected, and a non-operator gets 403 before validation runs, even with an invalid range (tested).
  - `operator_navigation` is a lazy closure through the same gate, and it is `[]` for guests and non-operators. Their page JSON never contains `/report` (tested). The report's props (emails, totals) are rendered only by the controller, which a non-operator never reaches. `AuthenticatedUserResource` exposes only id, name and email, so `is_operator` never reaches any page.
  - `is_operator` is not in `#[Fillable]` and has a `false` default in both the column and `$attributes`. Nothing in `app/` calls `unguard`, or `forceFill` on users. Fortify enables only registration, which `RegistrationTest` covers with `is_operator=1`, and there is no profile-update route. `GrantOperatorAccessAction` is the only writer.
  - The 403 is Laravel's stock `errors::403` view, because `bootstrap/app.php` has only the 419 `respond()` callback. It shows "403 | You don't have access to this page." and nothing else, also on an Inertia visit (tested).
- **Range rules (AC 5, 6, D-65).** Strict `Y-m-d` dates parsed as UTC midnights, inclusive, 30 days by default, at most 92, never past today (UTC). The rules run before any query. `ShowOperatorReportValidationTest` gives field errors, never a 500, for: end before start, 92 vs 93 days (with no query reading `generations`), a future end (including at 23:59:59Z under UTC+14), `2026-02-30`, an unpadded date, a date-time, a word, an array, and one date missing. Laravel parses `before_or_equal` with `'!' . format`, so the today comparison is deterministic.
- **Query shape.** There is one aggregate query per range, grouped by UTC date, user id and email, with no per-row lookups. I added a test that counts the queries: exactly one for a three-day range with four users (#2). The days are zero-filled from `ReportRange::dates()` and keyed by UTC date in `OperatorReportData::$days`. That is what PE-018 needs. No public class, prop or route name changed.
- **Operations.**
  - `app:grant-operator` trims and lowercases the email. It exits 1 with nothing written on an unknown email, never creates a user or touches a password, and exits 0 on a repeat run without writing (tested). It is safe on production: no prompt and no other side effect, and its one log line holds the email and the id.
  - `OperatorUserSeeder` throws in production before writing anything (tested).
  - The README's Operator access block and the plan's manual steps 1–5 are copy-pasteable. The `$E2E_REVIEWER_EMAIL` and `$OPERATOR_EMAIL` variables expand locally inside the double-quoted `ssh` command. The `tinker` check's `\"` and `\M` reach the remote shell as `"` and `\M` inside single quotes. Neither block holds a password or a secret, and the local operator's documented password exists only in the README's local accounts table and the seeder, which refuses production.
- **D-48 rule.** The story reads no new env name, so `phpunit.xml`, `.env.example` and the compose anchors are untouched. The decisions are D-62 to D-65 only.
- **Frontend.** There is no `any`. Money goes only through `MoneyAmount` and `money.ts`. A negative amount gets the minus sign, medium weight and red, so it doesn't rely on colour alone. The TS types document the `Number.MAX_SAFE_INTEGER` bound.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | No test proved spend comes from the ledger rather than the generation's `user_charge_nanodollars` column. The factory writes both from the same value, so switching the query to sum the column would pass every existing test. | `tests/Feature/Reporting/ShowOperatorReportActionTest.php` | Suggestion | Fixed | New `test_spend_comes_from_the_ledger_not_the_generations_charge_column` sets the column to 1 n$ with a raw update and still expects the ledger charge (`e81340a`). |
| 2 | Nothing pinned "one aggregate query per range". A later per-user or per-day lookup would have passed silently. | `tests/Feature/Reporting/ShowOperatorReportActionTest.php` | Suggestion | Fixed | New `test_one_query_reads_the_whole_range` asserts exactly one query, reading `generations`, for three days and four users, D-09 included (`e81340a`). |
| 3 | The `operator_navigation` comment said a non-operator "never learns the URL", but `paths.ts` ships `/report` in every bundle. The URL isn't secret, and the gate protects the page, but the comment invited relying on obscurity. | `app/Http/Middleware/HandleInertiaRequests.php:44` | Suggestion | Fixed | The comment now says non-operators are never shown the link, and that the gate, not the URL, protects the report (`f9b4394`). |
| 4 | `after()` used `if … elseif` for the order and span errors (review round 2 suggestion). | `app/Http/Requests/Reporting/ShowOperatorReportRequest.php:86` | Suggestion | Fixed | Two guarded blocks, with an early return after the order error (`034e2bf`). `ShowOperatorReportValidationTest` passes unchanged (18 tests). |
| 5 | `228fec0`'s subject is 73 characters, one over the limit (review W2). | git history | Warning | Skipped | Fixing it would mean rewording a commit, which the pipeline forbids. The user can reword it when merging, for example to `[PE-016] Take the report request's date format and today from the range`. |
| 6 | A non-operator's document load of `/report` logs a browser "Failed to load resource: … 403" console message (QA observation). | `tests/e2e/report.spec.ts:23` | Suggestion | Skipped | It is the browser reporting the intended 403 status, not an app error. The E2E already uses `page.request` so that it doesn't log. |
| 7 | `completed_at` has no index, so the report scans `generations`. | `database/migrations/2026_11_19_151446_create_generations_table.php:48` | Suggestion | Deferred | Accepted in plan → Risks: production is capped at 500 generations a day. PE-018 can add a partial index if `EXPLAIN` shows the need. |

No Critical issues were found. The one Warning (#5) is a history-only item the pipeline can't fix and is left for the merge.

## Checks after fixes

Run on `034e2bf`, after the last fix commit:

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `lane-make.sh l16 check` | Exit 0. Pint PASS (312 files). Larastan no errors. PHPUnit 1,821 passed (37,515 assertions), 2 more than QA's 1,819. Vitest 528 passed in 36 files. |
| Report E2E, desktop and mobile | `lane-make.sh l16 e2e "operator sees"` | Exit 0. 2 passed. |
| Mutation check of the UTC bucketing | `test_a_generation_counts_on_the_utc_date_it_completed` with the `AT TIME ZONE 'UTC'` removed | 2 failed as expected, then the file was restored. |

No action, command or queued code changed, so the worker needed no restart.

## Commits

- `e81340a` [PE-016] Prove spend reads the ledger in one query per range
- `f9b4394` [PE-016] Say the gate, not a hidden URL, guards the report
- `034e2bf` [PE-016] Return early from the range order check instead of elseif
- `[PE-016] Record audit` (this file and the story status)
