# Story: PE-007-credit-ledger-hold-settle-release

## Title
Append-only credit ledger: hold the max estimate, charge the actual exactly once, failed is free

## Status
`qa-ready`

## Description
Grading criterion #1 is correct money: every generation is recorded at exactly the right cost, never twice, and failed generations are free. This story makes that true end to end. It uses an append-only credit ledger from the first money write (D-08), so balances (PE-017) and the operator report (PE-016) later read entries that never change, not totals that do.

Creating a generation holds the user's **max** estimated price (D-14). On `succeeded` we do three things:

- record what Pixelforge bills us: the generation's own usage × the generation's own rates;
- charge the user that amount +25%, rounded up to the next nanodollar (D-04);
- release the hold.

On `failed`, the hold is released and nothing is charged.

A second charge must be impossible, not merely unlikely. A forward-only status guard runs inside the transition's locked transaction (D-06), and DB unique constraints back it up.

No UI. Amounts become visible in PE-012 (history) and PE-017 (balance).

## Acceptance Criteria
Worked values use the live rates (requirements §2.2): 5,000 / 10,000 / 40,000 / 12,500 n$ per input-text / input-image / output / reasoning token. User charge = ceil(provider × 125 / 100). Every amount is asserted as an exact integer.

**Lighthouse** is the docs example:
- prompt `A lighthouse on a cliff at dawn, oil painting`: 45 code points, so e = 12 and the text bounds are 10–14;
- `high`, `1536x1024`, 1 reference image;
- max provider estimate 14×5,000 + 765×10,000 + 6,450×40,000 + 8,000×12,500 = 365,720,000 n$ ($0.365720000);
- max user estimate 457,150,000 n$ ($0.457150000).

**Hold on creation**
1. Creating the Lighthouse generation writes exactly one `hold` entry for its owner, referencing the generation, of **−457,150,000 n$ (−$0.457150000)**. The amount is the generation's stored max user estimate, not a recomputation.
2. The docs' maximum case holds **−467,343,750 n$ (−$0.467343750)**, from the docs' max provider cost of 373,875,000 n$ ($0.373875000). That case is 400 code points (e = 100, text bounds 85–115), `high`, `1536x1024` and 2 refs.
3. The generation row and its hold commit together. If the hold write fails, no generation row remains. A generation never exists without its hold, and a hold never exists without its generation.
4. While a generation is non-terminal (`pending`, `queued`, `processing`), its provider cost and user charge are unset: null, not 0. Applying a `queued` snapshot (a create response) or a `processing` snapshot writes no ledger entry.

**Settle on success**
5. A succeeded Lighthouse snapshot arrives with usage 12 / 765 / 6,450 / 3,184 (text / image / output / reasoning) and rates `5.00` / `10.00` / `40.00` / `12.50`.
   - Provider cost is stored as **305,510,000 n$ ($0.305510000)** (60,000 + 7,650,000 + 258,000,000 + 39,800,000).
   - User charge is stored as **381,887,500 n$ ($0.381887500)**.
   - In the same transaction as the status change, the ledger gets a `release` of **+457,150,000 n$** and a `charge` of **−381,887,500 n$**.
   - The generation's ledger net is **−381,887,500 n$**.
6. The docs maximum end to end: the AC 2 generation succeeds with usage 115 / 1,530 / 6,450 / 8,000.
   - Provider cost is 373,875,000 n$. The charge is 467,343,750 n$, exactly the hold, and the net is −467,343,750 n$.
   - No “above hold” warning is logged. Actual = hold is the boundary, not an anomaly.
7. Costs come from the snapshot's own rates, never from the pinned table, and the markup rounds up.
   - The generation: a 50-code-point prompt (e = 13, bounds 11–15), `low`, `1024x1024`, no refs. It holds −21,906,250 n$, from a max provider cost of 15×5,000 + 280×40,000 + 500×12,500 = 17,525,000 n$.
   - Its succeeded snapshot carries rates `5.001` / `10.00` / `40.00` / `12.50` and usage 13 / 0 / 280 / 250.
   - Provider cost = 13×5,001 + 280×40,000 + 250×12,500 = **14,390,013 n$ ($0.014390013)**. The pinned table would give 14,390,000.
   - User charge = ceil(14,390,013 × 1.25) = ceil(17,987,516.25) = **17,987,517 n$ ($0.017987517)**.
8. Actual above the hold, when rates change between estimate and creation (requirements §6). The AC 7 generation's snapshot carries rates `5.00` / `10.00` / `80.00` / `12.50`, with the same usage.
   - Provider cost = 65,000 + 22,400,000 + 3,125,000 = **25,590,000 n$ ($0.025590000)**.
   - The charge is **31,987,500 n$ ($0.031987500)**, above the 21,906,250 n$ hold.
   - The actual is charged in full and the full hold is released, so the net is −31,987,500 n$.
   - One warning is logged with the generation id, the hold and the actual.
9. A `release` is always the exact negation of that generation's own `hold` entry, never a recomputed estimate.

**Failed is free**
10. A provider `failed` snapshot is applied to the Lighthouse generation: `error.code` is `generation_failed` or `internal_error`, and `usage` is null.
    - Provider cost 0 and user charge 0 are stored.
    - One `release` of +457,150,000 n$ is written, and no `charge`. The net is 0.
11. Failing the generation through the transition action's local-failure path (PE-006) has the same money outcome as AC 10.

**Exactly once**
12. Applying the same succeeded snapshot twice in a row leaves exactly one `release` and one `charge` and unchanged costs, and raises nothing. Applying `failed` twice leaves exactly one `release`.
13. Once a generation is terminal, no later snapshot changes its ledger or costs. Each of these writes nothing:
    - a late `processing` after `succeeded`
    - `failed` after `succeeded`
    - `succeeded` after a provider `failed`

    A succeeded snapshot after a *local* failure is a different case, covered by AC 20.
14. The database itself rejects a second `hold`, `release` or `charge` for the same generation: a direct insert that bypasses the actions raises a unique violation. Entries without a generation, such as PE-017's future grants and purchases, are not limited by this constraint.
15. Backstop for a bypassed status guard: suppose a `release` and a `charge` already exist for a generation whose status is still non-terminal. Applying a succeeded snapshot completes without an exception or an aborted transaction, and exactly one of each still exists.
16. Concurrency, on separate DB connections:
    - Two workers applying the same succeeded snapshot produce exactly one `release` and one `charge`, and neither errors.
    - A succeeded and a failed snapshot applied at the same time produce exactly one terminal outcome: either `release` + `charge` or `release` only. A failed generation is never charged.
17. Atomicity: if any write in a settlement fails, the status change, the stored costs and every ledger entry of that transition roll back together. The generation stays non-terminal with only its hold.
18. Bad provider data is never rounded or guessed. A succeeded snapshot that has either problem below throws, writes nothing and logs the generation id. The generation stays non-terminal with only its hold.
    - a rate that is not a whole n$/token, e.g. `5.0001`, which is 5,000.1 n$/token
    - missing `usage`

**Append-only**
19. Updating or deleting a ledger entry through its model throws and leaves the row unchanged. Entries record a creation time and have no update time.

**D-09: provider succeeded after a local failure**
20. The Lighthouse generation was failed locally, so its hold was released and its net is 0. A succeeded snapshot then arrives with the AC 5 usage.
    - The status stays `failed`.
    - Provider cost 305,510,000 n$ is recorded, together with that snapshot's usage, rates and `completed_at`. PE-016 and PE-018 need them to match `/v1/usage`.
    - The user charge stays 0, no `charge` is written and the net stays 0.
    - An anomaly is logged with the local and provider ids.
    - Applying the same snapshot again changes nothing.

## Scope

### In Scope
- An append-only credit ledger in the Billing domain.
  - Entries are signed integer n$ amounts. Each has an owner, an optional generation and an entry type (`hold`, `release` or `charge`).
  - A DB unique constraint allows at most one entry of each type per generation.
- Provider cost and user charge (integer n$) on generations, written only inside the transition's locked transaction.
- The hold, written by PE-006's create action in the same transaction as the generation row (D-14).
- The money side effects of applied transitions:
  - succeeded: settle and release
  - failed, from the provider or locally: release only
- The actual-above-hold warning, the bad-data rollback (AC 18) and the D-09 path (AC 20).

### Out of Scope
- Balances, starter grants, purchases and affordability checks: PE-017-credit-balances-buy-credits.
- The operator report and `/v1/usage` reconciliation: PE-016-operator-report, PE-018-usage-reconciliation-and-final-signoff.
- Webhook transport and signature verification: PE-008-verified-idempotent-webhooks. Tests here call the transition action directly.
- Submitting to Pixelforge, and failing on definitive rejections: PE-009-pixelforge-client-and-submit-job.
- Showing estimated or actual cost: PE-011-submit-generation-and-history, PE-012-live-status-results-and-failure-copy.

## Technical Notes
- **Depends on:**
  - PE-001-money-nanodollars-and-markup: rate parsing, provider cost and the ceil markup.
  - PE-006-generation-record-and-transitions: the generation row with its stored estimate, the snapshot DTO, the create action, and the forward-only transition action with its local-failure path and row lock.
- **Decisions:** D-03, D-04, D-06, D-08, D-09 and D-14. Requirements §3 (money model), §4 (the “Charge recorded twice” row) and §6 (rates can change between estimate and creation).
- **Two layers of exactly-once** (requirements §4):
  - the forward-only status guard under `lockForUpdate`;
  - a unique constraint on generation-scoped ledger entries.

  Ledger rows add up, so they need the constraint. The cost columns don't: each is assigned a pure function of the snapshot, so re-applying it cannot double anything.
- **Postgres caveat:** a unique violation aborts the whole transaction. Expected duplicates (AC 15, AC 16) must therefore be conflict-tolerant inserts that end as a no-op, never as a 500. The Architect picks the mechanism, e.g. `ON CONFLICT DO NOTHING` or a savepoint.
- **Entry types:** they must accept `grant` and `purchase` later (PE-017) without migration churn, so avoid a native Postgres enum type. Postgres treats NULLs as distinct in unique indexes, so entries without a generation never collide.
- **Signs and integers:**
  - Hold and charge are negative, and release is positive.
  - Amounts are BIGINT n$. No floats anywhere.
  - With today's rates every provider cost is a multiple of 2,500 n$, so ×1.25 is always exact and the ceil never fires. AC 7's `5.001` rate is the only case that proves the ceil.
- **Reading sums:** Postgres `SUM(bigint)` returns `numeric`. Every reader (tests now, PE-016 and PE-017 later) must read sums as exact integers or decimal strings, never through `float`.
- **Negative nets, no backfill:** until PE-017 adds grants, a user's ledger net is negative; this is expected. No real generations exist before PE-011, so no data migration is needed.
- **Raw writes:** the model-level append-only guard does not stop raw query-builder writes. Nothing in the app may update or delete ledger rows.
- **Required test layers:**
  - PHP feature (DB-backed): every AC, with exact integer assertions. AC 16 needs real separate connections, so it cannot run inside `RefreshDatabase`'s wrapping transaction. That test class uses a non-transactional strategy (e.g. `DatabaseTruncation`).
  - PHP unit: only if a pure settlement calculation is extracted beyond PE-001 (the AC 5–8 arithmetic). Otherwise PE-001's unit tests and these feature tests suffice.
  - JS unit, JS integration, E2E: none (no UI).
- **PR size:** about 450–500 changed lines once the feature tests are counted, which is over the ~400 budget. Suggested cut:
  - PR 1: ledger, hold, settle and release, and the unique constraint (AC 1–14, 17 and 19).
  - PR 2: concurrency backstops, the bad-data rollback and D-09 (AC 15, 16, 18 and 20).

## Mockups / References
No UI.
- https://pixelforge.promptedit.dev/docs#pricing: rates, token tables, the $0.373875 worked maximum, and “use integers (for example nanodollars)”.
- https://pixelforge.promptedit.dev/docs#generation-object: `usage` is null until succeeded, `rates` are fixed at creation, and the succeeded example is the Lighthouse snapshot.
- https://pixelforge.promptedit.dev/docs#usage: a generation is billed on the UTC day it completed, which is why D-09 keeps `completed_at`.
- requirements.md §3, §4, §5 (D-03, D-04, D-06, D-08, D-09, D-14) and §6.
