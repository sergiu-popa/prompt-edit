# QA — PE-017-credit-balances-buy-credits

**Verdict: QA FAILED**

Run on branch `pe-017-credit-balances-buy-credits` at `879e6dc` in lane l17. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 1).

## Failing items

- **AC 19 (operator report ignores grants and purchases): no test and no operator report on this branch.** Plan tasks 24 and 25 are unchecked and marked "deferred until PE-016 has merged" (review W1). `tests/Feature/Reporting/` does not exist.
- **AC 2, operator-seeder path: not built.** PE-016's operator seeder does not call `GrantStarterCreditsAction` on this branch (plan task 25).

Everything else in the story was verified (below). Status stays `qa-ready`. Once tasks 24 and 25 land after the rebase onto `main`, re-run QA.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `lane-make.sh l17 check` | Exit 0. PHPUnit 1,830 passed (37,493 assertions). Vitest 619 passed (37 files). |
| Existing E2E, desktop and mobile | `lane-make.sh l17 e2e` | 46 passed, 2 skipped (existing touch-only and mouse-only rows in `generate.spec.ts`). `credits.spec.ts` passes on both. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-017/check.log` and `.../PE-017/e2e-full.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 balance from ledger only; released hold no longer counts; D-09 changes nothing | `CreditLedgerEntryQueryBuilderTest` (D-09 case); browser header matches ledger sum; DB check below | Pass |
| 2 one starter grant per path | `RegistrationTest`, `UpsertUserCommandTest` (create only), `DemoUserSeederTest`; browser header `$0.0100` after sign-up at all widths; DB 1 grant per user | Partial: operator seeder not built |
| 3 header on every signed-in page; History poll refreshes it | `SharedCreditBalancePropTest`, `GenerationHistoryLiveUpdatesTest`; browser header on Generate, Buy credits and History; header equals ledger after History poll, no reload | Pass |
| 4 Generate balance and reserved line | browser: `Available $0.9237 · $0.0863 reserved for 1 running generation` (start 1,010,000,000 n$) | Pass |
| 5 History reserved and returned labels | browser: `· $0.0863 reserved` while running; `$0.0863 returned — not charged` on failed | Pass |
| 6 worked example, max hold 86,312,500 n$ | `CreditBalanceSettlementTest::test_creating_the_worked_example_holds_its_max`; DB: every hold is 86,312,500 | Pass |
| 7 settle to the actual charge | `CreditBalanceSettlementTest::test_a_success_releases_the_hold_and_charges_the_actual`; browser charges 60,003,125 / 64,962,500 / 82,356,250, each within the estimate 58,162,500 to 86,312,500 | Pass (exact 74,331,250 is PHP-only: the fake's usage varies per run) |
| 8 failed returns hold exactly | `CreditBalanceSettlementTest::test_a_failure_returns_the_balance_to_exactly_what_it_was`; browser header identical before and after the failure at 375, 768 and 1440 | Pass |
| 9 server refuses unaffordable, no row, hold or dispatch | `CreateGenerationAffordabilityTest`, `CreateGenerationActionTest`; browser: stale tab refused and DB has no row for the refused attempt | Pass |
| 10 exact boundary (equal accepted, 1 n$ short refused) | `CreateGenerationActionTest` | Pass (PHP only, not driven in browser) |
| 11 shortfall copy, rounded up, field-level | browser copy exact at 375, 768 and 1440: `…but you have $0.0100 available. You need $0.0764 more.`; stale-tab refusal: `…you have $0.0610 available. You need $0.0253 more.` (25,296,875 n$ rounded up) | Pass |
| 12 two concurrent creates, exactly one accepted, never negative | `CreateGenerationActionConcurrencyTest::test_two_concurrent_creates_cannot_overspend`; browser race in three runs: exactly one of two stale tabs accepted each time (DB: 14, 13 and 13 succeeded = window + 1) | Pass |
| 13 over-hold settle goes negative, logged, blocks next create | `CreditBalanceSettlementTest::test_a_charge_above_the_hold_goes_negative_is_logged_and_blocks_the_next_create` | Pass (PHP only; negative state not reachable in browser without a rate change) |
| 14 Generate disabled with reason and Buy credits link | Vitest `Create`, `ShortfallNotice`; browser: button disabled, shortfall shown, link goes to `/credits` at all widths | Pass |
| 15 fixed packs, "Demo — no payment is taken", no fields, guest redirect | `BuyCreditsPageTest`, Vitest `Buy`; browser: 0 form fields, label present, guest `/credits` goes to `/login` | Pass |
| 16 purchase adds one entry; double click or replayed token records once; unknown pack rejected | `PurchaseCreditsTest` (replay, unknown pack, invalid token); browser: double click on Add $1.00 gives `$0.0100` → `$1.0100` with one purchase row | Pass |
| 17 opening-grant amount | `GrantOpeningCreditsActionTest` | Pass (PHP only) |
| 18 backfill idempotent; deploy step | `GrantOpeningCreditsCommandTest`; deploy hook read in review. Not run: no SSH or `dep` per instructions | Pass (PHP); deploy step not exercised |
| 19 operator report unaffected by grants and purchases | none; no operator report on this branch | **Fail: not built (plan task 24)** |

## Edge cases probed

- Two stale tabs submitting at the same time, in three adaptive runs: exactly one accepted, the other refused with the shortfall copy.
- Double click on Add $1.00: one purchase.
- Double click on Generate in a stale tab: no extra generation row.
- Guest on `/credits`: redirected to `/login`.
- Failed generation: hold returned to the exact prior balance, at every width.
- Submit limit: after 10 generations within about a minute the server answered `You're submitting too quickly. Try again in 58 s.` (PE-013 throttle, expected). The pass waited it out.
- Not driven in browser: the 1 n$ boundary, the negative balance, backfill, and a replayed purchase token from the UI. These are PHP tests only.

## Visual verification

Desktop Chrome project, viewport set to 375, 768 and 1440 px, signed in as a new user (E2E starter). Screens: Generate (empty, shortfall, affordable, running), Buy credits (before and after purchase), History (processing, succeeded, failed), and a stale-tab refusal at 1440.

- **No horizontal scroll:** `scrollWidth - clientWidth = 0` on every screen at every width (30 measurements, all 0).
- **No console or page errors:** the E2E fixture fails a test on any console or page error, and no width pass failed for one. The two-tab race spec did not finish, so its pages were not checked by the fixture.
- **Measured values:**
  - Header at sign-up: `$0.0100` (exact 10,000,000 n$).
  - Generate running: `Available $0.9237 · $0.0863 reserved for 1 running generation`.
  - Succeeded: header after the History poll equals `1,010,000,000 − charge` exactly, with no reload (charges 60,003,125, 64,962,500 and 82,356,250).
  - Failed: `$0.0863 returned — not charged`; header unchanged.
  - Buy credits: `✓ $1.00 added. Balance $1.0100`; header `$1.0100`; 0 form fields.
- **Screenshots** (32 PNGs, named `NN-screen-width.png`): `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-017/shots-run1/`. Stale-tab refusal at 1440: `.../shots-run1/11-stale-tab-refused-1440.png`. Shortfall at 375: `.../shots-run1/03-generate-shortfall-375.png`. Looked at directly: the shortfall box with its Buy credits link, the succeeded card at 375, and the refusal at 1440.

## Database checks (lane DB `prompt_edit_l17`, read-only)

For the eight pass users with activity:

- Each user has 1 grant (10,000,000 n$) and 1 purchase (1,000,000,000 n$).
- Balance equals the ledger sum for every user. Reserved is 0 for all, since nothing was in flight.
- Each generation has 1 hold equal to its max, and 1 release equal to that hold. Each succeeded one has 1 charge equal to `user_charge_nanodollars`. Failed ones have no charge.
- Totals: 68 generations (65 succeeded, 3 failed), 68 holds, 68 releases, 65 charges, 8 grants, 8 purchases. No issues found.

## Cleanup and counts

- Temporary specs (`tests/e2e/qa-pe017-pass.spec.ts`, `tests/e2e/qa-pe017-race.spec.ts`) deleted. `test-results/` removed. `git status` clean.
- Data is append-only and left in place. Users created by the QA passes: 11. Eight have activity (68 generations, listed above). Three are from the aborted first pass and hold only the starter grant.
- The full `make e2e` run also created about 64 users and their generations in the lane DB. Those are not part of this QA count.
- Two stalled Playwright run containers were stopped (lane containers only).

## Notes (non-blocking)

- Harness issue, not app behaviour: the two-tab race spec stalled when Playwright clicked a stale background tab. The spec now brings each tab to the front before its click, and the race then ran.
- The lane's E2E starter is `$0.0100` (`CREDITS_STARTER_GRANT`, D-71), not the `$1.0000` default.
- The fake driver's usage varies per generation, so the AC 7 worked example can only be checked in PHP.
- Review suggestions S1 to S3 were not re-checked here.
