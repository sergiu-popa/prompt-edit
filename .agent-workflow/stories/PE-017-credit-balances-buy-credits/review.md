# Review — PE-017-credit-balances-buy-credits

## Round 1

**Verdict: APPROVE**

Scope reviewed: the 25 `[PE-017]` commits `945ee15..1b0faba` on `pe-017-credit-balances-buy-credits` (`origin/main..HEAD`), against `story.md` and `plan.md`. They are the plan, 23 task commits (tasks 1–23), and the bookkeeping. Tasks 1–23 are checked. Tasks 24 and 25 are left open on purpose: the plan's "Deferred until PE-016 has merged" section assigns them to the orchestrator after the rebase onto `main` (see W1).

Checks run on HEAD (`1b0faba`) in lane l17:
- `lane-make.sh l17 check` exits 0. Pint (317 files) and Larastan are clean. PHPUnit runs 1,830 tests (37,493 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 619 tests in 37 files, all passing.
- `lane-make.sh l17 e2e` exits 0, with 46 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows of `generate.spec.ts`. The new `credits.spec.ts` passes on desktop (11.1 s) and mobile (10.5 s). The three updated "returned — not charged" assertions and the longer keyboard walk in `auth.spec.ts` pass too.

There is no Critical finding and no open Warning. Three Warnings are acknowledged, because each is a deviation the plan documents. Three optional Suggestions follow.

### Summary

The implementation follows the plan class for class. Its decisions are recorded as D-67 to D-71.

- **Balance model (AC 1, D-67):**
  - `CreditLedgerEntryQueryBuilder::balance()` (`app/Domain/Billing/QueryBuilders/CreditLedgerEntryQueryBuilder.php:36`) is one aggregate. Each sum is cast to `bigint` in SQL, so no amount passes through a float, and a non-integer result throws.
  - Reserved is −Σ(hold + release), and running is holds − releases. Both hold because `SettleGenerationCreditsAction` always releases a hold in full.
  - Provider cost never enters the ledger. The D-09 case is covered in `CreditLedgerEntryQueryBuilderTest`.
- **Schema (task 1):**
  - The migration adds the five-value type CHECK, and binds `generation_id` to exactly `hold`/`release`/`charge`. That closes PE-007's open guard.
  - It also binds `purchase_token` to exactly `purchase`, keeps top-ups positive, and adds the `one_grant_per_user` partial unique index.
  - The generation FK is `restrictOnDelete` (PE-007), so the new generation CHECK can never be broken by a nulled FK. `down()` reverses everything.
- **Starter grant (AC 2, D-70):**
  - `CreateNewUser` and `UpsertUserCommand` (on create only) write the grant in the transaction that creates the user. `DemoUserSeeder` writes it on every run.
  - Each path has a test proving one grant. The two transactional paths also have a "failing grant creates no user" test.
  - `GrantStarterCreditsAction` reads config at call time, which suits the Fortify singleton. Its `createOrFirst` uses a savepoint inside a transaction.
- **Backfill (AC 17, 18):**
  - `GrantOpeningCreditsAction` is a single `INSERT … SELECT … ON CONFLICT (user_id) WHERE type = 'grant' DO NOTHING`, so a run that races a registration cannot fail. The AC 17 numbers (1,093,243,750 → 1,000,000,000, and 978,150,000 with an active hold) are asserted.
  - `deploy.php` hooks it `after('artisan:migrate')`, before the symlink. README runbook step 2 says so, and the plan's Production steps cover the old-release window.
- **Affordability (AC 9–13, D-68):**
  - `CreateGenerationAction` takes the user-row lock first, returns a replay of a stored submission token before the check, and throws `InsufficientCreditsException` before any insert. Because the throw happens inside `DB::transaction`, a refusal writes no row, no hold and no job.
  - The exact boundary (equal is accepted, 1 n$ short is refused), the replay after the balance is spent, and a negative balance refusing even the cheapest request are unit-tested.
  - AC 12 is proven by a real second process that waits on the lock (`CreateGenerationActionConcurrencyTest::test_two_concurrent_creates_cannot_overspend`).
  - AC 6, 7, 8 and 13 are asserted to the nanodollar in `CreditBalanceSettlementTest`.
- **Refusal on the wire (AC 11):** the controller returns `back()` with the `balance` error and flashes integer n$. PHP formats nothing. `useGenerationForm` shows the server's numbers as an alert until an input changes, then switches to the advisory. After Back it falls back to the error text.
- **Display (AC 3–5, 14):**
  - The shared `credits` prop is a closure, so partial reloads that don't ask for it skip the query.
  - History polls `['generations', 'credits']`, and the E2E proves the header drops by exactly History's charge with no reload.
  - Generate's line, History's reserved and returned labels, and the disabled Generate button with its notice and Buy credits link all match the mockups. `money.ts` rounds shortfalls up and negative balances away from zero. The shared `credit-shortfalls.json` fixture holds PHP and TS to the same numbers.
- **Buy credits (AC 15, 16, D-71):**
  - Purchases go through `createOrFirst` on `(user_id, purchase_token)`, never through `SettleGenerationCreditsAction`'s conflict-blind writer (D-26). Only that index can conflict for a purchase row.
  - A replay naming another pack returns the first purchase. An unknown pack, a fractional pack and a bad token are field errors that record nothing.
  - The page has no input fields, shows the "Demo — no payment is taken" badge, and disables every pack while one is "Adding…".

Security:
- Both credit routes sit in the `auth` group.
- The pack amount is validated against config with `Rule::in`, so the client never chooses an amount.
- `#[Fillable]` gains only `purchase_token`.
- No amount or secret is logged. The command logs only a count.

The constant-field check: the shared `credits` prop returns `available_nanodollars`, `reserved_nanodollars` and `running_generations`, and the `credit_shortfall` flash returns required, available and shortfall. None is constant under its scope.

### Commit hygiene

- Each task commit carries its code with its tests, in plan order, and every subject matches the plan's. All subjects start with `[PE-017]`, are imperative and are at most 72 characters, and every message ends with the `Co-Authored-By` trailer.
- `CREDITS_STARTER_GRANT` is cleared in both places of `phpunit.xml` in the commit that first reads it (`5ba78ac`), so D-48 holds.
- The plan (`945ee15`), the decisions (`7a5a993`) and the bookkeeping (`1b0faba`) each have their own commit. No code commit touches an artifact `.md` file.
- Two commits explain an unexpected change in their body: `46d2f30` (the new tab stops in `auth.spec.ts`) and `7a5a993` (the D-number gap).
- `844c147` changes the `PurchaseCreditsController` added one commit earlier, from `back()` to `to_route('credits.index')`. It does so because that route only exists from that task, and the Engineer notes record it. That is fine.

### Warnings

- [x] **W1. AC 19, and AC 2's PE-016 operator seeder, are not covered on this branch.** — acknowledged: PE-016's report and operator seeder don't exist here. The plan defers both to tasks 24 and 25 ("Deferred until PE-016 has merged"), with the test design written down, for the orchestrator to do after the rebase onto `main`. The story can't be called done until those two commits land, so QA should treat AC 19 as pending, not failed.
- [x] **W2. A negative balance shows an ASCII hyphen-minus ("-$0.0010"), where AC 13 and the mockup write "−$0.0010".** — acknowledged: this is the documented deviation in D-69 and the plan's "The minus sign" clarification. It keeps `money.ts`'s existing sign glyph, the amount is never `$0.0000`, and Generate adds the words "Top up to keep generating." (`resources/js/components/credits/CreditBalanceLine.vue:31`), so the sign isn't the only cue.
- [x] **W3. The header doesn't add "⚠ Top up to keep generating." for a negative balance, which the mockup's "Negative balance" row shows.** — acknowledged: the Engineer notes record that it doesn't fit the 375 px nav row. The header shows the amount in red with its sign, and the Buy credits link is always beside it (`resources/js/components/credits/HeaderBalance.vue:30`). Generate's balance line carries the words and its own link.

### Suggestions

- [ ] **S1. Lock the user row `FOR NO KEY UPDATE` instead of `FOR UPDATE`.**
  - `app/Domain/Billing/Actions/LockCreditBalanceAction.php:32` uses `lockForUpdate()`. Postgres's `FOR UPDATE` conflicts with the `FOR KEY SHARE` lock that every insert referencing `users.id` takes. That includes `SettleGenerationCreditsAction`'s release and charge rows (`credit_ledger_entries.user_id` is an FK).
  - So, while a create for that user is open, a webhook or reconciler settlement waits on it, holding its generation row lock. That is not a deadlock, because the create never touches that generation. It is still needless contention, and it also blocks a concurrent `UPDATE users` (a login's `remember_token`).
  - `->lock('for no key update')` still serializes two creates (it conflicts with itself), which is all D-68 needs. Fix: switch to it, and keep `CreateGenerationActionConcurrencyTest` green.
- [ ] **S2. Keep the test methods above the private helpers in `CreateGenerationActionTest`.** The five new tests (`tests/Unit/Domain/Generation/Actions/CreateGenerationActionTest.php:384` onward) were appended after the private `lighthouseSubmittedWith()` helper (`:374`), so a reader scrolling the file meets a helper mid-way. Fix: move the helper back to the end.
- [ ] **S3. Name the "reserved" separator in the markup rather than in the text.** `resources/js/components/history/HistoryItem.vue:170` renders the reserved label as its own `<p>` in a `flex-wrap` row, beginning with "· ". When the row wraps on a narrow card, the paragraph starts a new line and its leading dot reads as a stray bullet. A screen reader also announces it. This is cosmetic only, and the E2E pins `'· $0.0863 reserved'`. Fix: make the dot `aria-hidden` decoration (for example `<span aria-hidden="true">·</span>`), and update the assertions to match.
