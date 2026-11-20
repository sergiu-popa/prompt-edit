# Plan — PE-017-credit-balances-buy-credits

## Approach
The ledger gains two positive, generation-free types, `grant` and `purchase`. The balance is one aggregate over a user's entries, with no balance column: available is the sum of all of them, reserved is what the active holds take, and only `charge` entries are ever spend. `CreateGenerationAction` takes the user-row lock first, reads the balance under it and refuses a new generation whose server-side max it cannot cover, all in the transaction that writes the hold. The refusal reaches Generate as a `balance` error plus flashed integer amounts. The browser only adds an advisory disabled state, and `money.ts` formats every amount, with shortfalls rounded up. A starter grant is written explicitly on every account-creation path, an idempotent opening-grant backfill runs as a deploy step, and a fake Buy credits page records one purchase per token.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and get `make check` green before committing.
- In the lane, `make <target>` means `lane-make.sh l17 <target>`.
- A task that first reads a new env name in config clears it in `phpunit.xml`, in both `<env … force="true">` and `<server>`, in that same commit (D-48). Only task 3 does.
- After task 20 changes `docker-compose.yml`, recreate the containers (`make up`, or the lane's `up`).
- After any task that changes action, command or config code (3, 5, 6, 12, 16, 20), run `make restart-worker` before `make e2e`.
- Run `make e2e` after tasks 9, 11, 17, 19 and 20, and as the final check after task 21. Task 11 updates three existing E2E assertions.
- Local data: after task 6, run `app:grant-opening-credits` once on the dev database, so accounts made before this branch get a grant. After task 20, run `make seed`, so the demo account gets its purchase. CI's E2E job migrates and seeds a fresh database.
- Tasks 9, 10, 11, 17, 18 and 19 use the `tailwindcss-development` skill. Check each at 375 px, in the existing zinc/sky visual language.
- Never change spend: reporting reads `charge` entries only. `grant` and `purchase` are never charges (D-67).

Tasks 1–11 are the balance half: the ledger types, the balance, starter grants, the backfill, and the header, Generate and History displays (story split PE-017a). Tasks 12–21 are the enforcement half: affordability, the shortfall UX, Buy credits, the dev/E2E starter and the E2E spec (PE-017b). The story's split moved `money.ts`'s rounding rules into the first half, because the header needs them. The cut point is after task 11 (see Size).

- [x] 1. Add the migration `add_grants_and_purchases_to_credit_ledger_entries_table` (Data model). Add `Grant` and `Purchase` to `CreditLedgerEntryType`, and update its docblock: both are positive, never tied to a generation, and never spend. Add `purchase_token` to `CreditLedgerEntry`'s `#[Fillable]` and `@property`. Test-first: `CreditLedgerEntryTest` rows. Commit: `[PE-017] Add grant and purchase entries to the credit ledger`
- [x] 2. Add `CreditBalance` and `CreditLedgerEntryQueryBuilder` (Backend), with `CreditLedgerEntry::newEloquentBuilder()`. Add the shared fixture `tests/fixtures/credit-shortfalls.json` (Tests). Test-first: `CreditLedgerEntryQueryBuilderTest`, `CreditBalanceTest`. Commit: `[PE-017] Derive available and reserved credit from the ledger`
- [x] 3. Add `config/credits.php` (Config) and `GrantStarterCreditsAction` (Backend). Clear `CREDITS_STARTER_GRANT` in both places of `phpunit.xml`. Test-first: `GrantStarterCreditsActionTest`. Commit: `[PE-017] Grant a configurable starter credit once per user`
- [x] 4. Give `UserFactory` its `afterCreating` starter grant, with `withCredits(int)` and `withoutCredits()` states. Narrow the five global ledger counts to generation entries: `GenerationFactoryTest` (a made row), `CreateGenerationActionTest` (the failing hold, which now creates its user before registering the `created` hook), `BootsWithoutPixelforgeTest`, `SubmitsGenerations::assertNothingWasCreated()` and `CreateGenerationThrottleTest`. Test-first: `UserFactoryTest`. Commit: `[PE-017] Give factory users the starter grant by default`
- [x] 5. Call `GrantStarterCreditsAction` on every creation path:
    - `CreateNewUser`: in one transaction with `User::create()`, before the limiter hit;
    - `UpsertUserCommand`: on create only, in one transaction with `updateOrCreate`;
    - `DemoUserSeeder`: after `updateOrCreate`, every run, since the action is idempotent.

    Update each docblock. Test-first: `RegistrationTest`, `UpsertUserCommandTest`, `DemoUserSeederTest` rows. Commit: `[PE-017] Give every new account its starter grant`
- [x] 6. Add `GrantOpeningCreditsAction` and `GrantOpeningCreditsCommand` (`app:grant-opening-credits`) (Backend). In `deploy.php`, add a task that runs the command and hook it `after('artisan:migrate', …)`, with a comment naming D-70: it runs before `deploy:symlink`, so the new release never serves a balance without a grant. Test-first: `GrantOpeningCreditsActionTest`, `GrantOpeningCreditsCommandTest`. Commit: `[PE-017] Backfill an opening grant for accounts without one`
- [x] 7. Share `credits` from `HandleInertiaRequests` (Backend). Add `types/credits.ts` (`CreditBalance`) and `sharedPageProps.credits` to `types/inertia.d.ts`. Give `tests/js/integration/support/inertia.ts` a `credits` prop (null by default) and `givenCredits()`. Test-first: `SharedCreditBalancePropTest`, and a `GenerationHistoryLiveUpdatesTest` row. Commit: `[PE-017] Share the signed-in user's credit balance with every page`
- [x] 8. Add `formatUsdRoundedUp` and `formatBalance` to `lib/money.ts`, and a `balance` prop to `MoneyAmount` (Frontend). Test-first: `money.test.ts` rows, including the fixture's `display` column, and a `MoneyAmount.test.ts` row. Commit: `[PE-017] Round shortfalls up and negative balances away from zero`
- [x] 9. Add `components/credits/HeaderBalance.vue` to `AppLayout`. Make History's `useLiveUpdates` reload `['generations', 'credits']` (Frontend). Test-first: `AppLayout.test.ts` and `Index.test.ts` rows. The existing `auth.spec.ts` header tests must pass unchanged. Commit: `[PE-017] Show the balance in the header and refresh it with History`
- [x] 10. Add `components/credits/CreditBalanceLine.vue` to the Generate page, above the estimate (Frontend). Test-first: `CreditBalanceLine.test.ts`, and a `Create.test.ts` row. Commit: `[PE-017] Show available and reserved credit on Generate`
- [x] 11. Add the reserved and returned labels to `HistoryItem` (Frontend). In the same commit, update the three E2E `[data-charge]` "Not charged" assertions (`submit.spec.ts:69`, `live-status.spec.ts:91` and `:180`) to the returned copy. Test-first: `HistoryItem.test.ts` rows. Commit: `[PE-017] Label reserved and returned holds on History`
- [x] 12. Add `LockCreditBalanceAction` and `InsufficientCreditsException`, and change `CreateGenerationAction`'s transaction (Backend). Fund the tests that need more than the default $1.00 with `withCredits()` (at least `CreateGenerationThrottleTest`: 11 Lighthouse holds). `CreateGenerationActionConcurrencyTest`'s docblock now says the child waits on the user-row lock. Test-first: `LockCreditBalanceActionTest`, and `CreateGenerationActionTest` rows. Commit: `[PE-017] Refuse a generation the balance cannot hold`
- [x] 13. Make `CreateGenerationController` turn `InsufficientCreditsException` into the `balance` error and the `credit_shortfall` flash (Backend). Add `flashDataType.credit_shortfall` and the `CreditShortfall` type (Frontend). Test-first: `CreateGenerationAffordabilityTest`. Commit: `[PE-017] Return a shortfall error to the Generate form`
- [x] 14. Give `tests/scripts/create-generation.php` an optional third argument, `dusk`, which sends AC 6's request and prints the refusal as JSON. Add the AC 12 method to `CreateGenerationActionConcurrencyTest`. No production change is expected. Commit: `[PE-017] Prove two concurrent creates cannot overspend`
- [x] 15. Add `CreditBalanceSettlementTest` (AC 6, 7, 8, 13). No production change is expected. Commit: `[PE-017] Prove balances settle to the actual or return in full`
- [x] 16. Add `credits.packs_nanodollars` to `config/credits.php` (Config), and add `PurchaseCreditsAction`, `PurchaseCreditsRequest`, `PurchaseCreditsController` and `POST /credits/purchases` (Backend). Test-first: `PurchaseCreditsTest`. Commit: `[PE-017] Record a fake credit purchase once per token`
- [x] 17. Add `ShowBuyCreditsController` and `GET /credits` (Backend), plus `pages/Credits/Buy.vue`, `components/credits/CreditPacks.vue`, `formatUsdCents` in `money.ts`, the `credits` paths and the `types/credits.ts` page props (Frontend). Test-first: `BuyCreditsPageTest`, `Buy.test.ts` and `money.test.ts` rows. Commit: `[PE-017] Add the demo Buy credits page`
- [x] 18. Add the Buy credits link to `HeaderBalance`, and the negative-balance "Top up to keep generating." line with its link to `CreditBalanceLine` (Frontend). Test-first: `AppLayout.test.ts` and `CreditBalanceLine.test.ts` rows. Commit: `[PE-017] Link Buy credits from the header and a negative balance`
- [x] 19. Add `lib/credits.ts` (`creditShortfall`), `components/credits/ShortfallNotice.vue`, and the `available` option and `shortfall` result of `useGenerationForm`. Wire them through `SubmitGenerationArea` and `Create.vue` (Frontend). Test-first: `credits.test.ts` (the fixture), `ShortfallNotice.test.ts`, and `Create.test.ts` rows. Commit: `[PE-017] Disable Generate with the shortfall until it is affordable`
- [x] 20. Start dev and E2E accounts at 10,000,000 n$:
    - add `CREDITS_STARTER_GRANT: ${CREDITS_STARTER_GRANT:-10000000}` to the `x-dev-bounds` anchor, and add D-71 to its comment;
    - add `CREDITS_STARTER_GRANT=10000000`, with a one-line comment, to `.env.example`;
    - `registerNewUser(page, testInfo, { topUp = true })` buys the $1.00 pack through the Buy credits page before returning on `/dashboard`;
    - `DemoUserSeeder` records one $1.00 purchase under its constant `DEMO_PURCHASE_TOKEN`.

    Test-first: `LocalEnvTemplateTest` rows (the value, and compose's default equal to it) and a `DemoUserSeederTest` row. Recreate the containers, restart the worker, then run the full E2E suite. Commit: `[PE-017] Start dev and E2E accounts at $0.0100`
- [x] 21. Add `tests/e2e/credits.spec.ts` (Tests). Commit: `[PE-017] Cover blocked, buy and generate end to end`
- [x] 22. Add the README section "Balances and Buy credits". Add the backfill to Deploy runbook step 2, and the demo account's $1.0100 to Local development (README). Commit: `[PE-017] Document balances, Buy credits and the opening grant`
- [x] 23. Append D-67 to D-71 to `requirements.md` §5, after the last row, which is D-61 on this branch and D-66 after the rebase. Commit: `[PE-017] Record the balance decisions`

### Deferred until PE-016 has merged (orchestrator, after rebasing onto `main`)
These two need PE-016's code, which this branch doesn't have. The Engineer, Reviewer and QA leave them unticked. See "Deferred test design".
- [x] 24. Add `tests/Feature/Reporting/OperatorReportIgnoresCreditTopUpsTest.php` (AC 19). Commit: `[PE-017] Prove the operator report ignores grants and purchases`
- [x] 25. Make PE-016's operator seeder call `GrantStarterCreditsAction` after it creates or updates the operator, and add a row to its seeder test: two runs leave exactly one grant (AC 2). Commit: `[PE-017] Give the seeded operator its starter grant`

## Story clarifications
- **One PR.** The story asks for a PE-017a/b split. Per `.agent-workflow/CLAUDE.md`, this is one PR with the split named as the cut point after task 11.
- **E2E starts accounts low (deviation, D-71).** The blocked flow needs an account with exactly 10,000,000 n$. That is below the cheapest max of 21,825,000 n$. No in-app path reaches such a balance deterministically:
    - fake charges are seeded by a random ULID;
    - `[slow]` holds last 10 s;
    - a seeded fixture user is used up by its first purchase, and the ledger can't reset it.

    So:
    - Dev and E2E set `CREDITS_STARTER_GRANT=10000000` (D-48 pattern), and production keeps the $1.00 default.
    - The existing specs still start with enough credit: `registerNewUser` tops up the $1.00 pack through the real page, which gives $1.0100. The most any existing spec holds is 2 Lighthouse holds, 914,300,000 n$.
    - The seeded demo user gets a $1.00 demo purchase, so the demo account has $1.0100 locally and in E2E.
    - AC 2's "$1.0000 after sign-up" is proven at the production default in PHP and in JS integration.
- **One grant per user.** The starter grant and the opening grant are both `grant` entries, limited to one per user by a partial unique index (AC 2, AC 18). The paths that run more than once rely on it: the seeders, the backfill and a replayed registration.
- **Starter grant is explicit, not a model event.** `DatabaseSeeder` runs `WithoutModelEvents`, and a `created` listener would also fire for every factory user. Each path calls the action in the transaction that creates the user, so an account never exists without its grant. `CreateNewUser` is a Fortify singleton, so `GrantStarterCreditsAction` reads the config at call time.
- **Factory users are funded.** `UserFactory` gives each created user a starter grant, the way every real account gets one, so the many existing tests that create generations keep passing. The backfill tests use `withoutCredits()` legacy users, and the boundary tests use `withCredits(n)`.
- **Create order under the lock (D-68).** Inside `CreateGenerationAction`'s transaction:
    1. Lock the user row and read the balance.
    2. A replay of the same submission token returns its first generation, with no check (D-38 holds even after the first hold spent the balance).
    3. Otherwise, a max the balance can't cover throws before anything is inserted.
    4. Then `createOrFirst` (kept as D-38's backstop), the hold and the dispatch.

    The estimate stays outside the transaction, as today.
- **No user lock in settlement.** Settlement only releases, or charges at most the hold, except AC 13's rate change. So a balance goes negative only through an above-hold charge. That charge is settled in full, `SettleGenerationCreditsAction`'s existing warning logs it, and every create is blocked until a top-up.
- **The refusal on the wire.** The redirect back carries:
    - the error key `balance`: "You don't have enough credit for this generation." (`CreateGenerationController::INSUFFICIENT_CREDITS_MESSAGE`);
    - `Inertia::flash('credit_shortfall', {required_nanodollars, available_nanodollars, shortfall_nanodollars})`.

    PHP never formats an amount. The page composes AC 11's copy from the flash. It falls back to the error text when there is no flash (Back restores errors but not flash).
- **Copy.** The shortfall copy is "This generation needs {required} reserved (its maximum estimate), but you have {available} available. You need {shortfall} more.", followed by a "Buy credits" link.
    - `required` uses `formatUsd`, `available` uses `formatBalance`, and `shortfall` uses `formatUsdRoundedUp`.
    - The advisory and the server error use the same component. The server's numbers win until an input changes, then the advisory takes over.
    - In the E2E case (10,000,000 n$ against 86,312,500 n$) it reads "$0.0863 … $0.0100 … You need $0.0764 more." The shortfall of 76,312,500 n$ is rounded up, so the amount stated is always enough.
- **The minus sign.** A negative amount keeps `money.ts`'s existing ASCII hyphen-minus: −1,000,000 n$ shows as "-$0.0010". The story writes it as a typographic "−". Negative display also never relies on colour alone: the header and the Generate line add the "Top up to keep generating." text. If PE-016 changes the sign glyph, the rebase updates PE-017's expectations to match.
- **History labels come from the max.** The hold is always the generation's `user_estimate_max_nanodollars` (`HoldGenerationCreditsAction`), and every failure releases it in full. So `HistoryItem` derives both labels from the existing prop, and `GenerationResource` doesn't change.
    - A non-terminal card adds a separate `data-reserved` element, "· $0.0863 reserved". It sits outside `[data-estimate]`, whose exact text the E2E asserts.
    - A failed card's `[data-charge]` reads "$0.0863 returned — not charged".
- **Purchases.**
    - The page makes one UUID `purchase_token` when it mounts, and a new one after each successful purchase. A double click, or a resent request, reuses it.
    - The first request wins, even when a replay names another pack (as in D-38). A replay redirects with the stored purchase's amount.
    - The pack buttons are disabled while a request runs, and the clicked one reads "Adding…".
    - Purchases are unthrottled fake money. Pixelforge spend stays bounded by D-39 and the 500/day cap. JS formats n$ exactly up to `Number.MAX_SAFE_INTEGER` (about $9.0M), which takes 900,000 $10 purchases to reach. That is accepted.
- **Header at 375 px.**
    - Phones: the brand row stays brand, name and Sign out (the E2E pins it). The nav row adds the balance and the Buy credits link, right-aligned, with the word "Balance" visually hidden below `sm`.
    - From `sm`: everything sits on one row, with the balance between the nav and the name.
- **Backfill semantics (AC 17, 18).** Every user with no `grant` gets starter + the magnitude of the sum of their `charge` entries, in one `INSERT … SELECT` that ignores a conflict on the one-grant index (`insertOrIgnoreUsing`).
    - Only that index can conflict for a grant row: its generation and token are null, so the `(generation_id, type)` and `(user_id, purchase_token)` indexes never match.
    - The statement's snapshot defines go-live. A generation that settled before it is a forgiven past charge. A hold still active at it settles normally afterwards.
    - D-09 rows have no charge entry, so they add nothing.
    - It is safe to re-run, and it is a permanent deploy step that finds nobody after go-live.
- **AC 19 cannot run here.** PE-016's report query doesn't exist on this branch. It is deferred task 24, with its design below.

## Config
| Key | Env | Default | Dev / E2E | Task |
| --- | --- | --- | --- | --- |
| `credits.starter_grant_nanodollars` | `CREDITS_STARTER_GRANT` | 1,000,000,000 ($1.0000), through `filter_var` (int, min 1, else the default) | 10,000,000, from the `x-dev-bounds` anchor and `.env.example` | 3, 20 |
| `credits.packs_nanodollars` | none | `[1_000_000_000, 5_000_000_000, 10_000_000_000]`, in whole cents | same | 16 |

Production's `shared/.env` needs no change.

## Data model
One migration on `credit_ledger_entries` (task 1). Its `down()` drops everything it adds.
| Change | Role |
| --- | --- |
| `purchase_token` uuid, nullable | The Buy credits idempotency key. |
| unique `(user_id, purchase_token)` | One purchase per token per user (AC 16). NULLs are distinct, so other entries never collide. Its leading `user_id` also serves the balance aggregate, since the table has no `user_id` index today. |
| partial unique index on `user_id` where `type = 'grant'` (`credit_ledger_entries_one_grant_per_user`) | Exactly one starter or opening grant per user (AC 2, 18). |
| CHECK: `type` is one of the five values | No unknown type is stored. |
| CHECK: `generation_id` is set if and only if the type is `hold`, `release` or `charge` | Closes PE-007's open guard, and keeps grants and purchases generation-free. |
| CHECK: `purchase_token` is set if and only if the type is `purchase` | |
| CHECK: a `grant` or `purchase` amount is > 0 | Top-ups only add. |

The append-only trigger and `unique(generation_id, type)` are unchanged. Every existing row satisfies the CHECKs, and the previous release only writes valid rows, so `dep rollback` stays safe.

## Backend
All classes are `final` and declare `strict_types`.
| Class / file | Location | Role |
| --- | --- | --- |
| `CreditLedgerEntryType` | `app/Domain/Billing/Enums/` | + `Grant` (`grant`), `Purchase` (`purchase`). |
| `CreditLedgerEntryQueryBuilder` | `app/Domain/Billing/QueryBuilders/` | `ownedBy(User\|int)`, and the terminal `balance(): CreditBalance`. `balance()` is one aggregate with bigint-cast sums: available is the sum of every amount; reserved is minus the sum of `hold` + `release` amounts (each release is exactly minus its hold); running is the count of holds minus the count of releases. Consumers: `HandleInertiaRequests`, `LockCreditBalanceAction`. |
| `CreditBalance` | `app/Domain/Billing/ValueObjects/` | readonly: `available`, `reserved` (`Nanodollars`) and `runningGenerations` (int). `shortfallFor(Nanodollars $required): Nanodollars` is zero when covered, else `required − available` (available may be negative). `toArray()` returns `{available_nanodollars, reserved_nanodollars, running_generations}`. |
| `LockCreditBalanceAction` | `app/Domain/Billing/Actions/` | `execute(User): CreditBalance`. It locks the user's row (`lockForUpdate`) for the caller's transaction and reads the balance under the lock. It throws `LogicException` outside a transaction. Consumer: `CreateGenerationAction`. |
| `InsufficientCreditsException` | `app/Domain/Billing/Exceptions/` | `forHold(Nanodollars $required, CreditBalance)`, with `required()`, `available()`, `shortfall()`, and `toArray()` in the flash's shape. |
| `GrantStarterCreditsAction` | `app/Domain/Billing/Actions/` | `execute(User): void`. It reads `credits.starter_grant_nanodollars` at call time, then `createOrFirst` on `(user_id, type = grant)`, so a second call adds nothing. Consumers: `CreateNewUser`, `UpsertUserCommand`, `DemoUserSeeder`, and deferred task 25. |
| `GrantOpeningCreditsAction` | `app/Domain/Billing/Actions/` | `execute(): int`, the number of users granted (Story clarifications, "Backfill semantics"). Consumer: the command. |
| `PurchaseCreditsAction` | `app/Domain/Billing/Actions/` | `execute(User, Nanodollars $pack, string $purchaseToken): CreditLedgerEntry`, the stored or replayed purchase. It uses `createOrFirst` on `(user_id, purchase_token)` and never `SettleGenerationCreditsAction`'s writer. Consumers: `PurchaseCreditsController`, `DemoUserSeeder` (task 20), and deferred task 24. |
| `CreateGenerationAction` | `app/Domain/Generation/Actions/` | Task 12: the lock, replay, check, create order (Story clarifications). The docblock names D-68. `@throws InsufficientCreditsException`. |
| `CreateGenerationController` | `app/Http/Controllers/Generation/` | Task 13: it catches `InsufficientCreditsException`, flashes `credit_shortfall`, and returns `back()` with the `balance` error. The constant `INSUFFICIENT_CREDITS_MESSAGE`. |
| `ShowBuyCreditsController` | `app/Http/Controllers/Credits/` | Renders `Credits/Buy` with `packs_nanodollars` from config. |
| `PurchaseCreditsController` | `app/Http/Controllers/Credits/` | Request, then the action, then `Inertia::flash('purchased_nanodollars', …)` and `to_route('credits.index')`. |
| `PurchaseCreditsRequest` | `app/Http/Requests/Credits/` | `pack_nanodollars`: required, integer, `Rule::in(config packs)`, "Choose one of the packs.". `purchase_token`: required, uuid, "Reload the page and try again.". `toPack()` returns `Nanodollars`. |
| `GrantOpeningCreditsCommand` | `app/Console/Commands/` | `app:grant-opening-credits`, non-interactive. It prints "Granted opening credits to N users." and logs the count at info. |
| `HandleInertiaRequests` | `app/Http/Middleware/` | The new key `credits`: a closure returning `balance()->toArray()` for a signed-in user, else null. Excluded closures never run on partial reloads. |
| `CreateNewUser`, `UpsertUserCommand`, `DemoUserSeeder` | existing | Task 5 (Tasks). `DemoUserSeeder::DEMO_PURCHASE_TOKEN` is a fixed UUID (task 20). |
| `UserFactory` | `database/factories/` | Task 4: by default `afterCreating` writes one grant of the configured starter. `withCredits(int $nanodollars)` writes that amount instead, and `withoutCredits()` writes none. |
| `config/credits.php` | `config/` | See Config. The docblock names D-70 and D-71. |
| `routes/web.php` | `routes/` | In the `auth` group: `GET /credits` → `credits.index`, `POST /credits/purchases` → `credits.purchases.store`. |
| `deploy.php` | root | Task 6. |
| `tests/scripts/create-generation.php` | `tests/scripts/` | Task 14: an optional `dusk` argument. It catches `InsufficientCreditsException` and prints `{rejected, available_nanodollars, required_nanodollars}`. |

## Frontend
| File | Role |
| --- | --- |
| `types/credits.ts` | `CreditBalance` (the shared prop), `CreditShortfall` (the flash), and the Buy page's `packs_nanodollars: number[]`. Each documents integer n$. |
| `types/inertia.d.ts` | `sharedPageProps.credits: CreditBalance \| null`, and `flashDataType.credit_shortfall?` and `purchased_nanodollars?`. |
| `lib/money.ts` | `formatUsdRoundedUp`: the magnitude rounded up to the next $0.0001, with the sign kept and never `<$0.0001`. `formatBalance`: below zero `formatUsdRoundedUp` (away from zero), otherwise `formatUsd`. `formatUsdCents`: 2 decimals for whole-cent amounts, and a `RangeError` otherwise (packs only). |
| `lib/credits.ts` | `creditShortfall(required, available): number`, 0 when covered. No Vue imports. |
| `lib/paths.ts` | `credits: '/credits'`, `creditPurchases: '/credits/purchases'`. |
| `components/ui/MoneyAmount.vue` | A `balance` boolean prop selects `formatBalance`. The exact tip is unchanged. |
| `components/credits/HeaderBalance.vue` | "Balance" plus the amount (`MoneyAmount balance`), red and with the sign when negative, and the Buy credits link (task 18). |
| `components/credits/CreditBalanceLine.vue` | "Available $0.9137 · $0.0863 reserved for 1 running generation" (with "generations" pluralised, and without the reserved part at 0). When negative: "Balance -$0.0010 · Top up to keep generating." plus Buy credits (task 18). |
| `components/credits/ShortfallNotice.vue` | Props `required`, `available`, `shortfall` and `announce` (`role="alert"` only for the server's refusal). The copy (Story clarifications) and a Buy credits link. |
| `components/credits/CreditPacks.vue` | One card per pack, labelled `formatUsdCents`, with an "Add" button. It owns the `useForm` POST and the purchase-token rule. Every button is disabled while a request runs, and the clicked one is busy, reading "Adding…". |
| `pages/Credits/Buy.vue` | A thin page: `PageHeader` "Buy credits", a "Demo — no payment is taken" badge, `CreditPacks`, and the flashed "✓ $1.00 added. Balance $1.0100" status. No input fields. |
| `composables/useGenerationForm.ts` | The new option `available` (a getter). It returns `shortfall` (`CreditShortfall \| null`): the server's flash while `errors.balance` is set and no input has changed, else the advisory from the TS max. `isSubmittable` also needs no advisory shortfall. |
| `components/generation/SubmitGenerationArea.vue` | An optional `shortfall` prop renders `ShortfallNotice` beside the button, and its id joins the button's `aria-describedby`. |
| `pages/Generations/Create.vue` | Passes `usePage().props.credits` to `CreditBalanceLine` and `useGenerationForm`. |
| `pages/Generations/Index.vue` | `only: ['generations', 'credits']`. |
| `components/history/HistoryItem.vue` | Task 11 (Story clarifications, "History labels"). |
| `layouts/AppLayout.vue` | Places `HeaderBalance` (Story clarifications, "Header at 375 px"). |
| `tests/e2e/support/auth.ts` | `registerNewUser`'s `topUp` option (task 20). |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-67 | The ledger adds `grant` and `purchase`: positive, never tied to a generation, never spend. A user's balance is one aggregate over their entries, with no balance column: available = Σ every amount; reserved = −Σ(hold + release); running = holds − releases. CHECKs bind `hold`, `release` and `charge` to a generation and purchases to a token, and keep top-ups positive; one partial unique index allows one grant per user | A derived balance can never drift from the entries that justify it (D-08). Each release is exactly minus its hold, so no per-generation join is needed. Spend stays "charges only", so reports ignore top-ups by construction. |
| D-68 | `CreateGenerationAction` locks the user row, reads the balance, lets a replay of the same submission return its first generation, then refuses when available < the PHP max, before inserting, all in the transaction that writes the hold. The refusal is a `balance` error plus a flashed `credit_shortfall` in integer n$. The TS check only disables Generate. Settlement takes no user lock | Two tabs serialize on one row, so the second reads the first's hold. Checking after the replay keeps D-38 true for a double click. Only an above-hold charge (rates changed) can make a balance negative, and the next create then refuses. |
| D-69 | `money.ts` formats balances and shortfalls: a shortfall rounds up to the next $0.0001; a negative balance rounds away from zero; a positive balance and the required amount round half away (D-25); packs show cents; the sign stays the ASCII hyphen-minus. One JSON fixture gives PHP's shortfall and TS's shortfall and display | The amount we say is missing is always enough, and a debt never reads as $0.0000. The server sends integers only, so there is one formatter. |
| D-70 | Every creation path (registration, `app:upsert-user` on create, the seeders) calls `GrantStarterCreditsAction` in the transaction that creates the user; `CREDITS_STARTER_GRANT` defaults to $1.00. `app:grant-opening-credits` gives each user without a grant the starter + their settled charges, as a deploy step after `migrate` and before the symlink | Explicit calls survive `WithoutModelEvents` and keep factories honest. The one-grant index makes every path, re-seed and re-run idempotent. Existing users start at the starter minus their active holds, the same as a new user. |
| D-71 | Buy credits is fake: fixed packs from config, one `purchase_token` per page visit renewed after each success, unique per user; a replay returns the first purchase. Dev and E2E start accounts at 10,000,000 n$ (compose anchor and `.env.example`), below the cheapest max (21,825,000 n$). `registerNewUser` tops up $1.00 through the real page, and the demo seeder records one $1.00 demo purchase | A double click records one purchase, decided by the database. The low start makes the blocked flow deterministic in E2E, while every existing spec and the demo account still start with enough credit. Production keeps $1.00. |

## Tests
| Test | Location | Covers |
| --- | --- | --- |
| `CreditLedgerEntryTest` (existing) | `tests/Unit/Domain/Billing/Models/` | Task 1, by raw inserts that expect a constraint violation:<br>- a hold, release or charge without a generation;<br>- a grant or purchase with one;<br>- a purchase without a token, and a grant with one;<br>- a zero or negative grant or purchase;<br>- an unknown type;<br>- a second grant for a user (another user's is fine);<br>- a repeated token for a user (the same token for another user is fine);<br>- grants and purchases are append-only too. |
| `CreditLedgerEntryQueryBuilderTest` | `tests/Unit/Domain/Billing/QueryBuilders/` | AC 1 arithmetic:<br>- a grant, a purchase, an active hold, a released hold, a charge, and another user's entries (excluded) give the expected available, reserved and running;<br>- no entries gives 0, 0, 0;<br>- a D-09 generation (failed locally, then a provider success through `TransitionGenerationAction`) changes nothing;<br>- sums come back as PHP ints. |
| `CreditBalanceTest` | `tests/Unit/Domain/Billing/ValueObjects/` | `shortfallFor` over every case of `credit-shortfalls.json`, and the `toArray()` shape. |
| `tests/fixtures/credit-shortfalls.json` | `tests/fixtures/` | Cases of `{name, available, required, shortfall, display}`:<br>- equal: 86,312,500 / 86,312,500 → 0;<br>- 1 n$ short: 86,312,499 → 1, "$0.0001";<br>- AC 11: 6,312,500 → 80,000,000, "$0.0800";<br>- AC 12: 13,687,500 → 72,625,000, "$0.0727";<br>- E2E: 10,000,000 → 76,312,500, "$0.0764";<br>- zero available → 86,312,500, "$0.0864";<br>- negative: −1,000,000 → 87,312,500, "$0.0874";<br>- surplus: 1,000,000,000 → 0. |
| `GrantStarterCreditsActionTest` | `tests/Unit/Domain/Billing/Actions/` | One grant of 1,000,000,000 by default; a second call adds nothing; the configured amount wins; an empty env means the default. |
| `UserFactoryTest` | `tests/Unit/Database/Factories/` | A created user has one starter grant; `withCredits(n)` has one grant of n; `withoutCredits()` has none; `make()` writes nothing. |
| `RegistrationTest`, `UpsertUserCommandTest`, `DemoUserSeederTest` (existing) | `tests/Feature/Auth/`, `tests/Feature/Console/`, `tests/Feature/Database/` | AC 2:<br>- registration writes exactly one grant, and the followed `/dashboard` has `credits.available_nanodollars` = 1,000,000,000 (the header's $1.0000);<br>- a failed registration writes none;<br>- the command on create writes one; on update, for a `withoutCredits()` user, it writes none;<br>- seeding twice leaves one grant (task 20: and one $1.00 purchase, available 2,000,000,000 in tests). |
| `GrantOpeningCreditsActionTest` | `tests/Unit/Domain/Billing/Actions/` | Legacy users are `withoutCredits()`, with generations from `GenerationFactory` (which holds and settles through the ledger):<br>- AC 17: charges of 18,906,250 + 74,337,500 → a grant of 1,093,243,750, available 1,000,000,000;<br>- with an extra pending hold of 21,850,000 → available 978,150,000;<br>- a failed and a D-09 row add nothing;<br>- a user with a grant is skipped;<br>- a second run returns 0. |
| `GrantOpeningCreditsCommandTest` | `tests/Feature/Console/` | Prints the count and exits 0; a re-run grants 0 users; users who registered after it keep their single starter grant. |
| `SharedCreditBalancePropTest` | `tests/Feature/Billing/` | Null for a guest. Exactly `available_nanodollars`, `reserved_nanodollars` and `running_generations` (ints) on Generate and History. An active hold shows as reserved. |
| `GenerationHistoryLiveUpdatesTest` (existing) | `tests/Feature/Generation/` | A partial reload of `generations,credits` returns both. The balance changes between two reloads after a settlement (AC 3). |
| `LockCreditBalanceActionTest` | `tests/Unit/Domain/Billing/Actions/` | Outside a transaction → `LogicException`; inside one → the balance. |
| `CreateGenerationActionTest` (existing) | `tests/Unit/Domain/Generation/Actions/` | Task 12:<br>- AC 10: 86,312,500 is accepted with available 0 afterwards; 86,312,499 throws with a shortfall of 1, and leaves no generation, no hold and no pushed job;<br>- a replay of an accepted submission returns the first generation after the balance is spent;<br>- a negative balance refuses every create. |
| `CreateGenerationAffordabilityTest` | `tests/Feature/Generation/` | HTTP:<br>- AC 11: with 6,312,500 available, the POST redirects to `/dashboard` with the `balance` error and `assertInertiaFlash('credit_shortfall', …)` of required 86,312,500, available 6,312,500 and shortfall 80,000,000 (ints);<br>- followed, the page `hasFlash`;<br>- nothing is created (AC 9);<br>- an equal balance still lands on History. |
| `CreateGenerationActionConcurrencyTest` (existing) | `tests/Unit/Domain/Generation/Actions/` | AC 12, on real Postgres with truncation:<br>- the parent, in an open transaction, creates AC 6's request for a `withCredits(100_000_000)` user;<br>- the child (`dusk`, its own token) must wait on a lock;<br>- the parent commits;<br>- the child is refused with available 13,687,500;<br>- one generation and one hold remain; the ledger net is 13,687,500 and never below 0. |
| `CreditBalanceSettlementTest` | `tests/Feature/Billing/` | Starting at 1,000,000,000 with AC 6's request:<br>- the hold leaves 913,687,500 and reserves 86,312,500 for 1 running (AC 6);<br>- `succeeded` 8/0/1,100/1,234 → 925,668,750 and 0 reserved (AC 7);<br>- `failed` → exactly 1,000,000,000 (AC 8);<br>- AC 13: start at 86,312,500, then settle usage 10/0/1,100/2,000 with a text rate of "85.00" → a charge of 87,312,500, available −1,000,000, the warning logged with the id and both amounts, and the next create refused with a shortfall of 87,312,500. |
| `PurchaseCreditsTest` | `tests/Feature/Credits/` | AC 15, 16:<br>- a guest is redirected to sign in;<br>- each pack adds one purchase of its amount and flashes it;<br>- the same token twice records one purchase, and the replay flashes the first's amount even when it names another pack;<br>- a new token records a second;<br>- an unknown pack (2,000,000,000) or a bad token is a field error and records nothing;<br>- 6,312,500 + $1.00 → `credits.available_nanodollars` 1,006,312,500. |
| `BuyCreditsPageTest` | `tests/Feature/Credits/` | A guest's GET → `/login`; signed in, it renders `Credits/Buy` with exactly the three `packs_nanodollars`. |
| `money.test.ts` (existing) | `tests/js/unit/lib/` | Tasks 8 and 17:<br>- `formatUsdRoundedUp`: 1 → $0.0001, 100,000 → $0.0001, 100,001 → $0.0002, −1 → -$0.0001;<br>- `formatBalance`: −1,000,000 → -$0.0010, 913,687,500 → $0.9137, 6,312,500 → $0.0063, 0 → $0.0000;<br>- `formatUsdCents`: 1e9 → $1.00, 1e10 → $10.00, and a non-cent amount throws;<br>- the fixture's `display` column. |
| `credits.test.ts` | `tests/js/unit/lib/` | `creditShortfall` over every fixture case (TS max vs balance). |
| `MoneyAmount.test.ts` (existing) | `tests/js/integration/components/ui/` | `balance` shows -$0.0010, with the tip -$0.001000000. |
| `AppLayout.test.ts` (existing) | `tests/js/integration/layouts/` | The header shows "Balance $0.9137". After `page.props.credits` changes (a poll), it shows the new amount. No balance for a guest. A negative balance is red with its sign. The Buy credits link goes to `/credits` (task 18). |
| `Index.test.ts` (existing) | `tests/js/integration/pages/Generations/` | The poll's `router.reload` asks for `['generations', 'credits']`. |
| `CreditBalanceLine.test.ts` | `tests/js/integration/components/credits/` | AC 4's line; "generations" plural; no reserved part at 0; the negative line with its link. |
| `HistoryItem.test.ts` (existing) | `tests/js/integration/components/history/` | Pending, queued and processing show "$0.0863 reserved" outside `[data-estimate]`. Failed shows "$0.0863 returned — not charged". Succeeded shows neither. |
| `Buy.test.ts` | `tests/js/integration/pages/Credits/` | The demo label; three packs ($1.00, $5.00, $10.00); no input element; clicking $1.00 posts `{pack_nanodollars, purchase_token}`, and with `givenVisitsStayPending` every button is disabled and that one reads "Adding…"; the success flash text; a new token after success, the same one after a pending double click. |
| `ShortfallNotice.test.ts` | `tests/js/integration/components/credits/` | AC 11's exact copy, the link to `/credits`, and `role="alert"` only when `announce` is set. |
| `Create.test.ts` (existing) | `tests/js/integration/pages/Generations/` | Task 10: the balance line. Task 19 (AC 14):<br>- with 6,312,500 available and AC 6's prompt, Generate is disabled and the notice and link show;<br>- a cheaper input or a larger balance re-enables it;<br>- `givenVisitsFailWith({ balance })` plus `givenFlash({ credit_shortfall })` shows the server's numbers as an alert;<br>- editing the prompt switches to the advisory. |
| `LocalEnvTemplateTest` (existing) | `tests/Unit/` | Task 20: `CREDITS_STARTER_GRANT` = 10000000, and compose's default equals it. |
| `credits.spec.ts` | `tests/e2e/` | AC 14–16, desktop and mobile:<br>- register with `topUp: false` → the header reads $0.0100;<br>- type `A lighthouse at dusk [succeed]` (Medium, Square) → Generate disabled, with the exact copy ending "You need $0.0764 more.";<br>- follow Buy credits → the demo label and no text box; Add $1.00 → "✓ $1.00 added. Balance $1.0100", and the header reads $1.0100;<br>- generate the same prompt → History; `markNoReload`; wait for Succeeded;<br>- the header's exact tip (`exactNanodollars`) equals 1,010,000,000 minus History's exact charge, with no reload;<br>- at 375 px, the Buy page and the header have no horizontal scroll. |

## Deferred test design (task 24, AC 19)
- **Dataset.** PE-016's AC 11 worked example, G1–G4, built through PE-016's own test helper. Users A and B are made with `User::factory()->withoutCredits()`, so no grant exists before the first read. Freeze the clock at 2026-11-17T12:00:00Z, so every entry added below has a `created_at` inside the range. That makes a top-up visible to any query that sums ledger entries by date.
- **R0.** Run PE-016's report for 2026-11-16 to 2026-11-17: the daily rows, the per-user rows and the totals, read the way its page prop is built.
- **Entries to add.**
    - `GrantOpeningCreditsAction`: assert A's grant is 1,018,906,250 and B's is 1,074,337,500.
    - `PurchaseCreditsAction`: $5.00 for A and $1.00 for B.
    - A new user C, `withoutCredits()`, given `GrantStarterCreditsAction` and a $10.00 purchase.
- **R1.** The same report, then:
    - `assertSame(R0, R1)` on the whole structure;
    - R0's totals are 3 succeeded, spend 93,243,750, provider cost 92,075,000 and profit 1,168,750;
    - C has no per-user row.
- If PE-016 exposes only the page, compare two `assertInertia` reads of the operator page instead of the query.

## Production steps (orchestrator, after merge)
No agent runs these, and nothing in `shared/.env` changes.
1. `dep deploy`. After `artisan:migrate` and before the symlink, it runs `app:grant-opening-credits` (task 6). **Safe to re-run:** the one-grant index makes every run idempotent.
2. Once the deploy finishes, re-run the backfill by hand. It catches anyone who signed up on the old release while the deploy switched over: `ssh admin@164.90.236.77 "cd /home/admin/web/prompt.sergiupopa.dev/current && php8.4 artisan app:grant-opening-credits"`. Expect "Granted opening credits to 0 users." (or a small number). **Safe to re-run.**
3. Check that no user lacks a grant, as a count only, in `current/`: `php8.4 artisan tinker --execute 'echo DB::table("users")->whereNotExists(fn ($q) => $q->from("credit_ledger_entries")->whereColumn("credit_ledger_entries.user_id", "users.id")->where("type", "grant"))->count();'`. Expect 0. **Read-only.**
4. `make smoke-production`. Then sign in as the reviewer: the header shows about $1.0000, minus any running holds. **Read-only.**

## Size
Estimated at about 2,400 changed lines without lockfiles: roughly 700 of PHP and Vue code and 1,700 of tests, fixtures and docs. That is far over the ≈ 400-line budget, but it is one PR by the project rule. Every commit is self-contained and green. If the user asks for a split, cut after task 11:
- the PE-017a branch carries the ledger types, balances, starter grants, the backfill and the displays (with header and Generate showing a balance but no Buy credits link yet);
- PE-017b carries tasks 12–23.

## Engineer notes
- Tasks 1–23 are done, one commit each. Tasks 24 and 25 stay open until PE-016 has merged.
- `auth.spec.ts`'s keyboard test does change, unlike what task 9 says. The header balance is a `MoneyAmount` button (the E2E reads its exact tip), and Buy credits is a link, so Tab now goes past both between History and Sign out. Both commits update the test.
- `CreditLedgerEntryTest` had two cases that wrote generation-free `hold`/`release` rows, which the new CHECKs refuse. They now use a factory generation's hold and two purchases.
- The header shows a negative balance in red with its minus sign. It does not add "Top up to keep generating.", which would not fit the nav row at 375 px. Generate's balance line says it, with the Buy credits link.
- In task 16, `PurchaseCreditsController` redirected `back()`, because `credits.index` only exists from task 17. Task 17 switched it to `to_route('credits.index')`.
- Every `useGenerationForm` input change marks the server's refusal as stale. It then hands over to the advisory, and a Back-restored `balance` error without the flash shows the error text.


## Rebase onto main (orchestrator, 2026-11-20)
PE-016 merged first (#26, `1a92c58`), so this branch was rebased from `52e0515` onto it, and the notes now cite the rebased SHAs. Both stories' additions were kept wherever they met: the shared props (`operator_navigation` then `credits`), the header, `routes/web.php`, `paths.ts`, `inertia.d.ts`, the test support and requirements.md (D-62–D-65, then D-67–D-71). Three tests only failed once the two stories met, so each fix sits inside the commit where it would first fail:
- PE-016's `test_spend_reads_only_the_generations_charge_entry` inserted rows that imitated this story's types, which this story's CHECKs now refuse: a grant tied to a generation, and a charge with no generation. In `2a29899` it inserts a valid grant and purchase. From `6b1cd29` it uses the factory's starter grant instead, since one grant per user is unique.
- `DemoUserSeederTest` used `User::query()->sole()` after a full `seed()`, which now also seeds PE-016's operator. In `ac67c23` and `e605ec2` it selects the demo user by email.
- `BuyCreditsPageTest` (`2d94e9d`) lists `operator_navigation` among the shared props.

Tasks 24 and 25 follow in their own commits.

## Engineer notes (QA fix round, after the rebase)
- Task 25 (`35646f3`): `OperatorUserSeeder` grants the starter credit after it grants access. Its test adds two runs leaving one grant, a pre-balances operator getting its grant, and no ledger row when production is refused.
- Task 24 (`f6c2cb0`): PE-016's worked example was private to `OperatorReportWorkedExampleTest`, so it moved into `tests/Concerns/BuildsTheOperatorReportWorkedExample.php` and both tests build it from there. No PE-016 production code changed. The new test compares `toArray()` of R0 and R1, the report's page-prop shape.
