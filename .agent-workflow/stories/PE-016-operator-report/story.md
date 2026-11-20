# Story: PE-016-operator-report

## Title
Operator report: credit spend, provider cost and profit per day and per user

## Status
`in-review`

## Description
This is stretch (a), part one. An operator needs to know whether the business makes money. The report shows what users were charged, what Pixelforge cost us and the profit, per UTC day and per user, exact to the nanodollar.

Days are bucketed by Pixelforge's `completed_at` in UTC, exactly as `GET /v1/usage` buckets them (D-10). Provider-cost-only generations (D-09) are included, so that PE-018 can put Pixelforge's own figures next to ours and match them to the nanodollar.

Only operators can open the report. Operator access is granted with an idempotent artisan command, and there is no role-management UI.

Serves grading #1 correct money (exact, auditable totals), #3 product sense (an operator view that answers "are we profitable, and from whom?") and the stretch operator report.

## Acceptance Criteria

**Access**
1. A guest who opens the report is redirected to sign in. A signed-in non-operator gets `403` with the page "You don't have access to this page." An operator gets the report.
2. The "Report" navigation link is shown only to operators; a test on the shared navigation prop proves non-operators never receive it.
3. An idempotent artisan command grants operator access to an existing user by email.
    - Running it twice leaves the user an operator and exits successfully both times.
    - An unknown email exits non-zero with a clear message and changes nothing.
    - The command never creates users or sets passwords.
4. A local operator, `operator@promptedit.test`, is seeded with a documented local-only password, following PE-003's demo-user pattern. Local development and the CI E2E job, which seeds, therefore both have one. Running the seeder twice neither fails nor duplicates the operator, and the seeder refuses to run in production.

**Range**
5. The default range is the last 30 UTC days ending today, inclusive. With the clock frozen at 2026-11-18T12:00:00Z, that is 2026-10-20 to 2026-11-18.
6. The range is validated with field errors, never exceptions, and no query runs with an invalid range:
    - both dates must be valid dates (2026-02-30 is rejected);
    - start ≤ end;
    - end is not after today (UTC);
    - the span is at most 92 days inclusive: 2026-08-01 to 2026-10-31 (92 days) is accepted, and 2026-08-01 to 2026-11-01 (93 days) is rejected.

**Daily table**
7. There is one row per UTC day in the range, including days with no activity. Those days show 0 in every column.
8. A generation counts on the UTC date of Pixelforge's `completed_at`. It never counts on its `created_at`, or on the time we recorded its charge. This holds whatever the DB session or app timezone is, and a test runs under a non-UTC session timezone (for example `Pacific/Kiritimati`, UTC+14). Examples:
    - Created 2026-11-16T23:59:50Z and completed 2026-11-17T00:00:12Z: counts on 2026-11-17.
    - Completed 2026-11-16T23:59:58Z, with its webhook processed and its charge written at 2026-11-17T00:00:20Z: counts on 2026-11-16.
9. Each day shows four columns:
    - **Succeeded**: generations billed by Pixelforge, i.e. provider-succeeded, including D-09 rows.
    - **Spend**: user charges only.
    - **Provider cost**.
    - **Profit**: spend − provider cost.

    Holds, releases, and the grants and purchases PE-017 adds later are never counted as spend.
10. A failed generation contributes 0 to every column, including the Succeeded count.
11. Worked example. Rates are 5.00 / 10.00 / 40.00 / 12.50 per 1M tokens, which is 5,000 / 10,000 / 40,000 / 12,500 n$ per token. Usage below is given as input text / input image / output / reasoning tokens.

    | | User | Completed | Outcome | Usage | Provider cost | User charge |
    |---|---|---|---|---|---|---|
    | G1 | A | 2026-11-16 | Succeeded | 5 / 0 / 280 / 312 | 5 × 5,000 + 280 × 40,000 + 312 × 12,500 = 15,125,000 n$ | ceil(15,125,000 × 125 / 100) = 18,906,250 n$ |
    | G2 | B | 2026-11-16T23:59:58Z | Succeeded | 9 / 0 / 1,100 / 1,234 | 45,000 + 44,000,000 + 15,425,000 = 59,470,000 n$ | 74,337,500 n$ |
    | G3 | A | 2026-11-16 | Failed | – | 0 | 0 |
    | G4 | A | 2026-11-17T00:00:12Z (created 2026-11-16T23:59:50Z) | Provider-cost-only (D-09) | 6 / 0 / 280 / 500 | 30,000 + 11,200,000 + 6,250,000 = 17,480,000 n$ | 0 |

    Expected per day:
    - 2026-11-16: 2 succeeded, spend 93,243,750 n$ ($0.093243750), provider cost 74,595,000 n$ ($0.074595000), profit 18,648,750 n$ ($0.018648750).
    - 2026-11-17: 1 succeeded, spend 0, provider cost 17,480,000 n$ ($0.017480000), profit −17,480,000 n$ (−$0.017480000). The negative profit is shown, not hidden.
12. Every sum reaches the page as an integer in n$: a PHP `int`, then a TS `number`. Sums are never floats or decimal strings. A test asserts the prop types.

**Per-user and overall totals**
13. The per-user table covers the selected range.
    - It lists every user with at least one succeeded generation in the range, sorted by spend in descending order.
    - Each user shows succeeded count, spend, provider cost and profit.
    - Each user's totals equal the sum of their daily contributions.

    For AC 11 over 2026-11-16 to 2026-11-17:
    - user B: 1 succeeded, spend 74,337,500, provider 59,470,000, profit 14,867,500 n$ ($0.014867500);
    - user A: 2 succeeded, spend 18,906,250, provider 32,605,000, profit −13,698,750 n$ (−$0.013698750).
14. The overall totals equal both the sum of the days and the sum of the users. For AC 11 they are: 3 succeeded, spend 93,243,750, provider 92,075,000, profit 1,168,750 n$ ($0.001168750).

**Display**
15. Amounts are shown as USD to 4 decimals through `money.ts`, for example $0.0186, with the exact 9-decimal value available on hover or focus, for example $0.018648750. Negative values show a minus sign and a distinct style that does not rely on colour alone.
16. When nothing succeeded in the range, the daily table still lists every day with zeros, and the per-user table shows the empty state "No billed generations in this range."

## Scope

### In Scope
- An operator flag on users, the authorization gate and the route protection.
- An idempotent artisan command that grants operator access, run by hand on production after deploy for the reviewer and operator accounts.
- A seeded local operator with a documented local-only password; the seeder refuses production.
- The nav link for operators only, and 403 for everyone else.
- The report query in the Reporting domain:
  - per UTC day of Pixelforge's `completed_at`, including zero days;
  - per-user totals for the range;
  - overall totals.
- A UTC date range filter: by default the last 30 days, at most 92 days, inclusive.
- Failed generations contribute 0, and D-09 provider costs are included. Spend excludes holds, releases, grants and purchases.
- Integer sums, a 4-decimal display and exact 9-decimal values through `money.ts`, including negative values.
- A Vue report page with its components and TS prop types.
- PHP feature tests, JS unit and integration tests, and a Playwright spec.

### Out of Scope
- Comparison with `GET /v1/usage` (PE-018).
- Balances, grants and purchases (PE-017).
- CSV export, charts and timezone selection.
- A role-management UI, and revoking operator access through the UI.
- Seeding report demo data for E2E. The E2E creates its own generation through the fake (see Technical Notes).

## Technical Notes
- **Depends on:**
  - PE-007-credit-ledger-hold-settle-release. Spend comes from the ledger's `charge` entries. Provider cost, usage and `completed_at` are stored on each generation, including D-09 rows; PE-007 keeps provider cost on the generation, not in the ledger.
  - PE-015-production-go-live: a sequencing dependency only. Stretch work starts after the required scope is live.
- **Decisions:** D-03 (integer n$), D-08 (the append-only ledger is the source of spend), D-09 and D-10. See requirements.md §3 for the display rules, and §2.1: `/v1/usage` buckets by completed UTC day and spans at most 92 days.
- **Bucketing:**
  - Bucket on the UTC date of the generation's stored Pixelforge `completed_at`, never on the ledger entry's own timestamp (see the G2 example).
  - Do not rely on the connection or app timezone: in Postgres, `date_trunc` on `timestamptz` follows the session timezone.
- **Integer types:**
  - In Postgres, `SUM(bigint)` returns `numeric`, so convert it explicitly to an integer.
  - JS `number` holds integer n$ exactly up to `Number.MAX_SAFE_INTEGER` (about 9.0 × 10^15 n$, or about $9.0M), which is ample for this report. Note this next to the TS type.
- Profit can be negative on days with D-09 rows. Show it.
- PE-017 will add more ledger types later, so the query defines spend as "user charges only", never as "all debits".
- **Credentials:** the local operator's documented password is local-only, like PE-003's demo user, and never reaches production because the seeder refuses to run there. On production, operator access is only ever granted by the command, run by hand after deploy.
- Each prop has a TS type in `resources/js/types/`. Money crosses the wire as integer n$.
- **Why the E2E doesn't assert totals:** Playwright runs fully parallel, and other specs create generations through the fake today, so daily and overall totals move during a run. Seeded report data would also show as mismatches against the fake's usage in PE-018. The E2E therefore asserts one user's row, and the exact AC 11–14 figures are proven in PHP feature tests.
- **Required test layers:**
  - **PHP feature (query, RefreshDatabase):**
    - UTC day boundaries by `completed_at` under a non-UTC session timezone, including both AC 8 examples;
    - zero days;
    - failed = 0;
    - D-09 rows included;
    - holds and releases excluded;
    - the AC 11, 13 and 14 numbers;
    - per-user totals equal the sum of days;
    - integer prop types.
  - **PHP feature (HTTP):**
    - guest redirect;
    - 403 for non-operators;
    - operator 200 with `assertInertia` props;
    - range validation: 92 days accepted, 93 rejected, start > end, future end, invalid date;
    - the nav prop only for operators.
  - **PHP feature (console):**
    - the grant command is idempotent and fails on an unknown email;
    - the operator seeder is idempotent and refuses to run in production.
  - **JS unit:** negative and exact 9-decimal formatting in `money.ts`, if added here.
  - **JS integration:**
    - the daily and per-user tables render rows and totals;
    - negative styling;
    - exact values on hover or focus;
    - the empty state;
    - the validation error.
  - **E2E** (Playwright, fake driver):
    - A user registered in the spec makes a `[succeed]` generation and waits until History shows it Succeeded with its charge.
    - That user sees no Report link and gets 403 on the report URL.
    - The seeded operator then opens the report. The default range includes today.
    - The operator sees that user's per-user row with 1 succeeded and spend equal to the charge History showed.
    - The spec asserts only that user's row, never daily or overall totals.
- **PR size:** an estimated ~450 changed lines, over the budget. Split into two stories before planning, one PR each:
  - PE-016a: the operator flag, gate, grant command, seeder, nav prop and 403.
  - PE-016b: the report query, page and tests.

## Mockups / References
```text
Report (operator only)
┌─────────────────────────────────────────────────────────────────────┐
│ PromptEdit   Generate  History  Report                  ops@… ▾     │
├─────────────────────────────────────────────────────────────────────┤
│ Operator report (UTC)                                               │
│ From [2026-10-20]  To [2026-11-18]  [Apply]                         │
│                                                                     │
│ Spend $0.0932   Provider cost $0.0921   Profit $0.0012              │
│                                                                     │
│ Per day (by Pixelforge completion date, UTC)                        │
│ Date        Succeeded  Spend     Provider  Profit                   │
│ 2026-11-18      0      $0.0000   $0.0000    $0.0000                 │
│ 2026-11-17      1      $0.0000   $0.0175   −$0.0175  ← negative     │
│ 2026-11-16      2      $0.0932   $0.0746    $0.0186                 │
│ 2026-11-15      0      $0.0000   $0.0000    $0.0000                 │
│ …                                                                   │
│ Total           3      $0.0932   $0.0921    $0.0012                 │
│      (hover or focus any amount → exact, e.g. $0.018648750)         │
│                                                                     │
│ Per user                                                            │
│ User            Succeeded  Spend     Provider  Profit               │
│ b@example.com       1      $0.0743   $0.0595    $0.0149             │
│ a@example.com       2      $0.0189   $0.0326   −$0.0137             │
└─────────────────────────────────────────────────────────────────────┘

Validation error:
│ From [2026-08-01]  To [2026-11-01]                                  │
│ ⚠ The range can be at most 92 days.                                 │

Empty range:
│ Per user: No billed generations in this range.                      │
│ (the daily table still lists each day with zeros)                   │

Non-operator: 403 page — "You don't have access to this page."
```
- https://pixelforge.promptedit.dev/docs#usage (completed UTC day bucketing, 92-day limit)
- https://pixelforge.promptedit.dev/docs#pricing
- requirements.md §2.1, §3 and §5 (D-03, D-08, D-09, D-10)
