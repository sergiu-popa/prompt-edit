# Review — PE-016-operator-report

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 16 `[PE-016]` commits `263ca63..fe1eb6b` on `pe-016-operator-report`, checked against `story.md` and `plan.md`. `origin/main` does not contain PE-015 yet, so the story diff is `52e0515..HEAD`, which holds only `[PE-016]` commits. The commits are the plan, 13 task commits, one follow-up UI commit (`a38abb1`) and the bookkeeping. All 13 plan tasks are checked.

Checks run on HEAD (`fe1eb6b`):
- `lint` (Pint, 312 files) and `stan` (Larastan, 289 files) are clean.
- `test`: 1,781 of 1,815 PHPUnit tests pass. The 34 failures are all in `tests/Feature/Pixelforge/Fake/*` and come from the lane, not from the story. Each one is a `StrayRequestException` for `http://prompt-edit-l16-app:8080/webhooks/pixelforge`. The lane's `.env` points `PIXELFORGE_FAKE_WEBHOOK_URL` at its own container, while `InteractsWithFakePixelforge::FAKE_WEBHOOK_URL` expects `http://app:8080`. No story commit touches those files, `config/pixelforge.php` or `phpunit.xml`. With `PIXELFORGE_FAKE_WEBHOOK_URL=http://app:8080` set in the environment, `tests/Feature/Pixelforge/Fake` passes: 75 tests, 1,376 assertions.
- `js-check` (ESLint, Prettier, vue-tsc) is clean. `js-test` passes 528 Vitest tests in 36 files.
- `e2e "operator sees"` exits 0. `report.spec.ts` passes on desktop and on mobile.

There is no Critical finding. One Warning must change. Four optional Suggestions follow.

### Summary

The implementation follows the plan class by class, and D-62 to D-65 are recorded after D-61 with no clash.

- **Access (AC 1–4, D-62, D-63):**
  - `is_operator` is cast to a boolean. It stays out of `#[Fillable]` and defaults to false both in the column and in `$attributes`. `UserTest` and `RegistrationTest` prove that mass assignment and a registration posting `is_operator=1` are both ignored.
  - `GrantOperatorAccessAction` is the only writer. The command trims and lowercases the email as `UpsertUserCommand` does, fails on an unknown email without writing anything, never creates a user or touches a password, and logs only the email and the id.
  - `OperatorUserSeeder` refuses production before it writes anything, and running it twice is idempotent. `DemoUserSeederTest` now reads the demo user by email.
  - `auth` runs before `can:`, which runs before the Form Request. A guest is therefore sent to Sign in, and a non-operator gets the stock 403 page with the gate's message, even on an Inertia GET and even with an invalid range. All of these paths are tested.
  - `operator_navigation` is a closure, and it is `[]` for everyone who is not an operator. `OperatorNavigationPropTest` proves that a non-operator's page JSON never contains `/report`, and the two existing exact-props tests now list the new key.
- **Query (AC 7–14, D-64):**
  - There is one base-builder query. It filters on `billedByPixelforge()` (usage recorded) and on `completedBetween()` (bound as instants), left-joins only the `charge` entry, and groups by `to_char(completed_at at time zone 'UTC', …)`. Every sum is cast to `bigint`, and any value that is not an int throws.
  - `unique(generation_id, type)` on `credit_ledger_entries` means the left join can never double-count a generation.
  - The days are zero-filled from `ReportRange::dates()`, and the totals fold those days. The tests prove that the totals equal the sum of the users, and that each user's totals equal the sum of their single-day reports.
  - `ShowOperatorReportActionTest` covers both AC 8 examples under a session timezone of UTC+14 and UTC−11, with PHP's default timezone set the same way. It also covers every range edge, failed, local-failure and in-flight rows, a D-09 row, ledger types other than `charge` (and a `charge` without a generation), the sort order and int types. `OperatorReportWorkedExampleTest` reproduces every number in AC 11, 13 and 14.
- **Range (AC 5, 6, D-65):**
  - `ReportRange` parses strict `Y-m-d` dates as UTC midnights through a format round trip, which rejects `2026-02-30` and `2026-1-5`. `endsBefore()` returns the next UTC midnight.
  - The Form Request checks order and span in `after()` only when both dates are already valid. It redirects to `route('report')`. `ShowOperatorReportValidationTest` covers the 92/93-day boundary (and that no query reads `generations` on a 93-day range), start after end, an end after today (including today at 23:59:59Z under UTC+14), every invalid-date shape, a date given alone, no dates, the full-load redirect and the 403 that comes before validation.
- **Page (AC 15, 16):**
  - Amounts go only through `MoneyAmount` and `money.ts`. A negative amount gets `data-negative`, `font-medium` and red, so it never relies on colour alone.
  - The tables are `aria-labelledby` an `h2` outside the scroll box. The per-user empty state and the zero days are covered in `Show.test.ts` and `ReportTable.test.ts`.
  - `ReportRangeForm` keeps the typed values with `preserveState` and reads errors from the form or from the page props, so the errors also show on a full page load of an invalid URL.
  - At 375 px the E2E finds no horizontal scroll with the third nav item.

Security: the only new write path is the command and the seeder, which refuses production. The flag can't be mass-assigned. The report's only inputs are two dates, validated and bound, and the one raw SQL fragment is a constant. Emails are shown only to operators. No secret is committed or logged.

The constant-field check: the report's props carry no constant field. `limits.max_days` is a constant, but the form's hint uses it, and it is not a field of a scoped query.

### Commit hygiene

- Every task commit carries its code with its tests. Where a change broke existing tests, the commit that caused it also fixes them: the exact-props tests in `0a7a030`, `DemoUserSeederTest` in `0a8e187`, and the support defaults in `inertia.ts` in `0a7a030`.
- The plan (`263ca63`), the decisions and runbook (`eba12f8`) and the bookkeeping (`fe1eb6b`) each have their own commit.
- All 16 subjects start with `[PE-016]`, are imperative and are at most 72 characters, and all 16 messages end with the trailer.
- The cut point after task 4 (`0a7a030`) is clean.
- I checked by reading that each commit stands on its own. I did not run the suite at each commit, because the lane may not check out other commits.

### Warnings

- [ ] **W1. `ReportRange::isDate()` is public API with no production caller.**
  - `app/Domain/Reporting/ValueObjects/ReportRange.php:71-74` adds `public static function isDate(string $date): bool`. Its only caller is `ReportRangeTest::test_dates_are_validated_strictly_as_utc_calendar_dates` (`tests/Unit/Domain/Reporting/ValueObjects/ReportRangeTest.php:115-125`).
  - The plan's `ReportRange` API does not list it. The Form Request validates dates with Laravel's `date_format:Y-m-d` (`ShowOperatorReportRequest.php:44-45`), and the class itself uses only the private `tryParse()`.
  - The method was probably added in task 5 for a validation path that task 9 then built differently. Left in, it is an untested-for-purpose entry point on a money-domain value object that the next reader has to reason about, and PE-018 would inherit it.
  - **Fix:** delete `isDate()`. Move its cases (leap day `2028-02-29` accepted; `2026-02-29`, a leading space, a trailing `\n` and `''` rejected) into the `test_an_invalid_range_throws` provider (`ReportRangeTest.php:93`) and the accepted-range tests, as `fromDates()` rows. That keeps the strict-parse coverage on the real entry point. Use one commit, for example `[PE-016] Drop the unused ReportRange::isDate()`.

### Suggestions

- [ ] **S1. The Form Request recomputes "today (UTC)" and the date format itself.** `ShowOperatorReportRequest::today()` (`app/Http/Requests/Reporting/ShowOperatorReportRequest.php:110-113`) and the two `date_format:Y-m-d` literals (lines 44-45) duplicate `ReportRange`'s private `DATE_FORMAT` and `limitsFor()['today']`. Making `DATE_FORMAT` public, and building the rule from `ReportRange::limitsFor(CarbonImmutable::now())['today']`, would keep a single source for both. This is optional.
- [ ] **S2. `ReportTable.vue`'s import groups differ from its siblings.** Lines 2-6 import `vue` after two `@/` imports, then put a blank line before `@/types/report`. `ReportRangeForm.vue` and `Show.vue` import external packages first, then a blank line, then the `@/` imports. Reordering them would match. This is cosmetic.
- [ ] **S3. The E2E reads "today" from the runner's clock.** `tests/e2e/report.spec.ts:38` compares To with `new Date().toISOString().slice(0, 10)`. That date and the server's UTC date differ only in a run that crosses UTC midnight, so the flake is very unlikely. Comparing with To's own `max` attribute (`limits.today`) would remove it entirely. This is optional.
- [ ] **S4. `a38abb1` reworks `4cb7d7c`'s table caption within the same PR.** Its body explains why, and its tests move with it, so it reads cleanly. Folding it into `4cb7d7c` would need a history rewrite, which the pipeline may not do. I note it only for the user's commit-by-commit read, and it needs no action.
