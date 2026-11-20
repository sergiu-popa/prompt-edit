# QA — PE-016-operator-report

**Verdict: QA PASSED**

Run: 2026-11-20, branch `pe-016-operator-report` at `f75f12c` (code review round 2 APPROVE). Environment: lane l16 stack (app, worker, scheduler up; fake Pixelforge driver), `PIXELFORGE_FAKE_WEBHOOK_URL=http://app:8080` pinned by `lane-make.sh` for PHPUnit. No Pixelforge key used.

## Suite

| Check | Command | Result |
|---|---|---|
| Lint (Pint, 312 files) | `lane-make.sh l16 lint` | clean |
| Static analysis (Larastan, 289 files) | `lane-make.sh l16 stan` | no errors |
| PHPUnit | `lane-make.sh l16 test` | 1819 passed (37,511 assertions). No lane-environment failures |
| ESLint, Prettier, vue-tsc | `lane-make.sh l16 js-check` | clean |
| Vitest | `lane-make.sh l16 js-test` | 528 passed in 36 files |
| Full Playwright E2E (existing suite, desktop + mobile) | `lane-make.sh l16 e2e` | 46 passed, 2 skipped (the existing touch-only and mouse-only tests in `generate.spec.ts`) |
| QA browser probe (temporary, see below) | `lane-make.sh l16 e2e "width \|a user generation"` | 31 passed, 1 skipped (its generation flow runs on desktop only) |

## Acceptance criteria

| AC | Evidence | Status |
|---|---|---|
| 1 guest to sign in; non-operator 403 with message; operator gets report | `ShowOperatorReportAccessTest`; browser: guest `/report` → `/login` and demo 403 with "You don't have access to this page." at 375/768/1440, both projects | Pass |
| 2 nav link for operators only | `OperatorNavigationPropTest`, `AppLayout.test.ts`; browser: demo nav = Generate, History; operator nav has Report | Pass |
| 3 grant command, idempotent, unknown email fails | `GrantOperatorAccessCommandTest`; CLI (below) | Pass |
| 4 seeded operator, seeder idempotent, refuses production | `OperatorUserSeederTest`; operator login works in browser | Pass |
| 5 default range = last 30 UTC days | browser: From 2026-10-22, To 2026-11-20 (today UTC), 30 daily rows; `ReportRangeTest` covers the frozen-clock case | Pass |
| 6 validation field errors, no query on invalid range | `ShowOperatorReportValidationTest`; browser table below | Pass |
| 7 one row per UTC day, zero days shown | browser: 30 rows at default; 10 zero rows for 2020-01-01..10 | Pass |
| 8 bucket by Pixelforge `completed_at` UTC, non-UTC session TZ | `ShowOperatorReportActionTest` (Pacific/Kiritimati cases); SQL cross-check below | Pass |
| 9 four columns; holds/releases/grants excluded | `ShowOperatorReportActionTest`; spend delta in browser equals History charge exactly | Pass |
| 10 failed generation adds 0 | browser: `[fail]` shows "Not charged"; operator totals unchanged by it | Pass |
| 11 worked example per day | `OperatorReportWorkedExampleTest` | Pass |
| 12 integer sums end to end | PHP prop-type assertions in `ShowOperatorReportPageTest`; `BillingTypesDeclareNoFloatsTest` scan widened to `app/Domain/Reporting` | Pass |
| 13 per-user rows, sorted by spend desc; equal sums of days | `ShowOperatorReportActionTest`; browser probe asserts daily and per-user sums equal totals and the sort order at 375/768/1440 | Pass |
| 14 overall totals = sum of days = sum of users | `OperatorReportWorkedExampleTest`; browser probe (same assertions as 13) | Pass |
| 15 USD 4 dp, exact 9 dp on hover/focus; negatives signed and styled | `money.test.ts`, `ReportAmount` tests; browser: hover shows `$2.932718750`, focus reveals, Escape hides, tooltip stays on screen at 375/768/1440. No negative value exists in the lane's default range, so the browser did not exercise negative styling | Pass (negative styling by unit and integration tests only) |
| 16 empty range: zeros and empty state | browser: 10 zero rows and "No billed generations in this range." for 2020-01-01..10; `ShowOperatorReportPageTest` | Pass |

## Visual verification

Widths 375, 768 and 1440, both Playwright projects (desktop Chrome and Pixel 7 emulation), via a temporary probe spec (not committed; see *Cleanup*).

- **Guest:** `/report` redirects to `/login`. Document overflow 0 px at every width.
- **Demo user:** nav has no Report link. `/report` returns HTTP 403 with "You don't have access to this page." Overflow 0 px at every width.
- **Operator default report:** overflow 0 px (`scrollWidth == clientWidth`) at every width. The daily table scrolls sideways inside its own card at 375 px (box 341 px, table 491 px) and its title stays visible. Totals and per-user table are readable at all widths.
- **Hover and focus:** the amount tooltip shows the exact 9-decimal value (for example `$2.932718750`). At 375 px it sits at x 195.8–294.5 of 375, so it stays on screen. Focus reveals it and Escape hides it.
- **Range validation** (each case under the right input, `aria-describedby` set, no report query: the URL drops back to `/report` with the default range shown):

| Case | Message | Field |
|---|---|---|
| End before start (2026-11-10 to 2026-11-01) | The start date must be on or before the end date. | From |
| 93 days (2026-08-01 to 2026-11-01) | The range can be at most 92 days. | To |
| Future end (to 2026-11-21) | The end date can't be after today (UTC). | To |
| Empty start | Enter a start date. | From |
| URL `start_date=2026-02-30` | Enter a valid date. | From |
| URL `end_date=2026-13-01` | Enter a valid date. | To |
| 92 days (2026-08-01 to 2026-10-31) | accepted, 92 daily rows | — |
| Single day (2026-11-18) | accepted, 1 row | — |

- **Console and page errors:** none on app pages. A non-operator's document load of `/report` logs one browser message, "Failed to load resource: the server responded with a status of 403". This is the browser reporting the 403 response, not an app exception. The existing E2E avoids it with `page.request`, so it is noted here, not counted as a failure.
- **Server logs:** `lane-make.sh l16 logs` shows zero HTTP 500 responses and no exceptions.

Screenshots and measurement JSON, all under `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-016/`:
- `report-default-375.png`, `report-default-768.png`, `report-default-1440.png`
- `report-92-*.png`, `report-empty-*.png`, `report-hover-*.png`, `report-user-row-375.png`
- `report-err-order-*.png`, `report-err-span-*.png`
- `guest-login-*.png`, `demo-403-*.png`
- `*.json` measurements per check (`report-default-*.json`, `validation-*.json`, `user-generation-flow.json`, and others)

Viewed: `report-default-375.png` (readable, nothing cut off except the daily table's scrolling columns, as intended) and `report-err-span-375.png` (red error under To, Apply still reachable).

## Generation flow (end to end, desktop)

- A new non-operator registered and submitted `[succeed] qa pe016 success probe`. History showed Succeeded with the charge **14,290,625 n$ ($0.014290625)**.
- The operator's report, before and after, for today's UTC row:
  - succeeded 36 → 37 (+1);
  - spend 2,932,718,750 → 2,947,009,375 (**+14,290,625**, equal to the charge);
  - provider cost 2,346,175,000 → 2,357,607,500.
- The user's per-user row: 1 succeeded, spend 14,290,625 n$ (equal to History).
- A `[fail]` generation showed "Not charged" and added nothing to any column.
- The user's `/report` returns 403.

## Database cross-check

An independent SQL aggregate in the lane DB (UTC day of `completed_at`, `output_tokens IS NOT NULL`, spend = negated `charge` ledger entries) for 2026-10-22 to 2026-11-21 gives 37 succeeded, spend 2,947,009,375 and provider cost 2,357,607,500. The page at 1440 px shows the same numbers.

## Grant command

| Run | Exit | Output |
|---|---|---|
| `app:grant-operator <fixture email>` (not yet operator) | 0 | Granted operator access to ... |
| same again | 0 | ... is already an operator. |
| `app:grant-operator nobody-qa@promptedit.test` | 1 | No user has the email ... This command never creates users ... |

The unknown-email run left the user count unchanged.

## Cleanup

- The probe spec is deleted from the worktree, and `test-results/` is removed. The worktree is clean (`git status` empty). Its copy and screenshots sit in the scratchpad only.
- Fixture rows created by the probe: 5 users and 10 generations (2 per flow run). The lane DB has 46 users, 38 generations and 108 ledger entries before QA. The full E2E suite then added accounts and generations as it does by design.
- Fixture users cannot be deleted. Ledger entries are append-only (D-08, trigger `credit_ledger_entries_append_only`), so the probe's fixture rows stay. The existing E2E suite leaves its rows the same way.
- The one probe user promoted by the grant check was reverted to `is_operator = false`. The only operator in the lane DB is `operator@promptedit.test`.

## Open items

None blocking. The only observation is the browser's 403 console message noted under *Visual verification*.
