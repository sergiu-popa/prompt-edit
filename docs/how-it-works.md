# How it works

## How the money works

- **Integer nanodollars** (1 n$ = $0.000000001), stored as `BIGINT`, never floats. Pixelforge's per-1M-token rates are whole n$ per token (`"12.50"` → 12,500 n$/token). Provider cost is therefore exact and matches the 9-decimal `cost_usd` in `GET /v1/usage` digit for digit.
- **Billed at the generation's own rates.** Each generation is priced with the `rates` Pixelforge returns on it, never with a hard-coded table.
- **User price = ⌈provider × 125 / 100⌉.** It rounds up to the next nanodollar, so it never under-charges and over-charges by at most 0.75 n$.
- **The upfront estimate is an honest range.** Text tokens are ±15% of ⌈code points / 4⌉ and reasoning tokens vary by quality. One shared JSON fixture keeps the PHP and TypeScript estimators identical.
- **Exactly once, enforced by the database.** An append-only ledger holds the maximum at submit, charges the actual once on success (a unique constraint, not a convention) and releases the hold on failure.
- **Reconciles with Pixelforge.** The operator report buckets costs by Pixelforge's `completed_at` UTC day, the same way `/v1/usage` does, and checks every day against `/v1/usage`, exact to the nanodollar.

Worked examples and the full model are in [`requirements.md` §3](../.agent-workflow/requirements.md#3-money-model).

### Balances and Buy credits

- **The balance is the ledger.** There is no balance column that could drift (D-67). Available is the sum of a user's entries: grants and purchases add, holds of running generations and charges take. Reserved is what the active holds take. Provider cost lives on the generation, never in the ledger, so it never moves a balance.
- **Every account starts with $1.0000.** Registration, `app:upsert-user` (on create only) and the seeders each write one starter grant in the transaction that creates the user (`CREDITS_STARTER_GRANT`, D-70). A partial unique index allows one grant per user.
- **An unaffordable generation is refused up front.** The create action locks the user's row, reads the balance and refuses a max estimate it can't cover, all in the transaction that writes the hold (D-68). Two tabs therefore can't overspend. Generate shows the shortfall, rounded up to the next $0.0001, with a link to Buy credits, and the browser's check only disables the button early.
- **The actual can exceed the hold** when Pixelforge's rates change between estimate and creation. The charge is settled in full, a warning is logged, the balance may go negative ("-$0.0010") and every create is refused until a top-up.
- **Buy credits is fake.** The page at `/credits` offers $1.00, $5.00 and $10.00 packs, says "Demo — no payment is taken" and has no payment fields. Each visit sends one purchase token, unique per user in the database, so a double click adds one pack (D-71).
- **Existing accounts got an opening grant.** `app:grant-opening-credits` gives every user without a grant the starter plus their settled charges, so they start where a new account starts. It runs on every deploy and finds nobody after go-live.

## How it copes with Pixelforge

- **Local row first.** Its ULID is both the `Idempotency-Key` and `metadata.generation_id`. Retries are safe, and a webhook that arrives before the create response still finds its row.
- **Webhooks are verified, deduplicated and order-independent.** The HMAC-SHA256 signature over `"<t>.<raw body>"` is checked with a 5-minute tolerance. The event id is deduplicated in the same transaction as the state change, and the state machine only moves forward: a late `processing` can never undo `succeeded`.
- **Limits and overloads.** Every call takes from one shared budget of 50 requests a minute, below Pixelforge's 60. A `429` rate limit or concurrency limit and a `503` are retried after `Retry-After`, with the same idempotency key and byte-identical body. A slot that never frees up (10 min) or an overload that lasts (2 min) fails free; so does the daily limit, at once, and a key refused 3 times, with one critical log. History says "Pixelforge is busy — retrying automatically" or "Waiting for a free slot" while it waits. Definitive rejections (content policy, validation) fail free with a clear message.
- **Ambiguity is never failed.** A timeout, a 5xx or an unreadable answer may have created the generation, so it is retried with the same key and never failed locally. The submit job is bounded by time (30 min), not by tries; if the generation is still ambiguous then, it stays pending with its hold and is handed to the reconciler.
- **Lost webhooks.** A scheduled reconciler runs every minute. It polls generations Pixelforge confirmed but has sent no news about for 2–3 min, through the same transition path, so a poll and a webhook settle exactly once in either order. It sends at most 20 polls a run, always leaving 10 budget units of the minute to new submits, and checks each generation less often as it ages (every 1 min, up to every 15 min).
- **Dead or handed-over submits.** A generation Pixelforge never confirmed, whose submit job was handed over or stopped 5 min ago, is re-dispatched with the same ULID: Pixelforge replays a create that landed or creates it now. A per-generation lock keeps one create in flight. A day after creation it is given up as `lost` and free; one Pixelforge confirmed is never given up, only polled on.
- **Taking longer than usual.** After 15 min, a generation that is not done says "Taking longer than usual — we're checking with Pixelforge" on History.

Every failure mode is covered in the [robustness matrix](../.agent-workflow/requirements.md#4-robustness-matrix). The reasoning behind each choice is in the [decisions log](../.agent-workflow/requirements.md#5-decisions-log).

## Reading the operator report

Report is open to operators only (`app:grant-operator`, see [Operator access](production.md#operator-access)).

- **The range** is two UTC dates, both inclusive: by default the last 30 days ending today, at most 92 days, never past today (D-65). These are `/v1/usage`'s own rules, so the report asks Pixelforge for exactly the same range; a longer one is refused before anything is sent.
- **Three tables.** *Per day* and *Per user* show succeeded generations, spend (what users were charged), Pixelforge's cost and profit. *Pixelforge reconciliation* puts Pixelforge's own usage report for this API key ("Pixelforge says") beside what we recorded ("We recorded"), per UTC day and in total. Hover, focus or tap an amount for its exact 9-decimal value.
- **Match, Mismatch and Δ.** A day is a **Match** only when its provider cost and all four token counts (text, image, output, reasoning) are exactly equal integers: no tolerance, to the nanodollar (D-72). Anything else is a **Mismatch**, with its non-zero deltas in red. Δ is Pixelforge − ours, so `+` means Pixelforge billed more than we recorded. The total gets its own badge, against Pixelforge's own total.
- **In progress.** Today (UTC) is labelled In progress: generations still processing may complete later today and move its figures.
- **Why a mismatch happens.** Pixelforge counts every generation made with the API key. A mismatch usually means the key was used outside this app (for example a local test), generations were recorded before a database reset, or a generation finished at Pixelforge that we haven't recorded yet; the reconciler catches that up within minutes. This is why each environment has its own key.
- **Counts are not compared.** Generation counts are shown on both sides, but the docs don't say whether Pixelforge's count includes failed generations ([requirements §6](../.agent-workflow/requirements.md#6-open-questions--known-unknowns)).
- **Caching and outages.** A successful answer is reused for 60 s per range, so reloads stay off Pixelforge's 60 requests a minute; a failure is never cached (D-74). If Pixelforge is unreachable, slow (5 s), rate-limited or refuses the key, the page still renders our figures with "Pixelforge usage is unavailable right now…" and no badges.
- **Locally,** the fake Pixelforge answers `/v1/usage` from its own state, priced in decimals independently of our integer code (D-75), so a local or E2E report shows real parity.

## Stretch features

- **Operator report (PE-016):** spend, Pixelforge cost and profit per UTC day and per user, for any range up to 92 days.
- **Reconciliation with `/v1/usage` (PE-018):** every day and the total checked exactly against Pixelforge's own billing, with deltas and an explanation of any mismatch. Both are described in [Reading the operator report](#reading-the-operator-report).

Credit balances and Buy credits are [PE-017](../.agent-workflow/stories/PE-017-credit-balances-buy-credits/story.md), delivered in their own PR.
