# Plan — PE-016-operator-report

## Approach
A non-fillable `users.is_operator` flag is granted only by an idempotent `app:grant-operator` command, or locally by a seeder. A gate protects `GET /report` and a shared `operator_navigation` prop, and everyone else gets the stock 403 page with the gate's message. `ShowOperatorReportAction` takes one `ReportRange`, which is UTC and inclusive, at most 92 days, and uses the same param names as `/v1/usage`. It runs one aggregate query over generations Pixelforge billed, bucketed by the UTC date of `completed_at`, with spend taken from their user `charge` ledger entries only. It then folds the rows into zero-filled days keyed by date, per-user totals, and totals that are the sum of the days, so PE-018 can add Pixelforge's per-day figures beside ours by date.

## Tasks
Each task is one TDD commit. Write the failing test first, then the code, and get `make check` green before committing.
- The story adds no config (D-65), so `phpunit.xml`, `.env.example` and the `x-dev-bounds` anchor stay untouched.
- After task 1 (migration), run `make migrate`. After task 2, run `make seed`, so the dev database has the operator that E2E signs in as.
- Tasks 3, 4, 10 and 11 use the `tailwindcss-development` skill and follow the existing zinc / red visual language. There is no dark mode. Check each at 375 px.
- Before `make e2e` (task 12), run `make restart-worker`, because tasks 1 and 6 add a command and an action.

Tasks 1–4 are the story's PE-016a: the flag, the grant command, the seeder, the gate with its 403, and the nav prop. Tasks 5–13 are PE-016b: the range, the query, the page, the E2E spec and the docs. The cut point is after task 4 (see Size).

- [x] 1. Add migration `add_is_operator_to_users_table` (Data model). Give `User` an `is_operator` boolean cast and an `@property bool $is_operator`, and keep it out of `#[Fillable]`. Add the `UserFactory::operator()` state, `GrantOperatorAccessAction` and `GrantOperatorAccessCommand` (Backend). Test-first: `GrantOperatorAccessCommandTest`, plus rows in `UserTest` (defaults to false, ignored by mass assignment) and `RegistrationTest` (posting `is_operator=1` creates a non-operator). Commit: `[PE-016] Grant operator access by email with an idempotent command`
- [x] 2. Add `OperatorUserSeeder` (Backend), called by `DatabaseSeeder` after `DemoUserSeeder`. In the same commit, change `DemoUserSeederTest::test_seeding_twice…` to read the demo user by email instead of `sole()`, and add the operator's row to the README's local accounts table. Test-first: `OperatorUserSeederTest`. Commit: `[PE-016] Seed a local operator with a documented local-only password`
- [x] 3. Add `ShowOperatorReportController`, which renders `Reporting/Show` with no props yet, and its `ABILITY` and `FORBIDDEN_MESSAGE` constants. Define the gate in `AppServiceProvider::boot()` and add the `report` route behind `auth` and `can:` (Backend). Add the page shell `pages/Reporting/Show.vue` (Frontend). Test-first: `ShowOperatorReportAccessTest` and `Show.test.ts` (the heading). Commit: `[PE-016] Open the report page to operators only`
- [x] 4. Share `operator_navigation` from `HandleInertiaRequests` and add `types/navigation.ts` and its key in `inertia.d.ts`. `AppLayout.vue` appends those links after History. In `tests/js/integration/support/inertia.ts`, default the key to `[]` and add `givenOperatorNavigation()`, in this same commit, because every test that mounts AppLayout reads the key. Test-first: `OperatorNavigationPropTest` and rows in `AppLayout.test.ts`. Commit: `[PE-016] Show the Report link to operators only`
- [x] 5. Add the `ReportRange` value object with `MAX_DAYS`, `DEFAULT_DAYS`, `defaultFor()`, `fromDates()`, `inclusiveDayCount()`, `startsAt()`, `endsBefore()` and `dates()` (Backend). Test-first: `ReportRangeTest`. Commit: `[PE-016] Define the report's UTC date range`
- [x] 6. Add the scopes `billedByPixelforge()` and `completedBetween()` to `GenerationQueryBuilder`, and the `GenerationFactory::providerCostOnly()` state. Add the DTOs `ReportFigures`, `ReportUserFigures` and `OperatorReportData`, and `ShowOperatorReportAction` (Backend). Widen `BillingTypesDeclareNoFloatsTest`'s scan to `app/Domain/Reporting`. Test-first: `ShowOperatorReportActionTest`, plus rows in `GenerationQueryBuilderTest` and in `GenerationFactoryTest`'s ledger-consistency provider. Commit: `[PE-016] Sum spend, provider cost and profit per UTC completion day`
- [x] 7. Add `OperatorReportWorkedExampleTest` (AC 11, 13, 14). No production change is expected. Commit: `[PE-016] Prove the worked example's daily, per-user and overall totals`
- [x] 8. Add `ReportRange::toArray()` and `ReportRange::limitsFor()`. The controller renders the default range's `range`, `limits` and `report` props. Add `types/report.ts`, and type the page's props, with the shown range in the header description. Test-first: `ShowOperatorReportPageTest` and a `Show.test.ts` row. Commit: `[PE-016] Pass the default range's report to the page`
- [x] 9. Add `ShowOperatorReportRequest` with its rules, messages, `after()` and `range()`. The controller takes it in place of the default range. Test-first: `ShowOperatorReportValidationTest`. Commit: `[PE-016] Validate the report range with field errors`
- [x] 10. Add `paths.report` and `components/report/ReportRangeForm.vue`, and wire the form into the page. Add a GET-visit helper beside `givenVisitsFailWith()` in the test support. Test-first: `ReportRangeForm.test.ts`. Commit: `[PE-016] Choose the report range on the page`
- [x] 11. Add `ReportAmount.vue`, `ReportSummary.vue` and `ReportTable.vue` in `components/report/`. The page renders the summary, the daily table (with its total and the D-09 note) and the per-user table (with its empty state). Test-first: `ReportTable.test.ts` and `Show.test.ts` rows. Commit: `[PE-016] Show the report's totals, daily and per-user tables`
- [x] 12. Add `OPERATOR_USER` to `tests/e2e/support/auth.ts`, mirroring the seeder, and add `tests/e2e/report.spec.ts` (Tests). Run the full `make e2e`. Commit: `[PE-016] Cover the operator report end to end`
- [x] 13. Append D-62 to D-65 to `requirements.md` §5, after D-61. In the README, add the "Operator access" section and one Reviewer guide bullet (README). Commit: `[PE-016] Record the operator report decisions and runbook`

## Story clarifications
- **PR size.** The story asks for a split into PE-016a and PE-016b. The project rule wins: one PR, with that split as the cut point after task 4.
- **Decision numbers.** New rows are D-62 to D-65, and D-66 stays unused. Task 13 records them, so the plan commit touches only `plan.md` and `story.md`.
- **Billed by Pixelforge.** A generation counts as billed when its usage is recorded (`output_tokens` is not null). The transition action writes usage only from a provider-`succeeded` snapshot: on a normal success, or on D-09's provider-cost-only write onto a row that failed locally. Provider failures and local failures record no usage, so they fall out of the query and contribute 0 everywhere, including the count.
- **Spend.**
  - Spend is the negated amount of the generation's ledger entry of type `charge`, joined by `generation_id`, which is unique per type.
  - Holds, releases, any other type (PE-017's grants and purchases), and entries without a generation are never read.
  - A D-09 row has no charge, so its spend is 0.
  - The entry's own `created_at` is never read.
- **Provider cost and profit.**
  - Provider cost is the generation's `provider_cost_nanodollars`.
  - Profit is spend − provider cost, through `Nanodollars::minus()`, and it may be negative.
  - Every sum is cast to bigint in SQL, because Postgres sums a bigint as numeric and PDO would return a string. A non-int value is a bug and throws.
- **Bucketing (D-10).**
  - The day key is `completed_at` converted explicitly to UTC, then formatted as `YYYY-MM-DD`. Never `date_trunc` or a `::date` cast on the `timestamptz`, because both follow the session timezone.
  - The range filter is `completed_at` ≥ the start's UTC midnight and < the UTC midnight after the end, bound with offsets as `GenerationQueryBuilder::instant()` binds them.
- **Shape.**
  - The days list every date of the range, newest first, keyed by date in PHP.
  - The users are those with at least one billed generation in the range, D-09 rows included, sorted by spend descending, then email ascending.
  - The totals are the sum of the days. The tests prove they also equal the sum of the users.
- **Range (D-65).**
  - The params are `start_date` and `end_date`, the same names `/v1/usage` takes.
  - With neither param, the range is the last 30 UTC days ending today. With one, the other is required.
  - The dates are strict `Y-m-d`, parsed as UTC midnights, never through the app timezone.
  - The end must not be after today (UTC), the start must not be after the end, and the range spans at most 92 days inclusive.
  - Order and span are checked in `after()`, only once both dates are valid, so a bad end never produces a spurious order error.
- **A failed validation** redirects to `route('report')` (the default range) with field errors. It never uses `url()->previous()`, which could land on another page. The form's GET keeps its typed values (`preserveState`), so the error shows beside what was typed. A full page load of an invalid URL shows the default range with the error. The controller never runs, so no report query runs.
- **403 (D-63).** The gate denies with "You don't have access to this page.", and Laravel's stock `errors::403` view renders it as "403 | You don't have access to this page.", like the app's generic 404 and 500 pages. `auth` runs before `can:`, so a guest is sent to Sign in, and `can:` runs before the Form Request, so a non-operator with a bad range still gets 403.
- **Operator access (D-62).** `is_operator` is never mass-assignable, so registration and `app:upsert-user` can't set it. The grant command never creates users or touches passwords. Revoking access is out of scope.
- **Money display.** Amounts go through `MoneyAmount` and `money.ts` only. `money.ts` already formats negatives (`-$0.0175`, exact `-$0.017480000`), and `money.test.ts` covers that, so it is unchanged. The story's conditional JS unit layer does not apply.
- **Not colour alone.** A negative amount keeps money.ts's minus sign and is set in medium weight, and it is also red-700.
- **Copy.**
  - Header: title "Report", h1 "Operator report", description "Spend, Pixelforge cost and profit per UTC day, from {start} to {end}."
  - Range form: From and To, the hint "UTC dates, inclusive, up to 92 days.", and Apply.
  - Range errors:
    - "Enter a start date." / "Enter an end date.";
    - "Enter a valid date.";
    - "The start date must be on or before the end date.";
    - "The end date can't be after today (UTC).";
    - "The range can be at most 92 days.", under To.
  - Summary: Succeeded, Spend, Provider cost, Profit.
  - Daily table: caption "Per day (by Pixelforge completion date, UTC)", footer "Total", and the note "Succeeded counts every generation Pixelforge billed, including any we failed locally and never charged."
  - Per-user table: caption "Per user", and the empty state "No billed generations in this range."
  - Grant command:
    - "Granted operator access to {email}.";
    - "{email} is already an operator.";
    - "No user has the email {email}. This command never creates users: create the account first (README, "Reviewer account")."
- **PE-018 hooks.** `ReportRange` is the one query object per range. `OperatorReportData::$days` is keyed by UTC date. The param names match `/v1/usage`. PE-018 adds its own action for the same range and merges by date key, without changing this query.

## Data model
| Column | Type | Written by | Read by | Task |
| --- | --- | --- | --- | --- |
| `users.is_operator` | boolean, not null, default false; not fillable | `GrantOperatorAccessAction` | the gate (`HandleInertiaRequests`, the `report` route) | 1 |

No index is added (see Risks). The migration is additive with a constant default, so it is instant on Postgres, and every existing production user reads as a non-operator.

## Backend
All classes are `final`, declare `strict_types` and have explicit types.

| Class / file | Location | Role |
| --- | --- | --- |
| `User` | `app/Models/` | Task 1: the `is_operator` cast and `@property`, not fillable. |
| `UserFactory::operator()` | `database/factories/` | Sets `is_operator`. Consumers: the tests of tasks 1, 3, 4, 8 and 9. |
| `GrantOperatorAccessAction` | `app/Domain/Reporting/Actions/` | `execute(User): bool`: sets the flag by plain assignment and saves. It returns true when the flag changed and false when the user was already an operator. Consumers: the command and the seeder. |
| `GrantOperatorAccessCommand` | `app/Console/Commands/` | `app:grant-operator {email}` with `#[Signature]` and `#[Description]`:<br>- trims and lowercases the email (D-18);<br>- an unknown email → error copy and `FAILURE`, with nothing written;<br>- otherwise the action, its copy, and one info log (email, user id), then `SUCCESS` both times. |
| `OperatorUserSeeder` | `database/seeders/` | `EMAIL` `operator@promptedit.test`, `NAME` `Operator`, `PASSWORD` `promptedit-operator`. It refuses production with a `RuntimeException`, like `DemoUserSeeder`, then `updateOrCreate`s the name and password and runs `GrantOperatorAccessAction`. |
| `ShowOperatorReportController` | `app/Http/Controllers/Reporting/` | Invokable. `ABILITY` (`view-operator-report`) and `FORBIDDEN_MESSAGE`.<br>- Task 3 renders `Reporting/Show`.<br>- Task 8 adds `range`, `limits` (`ReportRange::limitsFor(now)`) and `report` (`ShowOperatorReportAction` → `toArray()`).<br>- Task 9 takes `ShowOperatorReportRequest::range()`. |
| `AppServiceProvider` | `app/Providers/` | Task 3: one call in `boot()` to a private method that defines `ABILITY`: allow when `is_operator`, else `Response::deny(FORBIDDEN_MESSAGE)`. |
| `routes/web.php` | `routes/` | Task 3: `GET /report`, named `report`, inside the `auth` group, with `can:` + `ABILITY`. |
| `HandleInertiaRequests` | `app/Http/Middleware/` | Task 4: one new lazy key, `operator_navigation`. For a user who passes the gate it is one link, label `Report`, href `route('report', absolute: false)`; for anyone else it is `[]`. |
| `ShowOperatorReportRequest` | `app/Http/Requests/Reporting/` | Task 9:<br>- `authorize()` is true, because the route's `can:` already ran;<br>- the rules are `required_with`, `string` and `date_format:Y-m-d` on both fields, plus end ≤ today (UTC);<br>- `after()` checks order and span via `ReportRange::inclusiveDayCount()`;<br>- the messages are in Copy;<br>- `$redirectRoute = 'report'`;<br>- `range(): ReportRange` builds the validated range, or the default. |
| `ReportRange` | `app/Domain/Reporting/ValueObjects/` | `final readonly`. `MAX_DAYS` = 92 and `DEFAULT_DAYS` = 30 (D-65).<br>- Task 5:<br>  - `defaultFor(CarbonImmutable $now)`: now's UTC date and the 29 days before it;<br>  - `fromDates(string, string)`: strict `Y-m-d` in UTC; throws `InvalidArgumentException` on a bad date, start > end or a span above `MAX_DAYS`, a guard the request makes unreachable;<br>  - `inclusiveDayCount(string, string)`;<br>  - `startsAt()` and `endsBefore()`: UTC midnights;<br>  - `dates()`: `Y-m-d`, newest first.<br>- Task 8:<br>  - `toArray()`: `start_date`, `end_date`;<br>  - static `limitsFor(CarbonImmutable $now)`: `max_days`, `today`. |
| `GenerationQueryBuilder` | `app/Domain/Generation/QueryBuilders/` | Task 6:<br>- `billedByPixelforge()`: usage recorded (Story clarifications);<br>- `completedBetween(CarbonImmutable $from, CarbonImmutable $before)`: from inclusive, before exclusive, through `instant()`.<br>Consumer: the action. |
| `GenerationFactory::providerCostOnly()` | `database/factories/` | A D-09 row:<br>- failed locally as `idempotency_key_reused`, with no provider id;<br>- the `succeeded()` state's usage and rates, provider cost 87,450,000, user charge 0, and a `completed_at`;<br>- `afterCreating` already writes a hold and a release only.<br>Consumers: the tests of tasks 6 and 7. |
| `ReportFigures` | `app/Domain/Reporting/DataTransferObjects/` | `final readonly`: `int $succeeded`, `Nanodollars $spend`, `Nanodollars $providerCost`.<br>- `zero()`, `plus(self)`, `profit()` (spend − cost);<br>- `toArray()`: `succeeded`, `spend_nanodollars`, `provider_cost_nanodollars`, `profit_nanodollars`, all ints. |
| `ReportUserFigures` | same | `int $userId`, `string $email`, `ReportFigures $figures`. `toArray()`: `user_id`, `email` and the figures. |
| `OperatorReportData` | same | `ReportRange $range`, `array<string, ReportFigures> $days` (every date, newest first) and `list<ReportUserFigures> $users` (sorted).<br>- `totals()` folds the days;<br>- `toArray()`: `days` (a list of `date` + figures), `users` and `totals`. |
| `ShowOperatorReportAction` | `app/Domain/Reporting/Actions/` | `execute(ReportRange): OperatorReportData`:<br>- runs one base-builder query: `billedByPixelforge()->completedBetween()`, a left join on the generation's `charge` entry, a join on `users`, grouped by UTC day key, user id and email;<br>- selects the count, the negated summed charge and the summed provider cost, each cast to bigint;<br>- folds the rows into `dates()`'s zero-filled days and a per-user map, then sorts the users.<br>The trio shares the `ShowOperatorReport` prefix (name parity). |

## Frontend
`<script setup lang="ts">` throughout, with no `any`. Money is formatted only through `MoneyAmount`, and counts through `formatCount()`.

| File | Role |
| --- | --- |
| `types/navigation.ts` | Task 4: `NavigationLink { label; href }`. |
| `types/inertia.d.ts` | Task 4: `operator_navigation: NavigationLink[]` in `sharedPageProps`, documented as empty for everyone but operators. |
| `layouts/AppLayout.vue` | Task 4: the nav renders the static items, then `page.props.operator_navigation`, with the same classes and `aria-current`. No other change. |
| `types/report.ts` | Task 8: `ReportRange`, `ReportLimits`, `ReportFigures`, `ReportDay` (`date` + figures), `ReportUser` (`user_id`, `email` + figures) and `OperatorReport`. A note says integer n$ is exact up to `Number.MAX_SAFE_INTEGER` (≈ $9.0M), which is ample. |
| `pages/Reporting/Show.vue` | Thin.<br>- Task 3: `Head` "Report", `AppLayout`, `PageHeader`.<br>- Task 8: typed props, and the range in the description.<br>- Task 10: `ReportRangeForm`.<br>- Task 11: `ReportSummary` and two `ReportTable`s, inside `gap-8`, like History. |
| `lib/paths.ts` | Task 10: `report: '/report'`. |
| `components/report/ReportRangeForm.vue` | Task 10:<br>- `useForm` with `start_date` and `end_date` from the `range` prop;<br>- `Field` + `TextInput type="date"`, with `max` = `limits.today`;<br>- Apply runs `form.get(paths.report)` with `preserveState` and `preserveScroll`, and is busy while processing;<br>- each field shows its error;<br>- a stacked column on a phone, one row from `sm`. |
| `components/report/ReportAmount.vue` | Task 11: wraps `MoneyAmount` (`align="end"`). A negative amount gets `data-negative`, `font-medium` and `text-red-700`. |
| `components/report/ReportSummary.vue` | Task 11: a `dl` of Succeeded, Spend, Provider cost and Profit from `totals`, in a white `rounded-xl` card: 2 × 2 on a phone, 4 across from `sm`. |
| `components/report/ReportTable.vue` | Task 11. Props:<br>- `caption`, `labelHeader`;<br>- `rows` (`key`, `label`, figures);<br>- optional `totals`, `note` and `emptyText`.<br>Layout:<br>- a captioned `table` in a white `rounded-xl` card, inside `overflow-x-auto`, so the page never scrolls sideways;<br>- numeric columns are right-aligned and `tabular-nums`;<br>- cells carry `data-succeeded`, `data-spend`, `data-provider-cost` and `data-profit`;<br>- the user label truncates;<br>- the `tfoot` shows totals when given;<br>- with no rows, a single row shows `emptyText`.<br>The `thead` row stays above the first body row, so that row's tooltip stays inside the scroll box. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-62 | Operator access is one boolean, `users.is_operator`, that is not fillable. It is granted only by the idempotent `app:grant-operator <email>`, which never creates users or touches passwords, and locally by `OperatorUserSeeder`, which refuses production. There are no roles and no UI | One role with one reader needs no roles table, and the gate stays one comparison. Keeping it unfillable means registration and `app:upsert-user` can never set it. Production grants are deliberate, by hand after deploy, and visible in the log. |
| D-63 | The `view-operator-report` gate protects `GET /report` and the shared `operator_navigation` prop, which is `[]` for everyone else. A non-operator gets Laravel's stock 403 page carrying the gate's message, "You don't have access to this page.", not an Inertia error page | It matches the app's generic 404 and 500 pages. `RedirectExpiredSession` is the app's one `respond()` callback, and `Inertia::handleExceptionsUsing()` would replace it. Non-operators never receive the link, so only a typed URL, which is a full page load, reaches the 403. |
| D-64 | The report counts a generation when Pixelforge billed it (its usage is recorded, D-09 rows included), on the UTC date of its `completed_at`. Spend is only the negated user `charge` ledger entries of those generations. Provider cost is the generation's own, and profit is spend − cost and may be negative. One query groups by UTC day and user, and PHP zero-fills the days and sums the users and totals from the same rows | It matches how `/v1/usage` buckets and bills (D-10), whatever the session or app timezone. The ledger stays the source of spend (D-08), and naming `charge` keeps PE-017's grants and purchases out. One set of rows makes the day, user and overall totals agree by construction. |
| D-65 | The range is `start_date` and `end_date`, UTC dates, inclusive: by default the last 30 days ending today, at most 92 days, and never past today. These are constants on `ReportRange`, not config. A failed validation redirects to the default report with field errors | They are `/v1/usage`'s own defaults, limit and param names (§2.1), so PE-018 can send the same range and never get `422 date_range_too_long`. These aren't operational bounds a dev or E2E value should change (D-48). A fixed redirect target keeps a typed URL from bouncing to an unrelated page. |

## Tests
No new trait is needed. The AC 11 data set lives in one test file (task 7), and the other files need a row or two each.

| Test | Location | Covers |
| --- | --- | --- |
| `GrantOperatorAccessCommandTest` | `tests/Feature/Console/` | Task 1 (AC 3):<br>- a user becomes an operator, with the copy, exit 0 and one info log;<br>- run twice: exit 0 both times, still an operator, and the "already" copy;<br>- `'  Ada@Example.COM '` finds `ada@example.com`;<br>- an unknown email: exit 1, the copy, the user count unchanged and no flag set;<br>- the name and password hash are never changed. |
| `UserTest`, `RegistrationTest` (existing) | `tests/Unit/Models/`, `tests/Feature/Auth/` | Task 1: the flag defaults to false and `new User([...])` ignores it; a registration posting `is_operator=1` creates a non-operator. |
| `OperatorUserSeederTest` | `tests/Feature/Database/` | Task 2 (AC 4):<br>- `seed()` twice → one operator account that signs in with the documented password;<br>- the demo user is not an operator;<br>- reseeding restores the name, the password and the flag;<br>- it refuses production with its message and writes nothing. |
| `DemoUserSeederTest` (existing) | `tests/Feature/Database/` | Task 2: reads the demo user by email, because two users now exist. |
| `ShowOperatorReportAccessTest` | `tests/Feature/Reporting/` | Task 3 (AC 1):<br>- a guest is redirected to Sign in;<br>- a non-operator gets 403 with "You don't have access to this page.", for a plain GET and for an Inertia GET;<br>- an operator gets 200 with `Reporting/Show`. |
| `OperatorNavigationPropTest` | `tests/Feature/Reporting/` | Task 4 (AC 2):<br>- a guest and a non-operator get `operator_navigation` `[]`, and their page JSON never contains `/report`;<br>- an operator gets exactly `[{label: Report, href: /report}]`. |
| `AppLayout.test.ts` (existing) | `tests/js/integration/layouts/` | Task 4: no Report link by default; with `givenOperatorNavigation()`, Report comes after History; `aria-current` on `/report`. |
| `ReportRangeTest` | `tests/Unit/Domain/Reporting/ValueObjects/` | Task 5, no database:<br>- AC 5: at 2026-11-18T12:00:00Z, 2026-10-20 to 2026-11-18, 30 dates newest first; the same under PHP's default timezone `Pacific/Kiritimati`, and at 00:00:00Z / 23:59:59Z;<br>- AC 6 guard: 92 days (2026-08-01 to 2026-10-31) is accepted, while 93 days, start > end, `2026-02-30` and `2026-1-5` throw;<br>- one day is fine;<br>- `startsAt()` / `endsBefore()` are `2026-11-16 00:00:00+00:00` / `2026-11-18 00:00:00+00:00` for 11-16 to 11-17. |
| `GenerationQueryBuilderTest`, `GenerationFactoryTest` (existing) | `tests/Unit/…` | Task 6:<br>- `billedByPixelforge()` selects succeeded and provider-cost-only rows, never pending, queued, processing, failed or locally failed ones;<br>- `completedBetween()` is inclusive at its start and exclusive at its end;<br>- `providerCostOnly()` has a ledger of a hold and a release, and is consistent. |
| `ShowOperatorReportActionTest` | `tests/Feature/Reporting/` | Task 6, RefreshDatabase.<br>Bucketing (AC 8):<br>- for each of `Pacific/Kiritimati` and `Pacific/Pago_Pago`, with `SET TIME ZONE` and PHP's default timezone both set and the latter restored in `tearDown()`: created 2026-11-16T23:59:50Z and completed 2026-11-17T00:00:12Z counts on 11-17; completed 2026-11-16T23:59:58Z with its charge written at 2026-11-17T00:00:20Z (travel) counts on 11-16;<br>- range edges: completed at start 00:00:00Z or end 23:59:59Z is in, while a second before the start or 00:00:00Z after the end is out.<br>What counts (AC 7, 9, 10):<br>- zero days: every date, all zeros;<br>- provider-failed and locally failed rows → nothing, count 0;<br>- a D-09 row → succeeded 1, spend 0, its provider cost, negative profit;<br>- pending, queued and processing rows with holds → nothing;<br>- a raw ledger debit of another type on a billed generation, and a raw entry with no generation, are never spend.<br>Shape (AC 12, 13):<br>- users sorted by spend descending, then email;<br>- every `toArray()` figure is an int, including sums over two rows. |
| `BillingTypesDeclareNoFloatsTest` (existing) | `tests/Unit/Domain/Billing/` | Task 6: the scan also covers `app/Domain/Reporting`. |
| `OperatorReportWorkedExampleTest` | `tests/Feature/Reporting/` | Task 7 (AC 11, 13, 14):<br>- G1–G4 as in the story, each cost and charge first checked through `CalculateProviderCostAction` and `CalculateUserChargeAction`;<br>- 11-16: 2 / 93,243,750 / 74,595,000 / 18,648,750; 11-17: 1 / 0 / 17,480,000 / −17,480,000;<br>- users B (1 / 74,337,500 / 59,470,000 / 14,867,500), then A (2 / 18,906,250 / 32,605,000 / −13,698,750);<br>- totals 3 / 93,243,750 / 92,075,000 / 1,168,750, which equal the sum of the days and the sum of the users;<br>- each user's figures equal the sum of their figures in the two single-day reports. |
| `ShowOperatorReportPageTest` | `tests/Feature/Reporting/` | Task 8, frozen at 2026-11-18T12:00:00Z (AC 5, 7, 12):<br>- `range` 2026-10-20 / 2026-11-18, and `limits` 92 / 2026-11-18;<br>- 30 days newest first, with zeros;<br>- one succeeded generation gives its day and its user row; the closure-scoped `has()` allows exactly `user_id`, `email` and the four figures;<br>- `whereType(…, 'integer')` for every figure in days, users and totals. |
| `ShowOperatorReportValidationTest` | `tests/Feature/Reporting/` | Task 9 (AC 6):<br>- 2026-08-01 to 2026-10-31 → 200 with 92 days;<br>- 2026-08-01 to 2026-11-01 → redirect to `/report` with the `end_date` span error, and no logged query reads `generations`;<br>- start after end → `start_date` error;<br>- end tomorrow (UTC) → `end_date` error, while today is accepted;<br>- `2026-02-30`, `2026-1-5` and an array → "Enter a valid date.";<br>- one date only → the other is required;<br>- neither date → the default;<br>- a non-operator with a bad range → 403. |
| `Show.test.ts` | `tests/js/integration/pages/Reporting/` | Tasks 3, 8, 11:<br>- the heading and the range;<br>- the summary totals;<br>- daily rows newest first, including a zero day;<br>- per-user rows in prop order;<br>- with no billed rows: every day listed with zeros, and "No billed generations in this range." (AC 16). |
| `ReportRangeForm.test.ts` | `tests/js/integration/components/report/` | Task 10:<br>- the fields hold the range, with `max` set to today;<br>- Apply sends one GET to `/report` with both dates, and is busy meanwhile;<br>- returned errors show under From and To and mark them invalid, while the typed values stay. |
| `ReportTable.test.ts` | `tests/js/integration/components/report/` | Task 11 (AC 15), with AC 11's figures:<br>- the caption, labels and counts;<br>- `$0.0932` etc., with exact values on focus and on hover (`$0.018648750`);<br>- the 11-17 profit `-$0.0175`, with `data-negative` and medium weight, while positives have neither;<br>- the totals footer;<br>- the empty text with no rows. |
| `report.spec.ts` | `tests/e2e/` | Task 12, desktop and mobile:<br>- a registered user submits `[succeed] a red fox` (Low, Square) and waits until History shows Succeeded and its charge (`exactNanodollars`);<br>- that user has no Report link, and `page.request.get('/report')` (same session) is 403 with the message; `page.request` is used so the document's 403 can't log a console error;<br>- sign out, sign in as `OPERATOR_USER`, then click Report;<br>- To holds today's UTC date;<br>- the user's row shows Succeeded 1, and its exact spend equals that charge;<br>- at 375 px, no horizontal scroll;<br>- it never asserts daily or overall totals. |

## Manual production steps (orchestrator, after merge)
Run these only once CI is green on `main`. No password is ever on a command line: emails come from shell variables, and accounts come from the README's stdin procedure.
1. **Deploy.** Run `dep deploy`, then `make smoke-production` (`make up` first). The migration adds `users.is_operator`, false for every existing user.
2. **Reviewer.** With `E2E_REVIEWER_EMAIL` exported as in the README's "Reviewer account" section, run `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-operator '$E2E_REVIEWER_EMAIL'"`. Expect "Granted operator access to …" and exit 0. Run it again: "… is already an operator.", exit 0.
3. **Operator account.** Set `export OPERATOR_EMAIL=<the user's own production account email>`. If that account doesn't exist, the user first creates it with the README's `app:upsert-user` procedure (`read -rs`, password over stdin). Then run the step 2 command with `$OPERATOR_EMAIL`.
4. **Check the grants.** `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan tinker --execute 'echo App\Models\User::query()->where(\"is_operator\", true)->count();'"` → 2. Never run `db:seed` on production.
5. **Check the page.**
    - As a guest, `curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' https://prompt.sergiupopa.dev/report` → `302 https://prompt.sergiupopa.dev/login`.
    - Signed in as the reviewer, Report is in the nav. The default range lists PE-015's go-live day, and the reviewer's row counts the go-live successes. Hovering an amount shows its 9 decimals. Then sign out.

## README
- **Local development (task 2):** a row for `operator@promptedit.test` / `promptedit-operator` in the seeded accounts table. The existing "local-only, the seeder refuses production" sentence covers both accounts.
- **Production → new "Operator access" section, after "Reviewer account" (task 13):**
  - `app:grant-operator <email>` with the `$E2E_REVIEWER_EMAIL` example;
  - it is idempotent, never creates users or sets passwords, and exits 1 on an unknown email;
  - create the account with `app:upsert-user` first;
  - there is no UI and no revoke (D-62).
- **Reviewer guide (task 13):** one bullet: "The reviewer account is an operator: Report shows spend, Pixelforge cost and profit per UTC day and per user, exact to the nanodollar on hover." No other existing README text changes.

## Size
The estimate is ≈ 2,700 changed lines, about 60 % of them tests. That is far above the ≈ 400 budget and the story's own ~450, which counted neither the timezone and ledger-type proofs nor the validation matrix. It ships as one PR of self-contained commits, by the project rule.

| Tasks | Content | ≈ Lines |
| --- | --- | --- |
| 1–2 | Flag, grant command, seeder | 340 |
| 3–4 | Gate, 403, page shell, nav prop | 260 |
| 5–7 | Range, query, worked example | 890 |
| 8–9 | Props, types, validation | 510 |
| 10–11 | Range form, summary, tables | 580 |
| 12–13 | E2E, decisions, README | 150 |

**Cut point: after task 4.** PR A (≈ 600 lines) is the story's PE-016a: AC 1–4. Operators then see an empty report page. PR B (≈ 2,100 lines) is PE-016b: AC 5–16, the E2E spec and D-62 to D-65.

## Risks
- **Parallel lanes.** PE-017 edits the same shared files: `AppServiceProvider::boot()`, `HandleInertiaRequests`, `inertia.d.ts`, `AppLayout.vue`'s nav, `tests/js/integration/support/inertia.ts`, `DatabaseSeeder`, the README and `requirements.md`. Each change here is a few appended lines or one new key, so a merge conflict stays trivial. No D-number above D-65 is used.
- **Session timezone in tests.** `SET TIME ZONE` runs inside RefreshDatabase's transaction, and a rollback reverts it, so it never leaks. PHP's default timezone is restored in `tearDown()`.
- **PDO types.** `SUM(bigint)` is numeric in Postgres, so every sum is cast to bigint in SQL. Under `strict_types`, the int-typed DTOs then throw on a stray string instead of rounding it.
- **The seeded second user.** Any test that seeds `DatabaseSeeder` and expects one user breaks. Only `DemoUserSeederTest` does, and task 2 fixes it.
- **E2E in a shared dev database.** Other specs keep creating generations, and the per-user table grows with every run. The spec reads only its own user's row, and never daily or overall totals.
- **A demoted operator.** An operator demoted mid-session gets Inertia's HTML modal on their next report visit. This is rare and accepted (D-63).
- **No index on `completed_at`.** Production is capped at 500 generations a day, so a 92-day range touches at most ≈ 46,000 rows, and a scan of the whole table is milliseconds for years. PE-018 can add a partial index if `EXPLAIN` ever shows the need.
