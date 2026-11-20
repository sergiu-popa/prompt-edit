# QA — PE-017-credit-balances-buy-credits

**Verdict: QA PASSED**

Run 2 on branch `pe-017-credit-balances-buy-credits` at `2fbd224` in lane l17, after the rebase onto `main` (PE-016 merged) and tasks 24 and 25. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 1).

Run 1 failed on AC 19 and the AC 2 operator path. Both are now covered and pass (see the table). Nothing else changed.

## Failing items

None.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `lane-make.sh l17 check` | Exit 0. PHPUnit 1,924 passed (38,006 assertions). Vitest 644 passed (40 files). |
| Full E2E, desktop and mobile | `lane-make.sh l17 e2e` | 48 passed, 2 skipped (the existing touch-only and mouse-only rows in `generate.spec.ts`). |
| AC 19 test | `lane-make.sh l17 test OperatorReportIgnoresCreditTopUps` | 1 passed (20 assertions). |
| AC 2 operator seeder | `lane-make.sh l17 test OperatorUserSeeder` | 6 passed (21 assertions). |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-017/check-run2.log`, `.../PE-017/e2e-full-run2.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 balance from ledger only; released hold does not count; D-09 changes nothing | `CreditLedgerEntryQueryBuilderTest`; browser header equals ledger sum after settle | Pass |
| 2 one starter grant per path | `RegistrationTest`, `UpsertUserCommandTest`, `DemoUserSeederTest`, `OperatorUserSeederTest` (6 passed); browser header `$0.0100` after sign-up at 375, 768, 1440; DB: operator 1 grant after a re-seed | Pass |
| 3 header on every signed-in page; History poll refreshes it | `SharedCreditBalancePropTest`, `GenerationHistoryLiveUpdatesTest`; browser header equals `1,010,000,000 − charge` after the poll, no reload, at all widths | Pass |
| 4 Generate balance and reserved line | browser run 1: `Available $0.9237 · $0.0863 reserved for 1 running generation`; run 2 Generate after settle shows the available balance with no running part | Pass |
| 5 History reserved and returned labels | browser: `· $0.0863 reserved` while running (all widths) | Pass |
| 6 worked example, max hold 86,312,500 n$ | `CreditBalanceSettlementTest`; browser: header `$0.9237` = 1,010,000,000 − 86,312,500 at all widths | Pass |
| 7 settles to actual charge | `CreditBalanceSettlementTest`; browser exact tip equals 1,010,000,000 − History's charge at all widths | Pass |
| 8 failure returns hold exactly | `CreditBalanceSettlementTest`; browser run 1, identical before and after at all widths | Pass |
| 9 server refuses unaffordable request, no row, hold or dispatch | `CreateGenerationAffordabilityTest`, `CreateGenerationActionTest`; browser: shortfall blocks Generate, Buy credits link | Pass |
| 10 exact boundary | `CreateGenerationActionTest` | Pass (PHP only) |
| 11 shortfall copy, rounded up, field-level | browser at 375, 768, 1440: `This generation needs $0.0863 reserved (its maximum estimate), but you have $0.0100 available. You need $0.0764 more.` | Pass |
| 12 concurrent creates, one accepted, never negative | `CreateGenerationActionConcurrencyTest::test_two_concurrent_creates_cannot_overspend` | Pass (run 1 browser race also passed) |
| 13 over-hold settle goes negative, logged, blocks next create | `CreditBalanceSettlementTest` | Pass (PHP only) |
| 14 Generate disabled with reason and Buy credits link | `Create.test.ts`, `ShortfallNotice.test.ts`; browser: `generateDisabled: true` at all widths, link to `/credits` | Pass |
| 15 fixed packs, demo label, no fields, guest redirect | `BuyCreditsPageTest`, `Buy.test.ts`; browser: "Demo — no payment is taken", 0 input, textarea or select | Pass |
| 16 purchase adds one entry; double click or replayed token records once; unknown pack rejected | `PurchaseCreditsTest`; browser run 1 double click: one purchase; `$0.0100` → `$1.0100` | Pass |
| 17 opening-grant amount | `GrantOpeningCreditsActionTest` | Pass (PHP only) |
| 18 backfill idempotent; deploy step | `GrantOpeningCreditsCommandTest`; deploy hook read in review; not run here (no SSH or `dep` per instructions) | Pass (PHP); deploy step not exercised |
| 19 operator report unaffected by grants and purchases | `OperatorReportIgnoresCreditTopUpsTest` (1 passed); browser: operator's `/report` text identical before and after a Buy credits purchase (`reportUnchanged: true`) | Pass |

## Edge cases probed

- Demo user: no Report link at 375, 768, 1440; `GET /report` returns 403 at every width.
- Operator: nav is Generate, History, Report at every width; `/report` renders at every width.
- Operator seeded again (`lane-make.sh l17 seed`): still exactly one grant and one purchase; user count unchanged at 332.
- New user, shortfall and purchase flow at every width: no console errors (the E2E fixture fails on any).
- Two stale-tab race and the 1 n$ boundary: run 1 only, not re-run; PHP covers the boundary.

## Visual verification

Desktop Chrome project, viewport set to 375, 768 and 1440 px. Three passes: new user, operator, demo user. Each pass ran in its own invocation so the report comparison did not race the generations.

- **No horizontal scroll:** `documentElement.scrollWidth − clientWidth = 0` on every measured screen at every width (all passes).
- **No console or page errors:** the E2E fixture passed on every test.
- **Header overlap and off-canvas checks** (header `a`, `button` and `p` boxes): no overlaps and nothing off the viewport at 375, 768 and 1440, for the new user, the operator and the demo user.
- **Clipping:** no header item clipped (the name is truncated by design).
- **Non-blocking finding, operator at 375 px:** the second header row (nav, balance, Buy credits) runs to x=371, while Sign out ends at x=359 (the 16 px gutter). The Buy credits text is fully visible and nothing overlaps. The Report link causes it, so it appears for operators only. The demo user's row ends at 359. Worth tightening in a follow-up.
- **Measured values:**
  - Sign-up header: `$0.0100` at 375, 768, 1440.
  - Shortfall copy: exact at every width, `generateDisabled: true`.
  - Buy credits: `✓ $1.00 added. Balance $1.0100`; header `$1.0100`; 0 form fields.
  - Running (1 generation, `[succeed]`): header `$0.9237`; reserved `· $0.0863 reserved`.
  - Settled: header `$0.9366` (375), `$0.9252` (768), `$0.9244` (1440). Each equals `1,010,000,000 − charge` exactly, checked with no reload.
  - Operator header: `$1.0100` before the purchase test, `$2.0100` after it.
  - Operator report, Succeeded 265, Spend $31.2731, Provider cost $25.0185, Profit $6.2546: unchanged across the purchase.
  - Demo header: `$2.0000`. Explained under Database checks.
- **Screenshots** (31 PNGs, plus `measurements.jsonl`): `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-017/shots-run2/`. Viewed directly: `375-op-01-dashboard.png` (operator header), `375-02-shortfall.png`, `375-op-02-report.png`, `375-06-history-succeeded.png`.

## Database checks (lane DB `prompt_edit_l17`, read-only unless noted)

- Operator (`operator@promptedit.test`): 1 grant of 10,000,000 n$ and 2 purchases (one per operator pass, 1,000,000,000 each). Balance `2,010,000,000` = ledger sum. The purchases are append-only and cannot be deleted; they are the only fixture rows this run added to a seeded account.
- Demo (`demo@promptedit.test`): 1 grant of 1,000,000,000 n$ (created 17:25, before the lane's `CREDITS_STARTER_GRANT` became 10,000,000), 1 purchase of 1,000,000,000 n$. Balance `2,000,000,000` = ledger sum. Not a defect: the seeder's grant is idempotent, and that grant predates the current starter setting. A fresh `make seed` on an empty DB gives the plan's $1.0100.
- Lane starter config: `credits.starter_grant_nanodollars` = 10,000,000.
- Totals at the end of this run (read-only query): 337 users, 330 generations.

## Cleanup and counts

- Temporary spec `tests/e2e/qa-pe017-run2.spec.ts` deleted. `test-results/` removed. `git status` is clean.
- Users created by this run's passes: 8, each with 1 starter grant, 1 purchase and 1 generation, all succeeded (1 hold, 1 release, 1 charge per generation). That is 6 from the new-user pass, which ran twice (375, 768 and 1440 px each time), and 2 from the existing `report.spec.ts`, which my `operator` grep also matched. The full E2E suite created more users and generations, which are not counted here.
- Purchases added to the operator account: 2 (see Database checks). Append-only, so they stay.
- Re-seed (`lane-make.sh l17 seed`) added no users, grants or purchases.
- Fixture cleanup: none possible for these rows. The ledger is append-only, and the users and generations are kept as E2E data.

## Notes (non-blocking)

- Review Suggestion S1 is still open: `LockCreditBalanceAction` uses `lockForUpdate()`. It is not Critical, and `CreateGenerationActionConcurrencyTest` passes.
- The operator header alignment overrun at 375 px (see Visual verification) is for a follow-up. It does not overlap or clip.
- Harness only: Playwright clears its `test-results/` folder at the start of each run, so output was written outside it.
