# Audit — PE-017-credit-balances-buy-credits

**Verdict: PASS**

Run on branch `pe-017-credit-balances-buy-credits` from `b0b2211` (QA run 2 recorded), after the rebase onto `main` (PE-016 merged as `1a92c58`). Prerequisites checked: story status `qa-passed`, `review.md` (round 1 APPROVE) and `qa.md` (QA PASSED) present.

## Scope

I read the whole `[PE-017]` diff (`1a92c58..b0b2211`, 103 files) against `story.md` AC 1–19, `plan.md` (including "Rebase onto main" and both Engineer notes), the review, both QA runs and D-67 to D-71. That covers:
- the ledger migration, `CreditLedgerEntryType`, `CreditLedgerEntry`, `CreditLedgerEntryQueryBuilder`, `CreditBalance`, `InsufficientCreditsException`, the four Billing actions, `CreateGenerationAction`, `GrantOpeningCreditsCommand`, `config/credits.php`, `deploy.php`, `phpunit.xml`, `docker-compose.yml` and `.env.example`;
- `CreateNewUser`, `UpsertUserCommand`, `UserFactory`, `DemoUserSeeder`, `OperatorUserSeeder`, `HandleInertiaRequests`, the two Credits controllers, `PurchaseCreditsRequest`, `CreateGenerationController` and `routes/web.php`;
- `money.ts`, `credits.ts`, `paths.ts`, `types/credits.ts`, `inertia.d.ts`, the four `components/credits/` files, `MoneyAmount`, `SubmitGenerationArea`, `HistoryItem`, `useGenerationForm`, `AppLayout`, `Credits/Buy.vue`, `Generations/Create.vue` and `Generations/Index.vue`;
- every new or changed PHPUnit, Vitest and Playwright file, the shared fixture, the test support (`InteractsWithCreditLedger`, `BuildsTheOperatorReportWorkedExample`, `inertia.ts`, `setup.ts`, `auth.ts`) and `tests/scripts/create-generation.php`;
- the README (reviewer guide, Balances and Buy credits, Local development, Operator access, Deploy runbook) and requirements.md §5.

Where the auditor checklist mentions Livewire, the Inertia/Vue rules in `.agent-workflow/CLAUDE.md` apply instead. PR size is not a finding.

## What was verified

- **Where PE-016 and PE-017 meet (the rebase).**
  - Shared props: `operator_navigation` and then `credits`, both closures, so a partial reload that names neither (History asks for `generations` and `credits` only) never runs the gate or the balance query. A guest gets `[]` and `null` (`SharedCreditBalancePropTest`, PE-016's `OperatorNavigationPropTest`), and `credits` holds only the three integers.
  - Header: the tab order is brand, Generate, History, Report (operators), the balance's tip button, Buy credits, then Sign out, the same DOM order at every width (`auth.spec.ts` walks it). The layout at 375 px was wrong for operators, and so was the one-row layout from 640 px (#1, fixed).
  - `routes/web.php`, `paths.ts`, `inertia.d.ts` and `tests/js/integration/support/inertia.ts` keep both stories' entries. requirements.md runs D-62–D-65, then D-67–D-71 (D-66 is the gap the decisions commit explains).
  - README: PE-016's "Operator report" line and "Operator access" section and this story's "Balances and Buy credits" section and runbook step both read correctly. On production the reviewer is the operator, and the backfill gives that account its opening grant like any other.
  - The three integration test fixes in plan.md's "Rebase onto main" still test what they meant to. `DemoUserSeederTest` selects the demo user by email, and `BuyCreditsPageTest` lists `operator_navigation` among the shared props. PE-016's `test_spend_reads_only_the_generations_charge_entry` can no longer insert a grant tied to a generation or a charge with no generation, because this story's CHECKs refuse both, so the schema now guarantees what those rows tested. Its remaining proof is that top-ups are never spend. The purchase was dated inside the range, but the factory's starter grant was dated at the real clock, outside it. Both top-ups are now inside the range (#3).
  - Five rebase SHAs in plan.md still named pre-rebase commits (#5, fixed).
- **AC 19 and AC 2.**
  - `OperatorReportIgnoresCreditTopUpsTest` follows the plan's design. It builds PE-016's worked example from the shared concern, with the clock frozen inside the range. R0 is read before any top-up. Then it adds the backfill (A 1,018,906,250, B 1,074,337,500, asserted), purchases for A and B, and a new user C with a starter grant and a $10.00 purchase. It asserts `R0 === R1` on the whole `toArray()`, R0's totals (3, 93,243,750, 92,075,000, 1,168,750), and that C has no per-user row. A report that summed every ledger entry in range would equal R0 before the top-ups (holds and releases cancel), then move by the top-ups, so `R0 === R1` fails. One that summed positive entries would fail the totals assertion. One that grouped users from the ledger would list C.
  - `OperatorUserSeeder` grants after it creates or updates the operator. Two runs leave exactly one grant, a pre-balances operator gets its grant, and in production it throws before writing a user or a ledger row (tested). The `production` guard is unchanged.
- **Migrations and the backfill on production.**
  - PE-016's `add_is_operator` (`…170247`) sorts before this story's single migration (`…171139`). Neither touches the other's table.
  - The migration runs in Laravel's Postgres migration transaction. Its CHECKs validate the existing rows: production holds only `hold`, `release` and `charge` rows, always with a generation (PE-007's writers), so they pass. If one ever failed, the DDL rolls back and the deploy stops before the symlink, with the old release still serving.
  - The previous release writes only valid rows under the new CHECKs, so `dep rollback` stays safe.
  - `after('artisan:migrate', 'artisan:app:grant-opening-credits')` runs on the new release's cached config, after migrate and before `deploy:publish`.
  - **Rehearsal on production-shaped data.** In a scratch database I migrated to PE-016's schema, then wrote, through the old schema, a reviewer/operator with two settled charges (218,625,000), one failed generation, one D-09 row and one active hold (123,387,500), another user with one charge (109,312,500), and an idle user. I then ran this story's migration and `app:grant-opening-credits` twice, at the production default.
    - The first run granted 3 users and the second 0.
    - The reviewer got 1,218,625,000 (starter + charges) and has 876,612,500 available with 123,387,500 reserved for 1 running.
    - The other two each got their opening grant and have exactly 1,000,000,000 available.
    - The scratch database was then dropped.
  - The backfill is one `INSERT … SELECT … ON CONFLICT (user_id) WHERE type = 'grant' DO NOTHING`. It is idempotent, it can't fail on a concurrent registration, and D-09 rows add nothing.
- **Earlier focus.**
  - The balance is one bigint-cast aggregate over the ledger, with no balance column. `reserved = −Σ(hold + release)` holds because every release is exactly minus its hold.
  - `CreateGenerationAction` takes the user-row lock first, inside the transaction that writes the hold, returns a replay before the check, and throws before any insert. `CreateGenerationActionConcurrencyTest` proves, with a second process, that the second create waits on the lock and is refused with 13,687,500 available. The lock mode is now `FOR NO KEY UPDATE` (#2).
  - The starter grant is given once: the partial unique index, `createOrFirst`, and one call per creation path, each in the user's transaction where it has one.
  - Buy credits is fake: no input fields, the demo badge, and `pack_nanodollars` validated as `integer` plus `Rule::in(config packs)` on the server. The purchase token is unique per user, and a replay returns the first purchase. Both routes are in the `auth` group.
  - Amounts are integer n$ throughout. PHP formats nothing. `money.ts` and `credits.ts` are both tested against `tests/fixtures/credit-shortfalls.json`, and so is PHP's `CreditBalanceTest`.
  - Config defaults are the production values: `CREDITS_STARTER_GRANT` defaults to 1,000,000,000, and packs are constants. `phpunit.xml` clears the env name in both places in `73868be`, the commit that first reads it.
  - **Production's `shared/.env` needs no new line.** `.env.production.example` (pinned by `ProductionEnvTemplateTest`) has no `CREDITS_` name, so production uses the $1.00 default. Only dev and E2E set 10,000,000, through the compose anchor and `.env.example`.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The operator's header broke at two widths. At 640 px the one-row header (brand, Generate, History, Report, balance, Buy credits, name, Sign out) was 35 px too wide, so the page scrolled sideways. Up to about 760 px the name shrank to 0 px. At 375 px the nav row ran 12 px past the gutter (QA's note), and a balance one digit longer (for example $12.0100) would have scrolled the phone page too. The reviewer is the operator on production. | `resources/js/layouts/AppLayout.vue:58` | Warning | Fixed | The one-row layout now starts at `md` instead of `sm`, and the second row is `flex-wrap`. Below `md`, when the nav and the balance don't both fit, the balance wraps onto its own right-aligned row. Measured at 375, 412, 640, 767, 768 and 1024 px: every item is inside the gutters, with no scroll and the name visible. `report.spec.ts` now checks no scroll, and that the balance, Buy credits and the buttons are inside the header's content box, at 375, 640 and 768 px. Without the fix it fails (`fd82bf4`). |
| 2 | `LockCreditBalanceAction` took `FOR UPDATE`, which also blocks the `FOR KEY SHARE` lock that every insert referencing `users.id` takes. So a webhook or reconciler settling another of the user's generations waited on any open create of that user (review S1). | `app/Domain/Billing/Actions/LockCreditBalanceAction.php:37` | Suggestion | Fixed | It now uses `lock('for no key update')`, which still conflicts with itself, so two creates still serialize (the concurrency test passes). A new `LockCreditBalanceActionTest` row pins the statement (`1aced7c`). |
| 3 | PE-016's spend test says the factory's starter grant is never spend, but the grant was dated at the real clock, outside the one-day range. Only the purchase could catch a report that sums the user's entries by date. | `tests/Feature/Reporting/ShowOperatorReportActionTest.php:195` | Suggestion | Fixed | The test freezes the clock on 2026-11-16 while it creates the generation, and checks first that the grant is dated inside the range (`c2258d4`). |
| 4 | The five new tests were appended after the private `lighthouseSubmittedWith()` helper (review S2). | `tests/Unit/Domain/Generation/Actions/CreateGenerationActionTest.php:374` | Suggestion | Fixed | The helper is back at the end of the class (`3616596`). |
| 5 | plan.md's "Rebase onto main" says the notes cite the rebased SHAs, but five still named pre-rebase commits (`3eb7383`, `1b438c6`, `17ac273`, `01f83f9`, `ccb380f`). | `.agent-workflow/stories/PE-017-credit-balances-buy-credits/plan.md:259` | Suggestion | Fixed | They now name `2a29899`, `6b1cd29`, `ac67c23`, `e605ec2` and `2d94e9d` (`9231e0f`). |
| 6 | The reserved label's leading "· " starts a line when History's money row wraps, and a screen reader may voice it (review S3). | `resources/js/components/history/HistoryItem.vue:170` | Suggestion | Skipped | It is cosmetic. Hiding the dot from assistive technology would not fix the wrapped line, and most screen readers skip a lone middle dot as punctuation. |
| 7 | Someone who registers on the old release in the seconds between the pre-symlink backfill and the PHP-FPM reload gets no grant until the backfill runs again. Until then their balance is zero or below, so they are blocked and can't overspend. | `deploy.php:103` | Suggestion | Deferred | The plan's Production steps 2–3 re-run the backfill and count accounts without a grant once the deploy finishes. Every later deploy re-runs it too. Running it from a post-reload hook would also fire on `dep rollback`, onto a release that may lack the command. |
| 8 | A negative balance uses the ASCII hyphen-minus ("-$0.0010"), not "−" (review W2). The header doesn't add "Top up to keep generating." (review W3). | `resources/js/lib/money.ts`, `resources/js/components/credits/HeaderBalance.vue` | Warning | Skipped | Both are documented deviations, D-69 and the Engineer notes, and the review accepted them. The amount is never $0.0000, and Generate's line carries the words and a link. |

No Critical issues were found. Warning #1 is fixed, and Warning #8 is a documented, accepted deviation.

## Checks after fixes

Run on `9231e0f`, after the last fix commit:

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `lane-make.sh l17 check` | Exit 0. Pint PASS (339 files). Larastan no errors. PHPUnit 1,925 passed (38,007 assertions), 1 more than QA's 1,924. Vitest 644 passed in 40 files. |
| Full E2E, desktop and mobile | `lane-make.sh l17 e2e` (after `restart-worker`) | Exit 0. 48 passed, 2 skipped (the existing touch-only and mouse-only rows of `generate.spec.ts`). |
| The new header assertion without the fix | `report.spec.ts` with `AppLayout.vue` reverted | Failed on both projects ("outside the content box 16–359"), then the fix was restored. |
| Lock, create and concurrency tests | `lane-make.sh l17 test "LockCreditBalanceActionTest\|CreateGenerationActionConcurrencyTest\|CreateGenerationActionTest\|CreateGenerationAffordabilityTest"` | 36 passed. |

**Lane database note.** One of my rehearsal commands lost its database override, so it ran against the lane's dev database `prompt_edit_l17` instead of the scratch one. It rolled back and re-ran PE-016's migration, rolled back this story's migration, and wrote three legacy users. I rebuilt that database with `lane-make.sh l17 fresh`, which migrates and seeds it: demo has $1.0100 and the operator $0.0100. I then restarted the worker and repeated the rehearsal in a separate scratch database. Only the lane's own dev data was affected. The test database, production and the other lanes' databases were not touched. The QA run's E2E rows in the lane database are gone, but qa.md records their counts.

## Commits

- `fd82bf4` [PE-017] Keep the operator's header inside the gutters at every width
- `1aced7c` [PE-017] Lock the user row FOR NO KEY UPDATE so settlements never wait
- `3616596` [PE-017] Keep CreateGenerationActionTest's helper after its tests
- `c2258d4` [PE-017] Date the spend test's starter grant inside the report range
- `9231e0f` [PE-017] Cite the rebased SHAs in the plan's rebase notes
- `[PE-017] Record audit` (this file and the story status)
