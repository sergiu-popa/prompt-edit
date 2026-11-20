# Story: PE-018-usage-reconciliation-and-final-signoff

## Title
Provider costs reconcile with GET /v1/usage; final production sign-off

## Status
`done`

## Description
This is stretch (a), part two, and the final proof on the real deployment. The brief asks that the operator report's provider costs match `GET /v1/usage`, which Pixelforge calls the "source of truth for that key's provider costs". The PE-016 report gains, per UTC day, Pixelforge's own provider cost and token counts next to what we recorded. Each day shows the deltas and an exact integer Match or Mismatch badge.

This story comes last for two reasons. First, `/v1/usage` is scoped to the calling key, so parity can only be proven on production data. Second, image URLs expire 24 h after completion. So the second half of the story refreshes the demo generations shortly before hand-in, confirms that every day matches, and finishes the README.

Serves grading #1 correct money (verified externally, to the nanodollar), #2 robustness (the report survives Pixelforge being unreachable), #3 UX (mismatches are explained), the stretch operator report and the required public deployment.

## Acceptance Criteria

**Contract and parsing**
1. The Pixelforge contract gains a usage operation for an inclusive UTC date range.
    - The HTTP driver calls `GET /v1/usage?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD`.
    - It returns typed usage data: one row per day (`date`, `generations`, `input_text_tokens`, `input_image_tokens`, `output_tokens`, `reasoning_tokens`, `cost_usd`) plus the `total`.
    - `422 date_range_too_long` raises a typed error, and other failures raise the existing typed errors.
    - Each call takes one unit from the PE-013 shared budget.
2. `cost_usd` is parsed to integer n$ by the PE-001 decimal parser, never through a float:
    - "0.074595000" → 74,595,000;
    - "0.373875000" → 373,875,000 (the docs' worked maximum);
    - "0.000000001" → 1;
    - "0.000000000" → 0.

    A value with more than 9 decimals, a negative value or a non-numeric value throws.
3. The fake driver computes usage from its own state.
    - It sums the usage and cost of its succeeded generations by completed UTC day.
    - It includes zero days.
    - It formats `cost_usd` as a 9-decimal string.
    - It answers `422 date_range_too_long` for more than 92 days.

    Local and E2E runs therefore show realistic parity.

**Report comparison**
4. For each UTC day in the range, the report shows Pixelforge's value, our recorded value and the delta (Pixelforge − ours) for five figures: provider cost, input text tokens, input image tokens, output tokens and reasoning tokens. "Our recorded value" is the PE-016 provider cost and the summed stored usage of provider-succeeded generations, bucketed by Pixelforge's `completed_at` UTC date and including D-09 rows.
5. A day is a **Match** only when all five integers are exactly equal, with no tolerance. Otherwise it is a **Mismatch**, and the non-zero deltas are highlighted. Generation counts are shown but never compared (requirements §6). The totals row gets its own badge, computed the same way.
6. Worked example, using the PE-016 data (clock frozen at 2026-11-18T12:00:00Z, range 2026-11-15 to 2026-11-18):

    | Day | Pixelforge `cost_usd` | Pixelforge tokens | We recorded | Result |
    |---|---|---|---|---|
    | 2026-11-16 | "0.074595000" | 14 / 0 / 1,380 / 1,546 | 74,595,000 n$ and 14 / 0 / 1,380 / 1,546 (G1 5/0/280/312 + G2 9/0/1,100/1,234) | Match |
    | 2026-11-17 | "0.017480000" | 6 / 0 / 280 / 500 | 17,480,000 n$ and 6 / 0 / 280 / 500 (the D-09 row G4) | Match |
    | 2026-11-15 and 2026-11-18 | 0 | 0 / 0 / 0 / 0 | 0 | Match |
    | Total | "0.092075000" | 20 / 0 / 1,660 / 2,046 | 92,075,000 n$ | Match |
7. If Pixelforge reports "0.017480001" for 2026-11-17, that day shows Mismatch with a provider-cost delta of +1 n$ ($0.000000001), and the totals row also shows Mismatch.
8. A realistic mismatch: someone used the production key outside production for one more `[succeed]` generation with usage 5 / 0 / 280 / 312. Pixelforge then reports "0.032605000" and 11 / 0 / 560 / 812 for 2026-11-17, against our 17,480,000 n$ and 6 / 0 / 280 / 500.
    - The day shows Mismatch.
    - Deltas: cost +15,125,000 n$ ($0.015125000) and tokens +5 / 0 / +280 / +312.
    - The explanation copy is shown (AC 12).
9. The current UTC day carries an "In progress" label, because generations still processing may complete later today.
10. Zero days on both sides show 0 = 0 and Match.

**Range, caching and failure**
11. The range rules are PE-016's: inclusive, at most 92 days, validated locally before any call. A 93-day request therefore sends nothing to Pixelforge. If Pixelforge still answers `422 date_range_too_long`, the report renders our figures with a notice instead of an error page.
12. When any day is a Mismatch, the report shows this explanation: "Pixelforge counts every generation made with this API key. A mismatch usually means the key was used outside this app (for example a local test), generations were recorded before a database reset, or a generation finished at Pixelforge that we haven't recorded yet (it catches up within minutes). Our recorded figures come from each generation's own usage and rates."
13. Successful usage responses are cached briefly (default 60 s) per range, so a reload within that window sends no request to Pixelforge. Failed responses are never cached.
14. If Pixelforge is unavailable, the report still renders our recorded figures. That covers a timeout after a short client timeout (default 5 s), a 5xx, a 429, a 401, or a spent local budget.
    - The Pixelforge columns show "—" and the badges are hidden.
    - A notice reads: "Pixelforge usage is unavailable right now. Our recorded figures are shown; the comparison will return when Pixelforge responds."
    - The failure is logged without secrets, and no exception reaches the user.

**Final production sign-off** (operational, recorded as a ticked checklist in the PR)
15. Shortly before hand-in, within 24 h so that the images are still live:
    - the current `main` is redeployed;
    - PE-015's first-real-generations set is created again on production: the same 8 rows, with every prompt at most 40 code points. That is three `[succeed]` rows (low 1024x1024; medium 1536x1024 with 2 refs; high 1024x1536), plus `[fail]`, `[blocked]`, `[overloaded]`, `[succeed] [duplicate]` and `[succeed] [slow]`, all at low 1024x1024 with no refs;
    - each one is checked for its status and a single charge.

    The billable rows therefore cost at most PE-015's bound: 516,950,000 n$ ($0.516950000) of provider cost and 646,187,500 n$ ($0.646187500) charged.

    Created together, their holds total at most 6 × 21,887,500 + 132,950,000 + 447,575,000 = 711,850,000 n$ ($0.711850000). The account that creates them therefore needs at least $0.7119 available (PE-017). If it has less, buy a credits pack first.
16. On production, every UTC day in the report's default 30-day range shows Match against `/v1/usage` for cost and all four token types, and so does the total. That includes the PE-015 days and today once every demo generation is terminal.
17. A read-only Playwright smoke against the production URL passes. It signs in with reviewer credentials supplied through env and never committed. The reviewer account was granted operator access with the PE-016 command. The smoke checks:
    - History shows succeeded images with their actual costs;
    - the operator report shows Match badges.

    It creates no generations and buys no credits.
18. The README gets its final pass:
    - the live URL and the reviewer guide;
    - how to read the report and its Match badge;
    - the stretch features;
    - a pointer to the decisions log, complete with any rows added by later stories.

## Scope

### In Scope
- The usage operation on the contract:
  - the HTTP driver, with the typed `date_range_too_long` error;
  - the fake driver, computed from fake state by completed UTC day.
- Typed usage data, with `cost_usd` parsed to n$ through PE-001.
- Report columns "Pixelforge says" and "We recorded" for provider cost and the four token types, per day and in total, with an exact Match/Mismatch badge and the deltas. Generation counts are not compared.
- Local range validation (at most 92 days), a short cache of successes only, calls through the shared budget, and a notice when Pixelforge is unavailable.
- The mismatch explanation copy and the "In progress" label for today.
- The final sign-off: redeploy, the PE-015 demo set recreated, every day in range matching, the final README pass and a read-only Playwright production smoke.

### Out of Scope
- Automatically correcting or backfilling mismatches.
- Alerting on mismatches.
- Comparing generation counts (requirements §6).
- CSV export and charts (also out of scope in PE-016).
- New product features.

## Technical Notes
- **Depends on:**
  - PE-016-operator-report: the report page, the range rules and the figures recorded by `completed_at`.
  - PE-017-credit-balances-buy-credits: a sequencing dependency, so the final sign-off covers the whole app.
  - Stored per-generation usage tokens and provider cost come from PE-006 and PE-007.
  - If stretch is cut, the sign-off half can ship alone right after PE-015: AC 15 (without the PE-017 balance check), AC 17 (without the report check) and AC 18.
- **Decisions:**
  - D-03: exact integers match the 9-decimal strings.
  - D-09: recording provider-cost-only rows is what makes parity possible.
  - D-10: bucket by completed UTC day.
  - D-12: the fake driver gives parity locally.
  - See requirements.md §2.1 and §6.
- **Pixelforge facts** (https://pixelforge.promptedit.dev/docs#usage):
  - `start_date` and `end_date` are `YYYY-MM-DD` in UTC and inclusive. The default is the last 30 days, and the maximum is 92 days (`422 date_range_too_long`).
  - Every day in the range is included, with zeros where nothing happened.
  - The response has `object` ("usage"), `start_date`, `end_date`, `data[]` and `total`.
  - `cost_usd` is an exact, unrounded 9-decimal string.
  - Usage is scoped to the **calling key**, and a generation counts on the UTC day it completed.
  - The docs don't say whether `generations` counts only succeeded generations, which is why counts are not compared.
  - Calls count toward the 60 rpm account limit.
- Use a dedicated key per environment. Any use of the production key outside production breaks parity, which is why the PE-015 live smoke uses the dev key.
- A Match is exact integer equality on all five figures, never a tolerance.
- **Fake parity across resets:** the fake's usage comes from its own state, so that state must be cleared whenever the app DB is (`make fresh`, CI's fresh DB). PE-010 leaves the state store open. If it lives outside the app DB, this story makes the reset clear it too. Otherwise a local reset shows a mismatch that is correctly explained but noisy.
- Demo generations spend real money and count toward the 500/day limit, so keep the refresh to PE-015's set. Never commit or print credentials, including the reviewer account's.
- **Required test layers:**
  - **PHP unit:**
    - `cost_usd` parsing: exact values, 1 n$, 0, and invalid, too-precise or negative values throwing;
    - the day-by-day comparison: all matching, a 1 n$ cost delta → Mismatch, a token-only delta → Mismatch, zero days, counts ignored, and the totals badge.
  - **PHP feature (`Http::fake`):**
    - the usage driver maps the response;
    - 422 → the typed error and a notice;
    - unavailable (timeout, 5xx, 429, 401, spent budget) → the report renders with the notice and our recorded figures;
    - the cache prevents a second call within the window, and a failure is not cached;
    - a 93-day range is rejected locally with no request sent;
    - the budget is used;
    - `assertInertia` props carry integer n$ and the match flags.
  - **PHP feature (fake parity):** generations created and completed through the fake produce usage equal to our recorded costs and tokens, and every day matches.
  - **JS integration:** the comparison table's Match/Mismatch badges and deltas, the unavailable notice, the explanation copy and the "In progress" label.
  - **E2E (Playwright, fake driver):**
    - a spec makes a `[succeed]` generation and waits for Succeeded;
    - the seeded operator (PE-016) then sees today's row labelled In progress with a Match badge;
    - use a polling assertion with reloads, with a timeout above the shortened E2E reconciler threshold. Parallel specs can leave today briefly mismatched while their webhooks or polls land, notably PE-014's `[no-webhooks]` spec.
  - **E2E (read-only production smoke, run by hand against the live URL):** AC 17.
- **PR size:** an estimated ~450 changed lines, slightly over the budget. Split into two stories before planning, one PR each:
  - PE-018a: the usage operation, the fake usage, the parsing, the comparison and the report columns.
  - PE-018b: the production smoke config, the README and the operational sign-off.

## Mockups / References
```text
Operator report — provider cost reconciliation (UTC)
┌───────────────────────────────────────────────────────────────────────────────┐
│ From [2026-11-15]  To [2026-11-18]  [Apply]                                   │
│                                                                               │
│ Date         Provider cost                Tokens (text/image/output/reasoning)│
│              Pixelforge  We recorded  Δ   Pixelforge      We recorded  Status │
│ 2026-11-18*  $0.0000     $0.0000      0   0/0/0/0         0/0/0/0      Match  │
│ 2026-11-17   $0.0175     $0.0175      0   6/0/280/500     6/0/280/500  Match  │
│ 2026-11-16   $0.0746     $0.0746      0   14/0/1380/1546  same         Match  │
│ 2026-11-15   $0.0000     $0.0000      0   0/0/0/0         0/0/0/0      Match  │
│ Total        $0.0921     $0.0921      0   20/0/1660/2046  same         Match  │
│ * In progress (today, UTC)        (hover or focus → exact, e.g. $0.074595000) │
└───────────────────────────────────────────────────────────────────────────────┘

Mismatch day:
│ 2026-11-17   $0.0326  $0.0175  +$0.0151  11/0/560/812 vs 6/0/280/500 ⚠ Mismatch │
│ ℹ Pixelforge counts every generation made with this API key. A mismatch       │
│   usually means the key was used outside this app (for example a local test), │
│   generations were recorded before a database reset, or a generation finished │
│   at Pixelforge that we haven't recorded yet.                                 │

Pixelforge unavailable:
│ ⚠ Pixelforge usage is unavailable right now. Our recorded figures are shown;  │
│   the comparison will return when Pixelforge responds.                        │
│ 2026-11-16   —        $0.0746  —         —                          —         │

Range too long:
│ ⚠ The range can be at most 92 days.                                           │
```
- https://pixelforge.promptedit.dev/docs#usage
- https://pixelforge.promptedit.dev/docs#images (24 h expiry, which sets the refresh timing)
- https://pixelforge.promptedit.dev/docs#rate-limits
- requirements.md §2.1, §2.6 and §6; §5 D-03, D-09, D-10, D-12
