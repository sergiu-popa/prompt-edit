# Story: PE-017-credit-balances-buy-credits

## Title
Credit balances, fake 'Buy credits', and blocking unaffordable generations

## Status
`draft`

## Description
This is stretch (b). Every user gets a credit balance derived from the PE-007 append-only ledger. There is no separate balance column that could drift. New users receive a starter grant. A clearly fake "Buy credits" button tops up the balance with fixed packs.

A generation whose **max** estimate is more than the available balance is blocked up front. The server makes the decision, under a user-row lock in the same transaction that writes the hold. The UI explains the shortfall and links to Buy credits.

Holds have existed since PE-007 (D-14), so in-flight generations already reduce what is available. Users therefore cannot overspend, even from two tabs.

Serves grading #1 correct money (the balance is the ledger sum, the hold is the max and settlement is the actual), #2 robustness (concurrent creates, double clicks), #3 UX (clear balance, reservation and shortfall copy) and the stretch balances feature.

## Acceptance Criteria

**Balance model**
1. Available balance = grants + purchases − holds still active − charges, read from the ledger alone. A released hold no longer counts.
    - Provider cost is stored on generations (PE-007), never in the ledger, so it never affects a balance. A D-09 generation (failed locally, succeeded at Pixelforge) changes nothing.
    - Reserved = the sum of the user's holds that are still active.
2. Every new user receives exactly one starter grant of `CREDITS_STARTER_GRANT` (default 1,000,000,000 n$ = $1.0000), whichever way the account is created:
    - registration;
    - the PE-004 account command, on create only. Re-running it for an existing user grants nothing;
    - a local seeder, such as PE-003's demo user or PE-016's operator.

    The header shows "$1.0000" immediately after sign-up.

**Display**
3. The app header shows the available balance on every signed-in page. While History polls (PE-012), each poll also refreshes the header balance and the reserved amounts, so a settled charge or a returned hold shows without a reload.
4. The Generate page shows the available balance and what is reserved for running generations, for example "Available $0.9137 · $0.0863 reserved for 1 running generation".
5. History shows the held amount on an in-flight generation ("$0.0863 reserved"). On a failed one it shows that the hold came back ("$0.0863 returned — not charged").

**Worked example** (prompt `A lighthouse at dusk [succeed]`, 30 code points, medium, 1024x1024, no references)
6. The estimate and the hold:
    - text tokens: e = ceil(30 / 4) = 8, so the text bounds are floor(8 × 0.85) = 6 to ceil(8 × 1.15) = 10;
    - max provider cost: 10 × 5,000 + 1,100 × 40,000 + 2,000 × 12,500 = 50,000 + 44,000,000 + 25,000,000 = 69,050,000 n$;
    - max user price, which is the hold: ceil(69,050,000 × 125 / 100) = 86,312,500 n$ ($0.086312500, shown as $0.0863);
    - typical: 72,237,500 n$ ($0.0722); min: 58,162,500 n$ ($0.0582).

    Starting from 1,000,000,000 n$, creating the generation leaves 1,000,000,000 − 86,312,500 = 913,687,500 n$ available ($0.9137), with 86,312,500 n$ reserved.
7. On `succeeded` with usage 8 / 0 / 1,100 / 1,234:
    - provider cost: 40,000 + 44,000,000 + 15,425,000 = 59,465,000 n$;
    - charge: ceil(59,465,000 × 125 / 100) = 74,331,250 n$ ($0.074331250);
    - the hold is released, and available becomes 1,000,000,000 − 74,331,250 = 925,668,750 n$ ($0.9257), with 0 reserved.
8. On `failed`, the hold of 86,312,500 n$ is released and available returns to exactly 1,000,000,000 n$.

**Affordability (server, authoritative)**
9. Generation creation checks affordability under a per-user lock, in the same transaction that writes the hold. If available < the server-computed max user estimate, the request is rejected. No generation row and no hold are written, and nothing is dispatched to Pixelforge.
10. The boundary is exact. Using AC 6's request, with a max of 86,312,500 n$:
    - available 86,312,500 n$ → accepted, and available becomes 0;
    - available 86,312,499 n$ → rejected with a shortfall of 1 n$.
11. The rejection is a field-level error on the Generate form that states the shortfall. Example: available 6,312,500 n$ ($0.0063) against a max of 86,312,500 n$ gives a shortfall of 80,000,000 n$ ($0.0800). The copy reads: "This generation needs $0.0863 reserved (its maximum estimate), but you have $0.0063 available. You need $0.0800 more."
    - Following PE-005 AC 6, the copy calls the max the amount reserved, never a guaranteed ceiling.
    - Amounts reach the page as integer n$ and are formatted only by `money.ts`.
    - The shortfall is rounded **up** to the next $0.0001, so the amount stated is always enough. A 1 n$ shortfall shows "$0.0001", never "$0.0000".
12. Two concurrent create requests come from the same user, with 100,000,000 n$ available and a max of 86,312,500 n$ each. Exactly one is accepted. The other is rejected, with available shown as 13,687,500 n$ ($0.0137). The balance is never negative.
13. If rates change between estimate and creation, the actual charge can exceed the hold. Example: available 86,312,500 n$, the hold takes it to 0, and the generation then settles at 87,312,500 n$.
    - The actual charge is still settled, because we owe Pixelforge.
    - Available becomes −1,000,000 n$, shown as "−$0.0010" with a minus sign.
    - A warning is logged with the generation id and both amounts.
    - Every further create is blocked until the user tops up.
    - Negative amounts are rounded away from zero, so they never show as "$0.0000".

**Client (advisory)**
14. While the TS max estimate exceeds the available balance, Generate on the Generate page is disabled, with the reason and a "Buy credits" link. It re-enables as soon as the inputs or the balance make the request affordable. This check is advisory only: AC 9 still applies to every request, including one sent from a tab with a stale balance.

**Buy credits (fake)**
15. The Buy credits page offers fixed packs of $1.00, $5.00 and $10.00 (1,000,000,000 / 5,000,000,000 / 10,000,000,000 n$).
    - It is labelled "Demo — no payment is taken".
    - It has no card or payment fields.
    - Guests are redirected to sign in.
16. Buying a pack adds one purchase entry and the header balance updates, for example from $0.0063 to $1.0063. A double click, or a resubmitted request carrying the same purchase token, records exactly one purchase, enforced by a DB unique constraint. An unknown pack is rejected with a validation error and records nothing.

**Opening-grant backfill for existing users**
17. A one-time artisan command gives every existing user who has no grant an opening grant of the starter grant + the sum of their settled charges. Their available balance at go-live therefore equals the starter grant minus any active holds, and those holds settle or release normally.
    - Example: charges 18,906,250 + 74,337,500 = 93,243,750 n$ and no active hold → opening grant 1,093,243,750 n$ → available 1,000,000,000 n$.
    - With an additional active hold of 21,850,000 n$ → available 978,150,000 n$ until that generation resolves.
18. The backfill is idempotent: a second run adds nothing, and users who registered after deploy are skipped, because they already have their starter grant. It runs on production as a deploy step, after migrations and before the new release serves balances. This extends PE-004's deploy command, which until now is only `php artisan migrate --force`, and the README runbook is updated to match.

**Reporting unaffected**
19. PE-016's spend, provider cost and profit are identical before and after grants, purchases and backfill entries are added; a test asserts it on the PE-016 dataset.

## Scope

### In Scope
- The ledger types `grant` and `purchase`.
- The available balance and the reserved amount, both derived from ledger entries only. Provider cost stored on generations is never part of them.
- Balance display:
  - the balance in the app header, refreshed by History's polling;
  - on the Generate page, the balance and the reserved amount;
  - in History, the reserved and returned labels.
- A starter grant on every user-creation path (registration, the PE-004 command on create, local seeders), and a one-time opening-grant backfill as a production deploy step.
- A fake "Buy credits" page with fixed packs and a demo label. A double click tops up only once.
- The server-side affordability check under a per-user lock, before the hold is written, with a field-level shortfall error.
- The advisory client-side disabled state, with a link to Buy credits.
- `money.ts` display rules: shortfalls round up, and negative amounts round away from zero.
- PHP unit and feature tests, JS unit and integration tests, and Playwright. Existing E2E users get enough starting credit.

### Out of Scope
- Real payments, refunds and payment providers.
- A credit statement or transaction history page.
- Admin credit adjustments.
- Changing the hold, settle or release mechanics (PE-007).
- Showing balances in the operator report. PE-016 and PE-018 stay spend-only.

## Technical Notes
- **Depends on:**
  - PE-007-credit-ledger-hold-settle-release: the ledger, holds and unique constraints.
  - PE-012-live-status-results-and-failure-copy: the History states this story annotates, and the polling this story extends to refresh the balance.
  - PE-015-production-go-live: a sequencing dependency only. Stretch comes after the required scope is live.
- **Decisions:**
  - D-03, D-04 and D-08: the balance is derived from the append-only ledger and never stored separately.
  - D-14 is shared with PE-007: the hold mechanics live there, and the enforcement lives here.
  - requirements.md §3, and §6 on rates changing between estimate and creation.
- **Ledger contents (PE-007):** entries are `hold`, `release` and `charge`, and this story adds `grant` and `purchase`. Provider cost and user charge also live on the generation, so the balance query never needs to exclude anything provider-related.
- **Ledger guards left open by PE-007:**
  - `unique(generation_id, type)` does not limit an entry without a generation, so nothing stops a `hold`, `release` or `charge` with a null `generation_id`. Once `grant` and `purchase` exist, add a CHECK that those three types always carry a generation.
  - `SettleGenerationCreditsAction` inserts with `ON CONFLICT DO NOTHING` and no conflict target (D-25), so it swallows any unique violation on the table. If this story adds another unique index, purchases and grants must not reuse that writer.
- **Locking:**
  - Check the balance and write the hold in one transaction, under the user-row lock, or two tabs can both pass the check and overspend.
  - Compute the balance in a single aggregate query, not per entry.
- **Which estimate decides:** the affordability check uses the server-side PHP estimator's max (PE-002). The TS mirror only drives the advisory UI.
- **Polling (PE-012):** History's partial reload must include the shared balance prop as well as the generations prop (AC 3). Otherwise the header stays stale until navigation.
- **Cheapest request:** the cheapest possible request is a 1-code-point prompt at low quality, 1024x1024, with no references. Its max is ceil((2 × 5,000 + 280 × 40,000 + 500 × 12,500) × 125 / 100) = ceil(17,460,000 × 1.25) = 21,825,000 n$. An E2E user seeded with 10,000,000 n$ can therefore afford nothing, which makes the blocked flow deterministic.
- Starter grant and pack amounts are config values in integer n$. A $1.0000 starter covers about 11 concurrent medium 1024x1024 holds (86,312,500 n$ each). Existing Playwright specs that create more than that need a larger seeded balance.
- "Buy credits" must be unmistakably fake: no card fields, and no payment wording beyond "Demo — no payment is taken".
- **Required test layers:**
  - **PHP unit:**
    - balance and reserved arithmetic over ledger entries, where a D-09 generation changes nothing;
    - the shortfall computation;
    - the opening-grant amount.
  - **PHP feature:**
    - the exact boundary: an equal balance is accepted; 1 n$ short is rejected with no row, no hold and nothing dispatched;
    - concurrent creates cannot overspend (AC 12);
    - a failure returns the balance to exactly its prior value;
    - a success settles to the actual (AC 7 numbers);
    - an over-hold settle goes negative and blocks the next create;
    - the starter grant is given once per creation path: registration, the account command on create (not on update) and the seeders;
    - purchase idempotency and an unknown pack;
    - the backfill amount and its idempotency;
    - the shared balance prop via `assertInertia`, including on History's partial reload;
    - the report is unaffected.
  - **JS unit:**
    - affordability (TS max vs balance);
    - shortfall rounding up, so 1 n$ shows as $0.0001;
    - a negative balance rounds away from zero.
  - **JS integration:**
    - the Generate page's disabled state, with its reason and Buy-credits link;
    - the header balance, including its update after a poll;
    - the reserved line;
    - the reserved and returned labels in History;
    - Buy-credits pack selection, with the demo label and the disabled "Adding…" state.
  - **E2E** (Playwright, fake driver):
    - a user with 10,000,000 n$ is blocked and sees the shortfall;
    - they buy $1.00 of credits;
    - they generate with `[succeed]`;
    - after Succeeded, the header balance has dropped by exactly the actual charge shown in History, without a reload.
- **PR size:** an estimated ~600 changed lines, clearly over the ~400 budget. Split into two stories before planning, one PR each:
  - PE-017a: ledger types, the balance and reserved amounts, the starter grant on every creation path, the backfill, and the header (with poll refresh) and Generate display.
  - PE-017b: affordability enforcement, the shortfall UX, Buy credits, the `money.ts` rounding rules and the E2E spec.

## Mockups / References
```text
Header (all signed-in pages; refreshed by each History poll)
┌─────────────────────────────────────────────────────────────────────┐
│ PromptEdit   Generate  History        Balance $0.9137  [Buy credits]│
└─────────────────────────────────────────────────────────────────────┘

Generate — affordable (estimate in PE-005's format)
│ Available $0.9137 · $0.0863 reserved for 1 running generation       │
│ Estimated cost $0.0582 – $0.0863, typically $0.0722                 │
│ [ Generate ]                                                        │

Generate — unaffordable (client advisory + server error)
│ Available $0.0063                                                   │
│ Estimated cost $0.0582 – $0.0863, typically $0.0722                 │
│ [ Generate ] (disabled)                                             │
│ ⚠ This generation needs $0.0863 reserved (its maximum estimate),    │
│   but you have $0.0063 available. You need $0.0800 more.            │
│   → Buy credits                                                     │

Negative balance (actual exceeded the hold)
│ Balance −$0.0010  ⚠ Top up to keep generating.  → Buy credits       │

Buy credits
┌─────────────────────────────────────────────────────────────────────┐
│ Buy credits                 DEMO — no payment is taken              │
│ ┌─────────┐ ┌─────────┐ ┌──────────┐                                │
│ │  $1.00  │ │  $5.00  │ │  $10.00  │                                │
│ │ [ Add ] │ │ [ Add ] │ │ [ Add ]  │                                │
│ └─────────┘ └─────────┘ └──────────┘                                │
│ (the button shows "Adding…" and is disabled while the request runs) │
│ ✓ $1.00 added. Balance $1.0063                                      │
└─────────────────────────────────────────────────────────────────────┘

History annotations (same card format as PE-011/PE-012)
│ ● Processing        Est. $0.0582 – $0.0863 · $0.0863 reserved       │
│ ✕ Failed            $0.0863 returned — not charged                  │
│ ✓ Succeeded         Charged $0.0743 · Est. $0.0582 – $0.0863        │
```
- requirements.md §3, §5 (D-08, D-14) and §6
- https://pixelforge.promptedit.dev/docs#pricing
