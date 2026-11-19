# Story: PE-002-upfront-estimate-shared-fixture

## Title
Upfront estimate in PHP and TypeScript, locked together by one shared JSON fixture

## Status
`qa-passed`

## Description
Users must see what a generation will cost before they submit it (required "generate page with upfront estimate" and "pricing"). The estimate is computed in two places:

- an **authoritative PHP action**, recomputed server-side when a generation is created (PE-006) and later used for the hold (D-14);
- a **pure TypeScript mirror**, which gives instant feedback while the user types (PE-005).

Both read the same pinned pricing snapshot and are tested against **one shared JSON fixture**, so they cannot drift (requirements §3; CLAUDE.md testing rule). The fixture is the contract. It pins code-point counting, the ±15% text bounds, the typical-midpoint rule and the markup round-up for both languages. This serves grading criteria #1 (correct money) and #3 (clear what things cost).

## Acceptance Criteria

**Pricing snapshot**

1. The app holds a pinned pricing snapshot equal to live `GET /v1/pricing` as checked on 2026-11-18. It is never fetched at runtime. It contains:
   - rates of 5.00 / 10.00 / 40.00 / 12.50 USD per 1M tokens (text / image / output / reasoning);
   - 4 characters per text token and ±15% text variance;
   - 765 tokens per reference image;
   - the output-token and reasoning tables in requirements §2.2.
2. The pricing table built from the snapshot exposes rates as integer n$/token (5,000 / 10,000 / 40,000 / 12,500, converted by PE-001). Its array form equals the fixture's `pricing` block exactly. That block is also the prop shape the Generate page receives (PE-005).
3. Building the pricing table throws, and never fails later at estimate time with a silent zero, when:
   - a rate is not a whole n$/token (e.g. `"0.0005"`);
   - any of the nine quality×size output entries or three reasoning ranges is missing;
   - a reasoning min exceeds its max.

**Counting and token bounds**

4. Characters are Unicode code points, counted the same way in both languages: PHP `mb_strlen($prompt, 'UTF-8')`, TS `[...prompt].length`. There is no trimming and no normalisation. The fixture pins:
   - `"👍🏽👍🏽👍🏽👍🏽"` = 8 code points (16 UTF-16 units, 32 bytes) → e = 2;
   - `"東京の夜景"` = 5 code points (15 bytes) → e = 2;
   - decomposed `"café"` (e + U+0301) = 5 → e = 2, while precomposed `"café"` = 4 → e = 1;
   - `"[fail] a cat"` = 12 → e = 3, because test keywords count like any other characters;
   - `" cat "` = 5 → e = 2, because leading and trailing spaces count;
   - `"a\nb"` = 3 → e = 1, because a newline is one code point.
5. Text tokens: e = ceil(codepoints / 4), min = floor(e × 85 / 100), max = ceil(e × 115 / 100), typical = e. As min / typical / max:
   - 0 chars → 0 / 0 / 0;
   - 1 → 0 / 1 / 2;
   - 40 → 8 / 10 / 12;
   - 400 → 85 / 100 / 115;
   - 4,000 → 850 / 1,000 / 1,150.
6. Exact and estimated parts:
   - Image tokens are exact: 765 × reference count (0 → 0, 2 → 1,530, 4 → 3,060).
   - Output tokens are exact, from the quality×size table.
   - Reasoning is the quality's min–max with typical = midpoint: low 0 / 250 / 500, medium 200 / 1,100 / 2,000, high 1,000 / 4,500 / 8,000.
7. **Typical rule** (pinned in the fixture and recorded in the decisions log): each token type's typical count is the midpoint of its min and max, rounded **up** to a whole token when min + max is odd. Typical prices those counts the same way as the bounds. With today's tables every midpoint is already whole, and the text midpoint is always exactly e. A synthetic case pins the round-up: reasoning range 0–499 → typical 250.

**Prices**

8. An estimate returns:
   - min, typical and max **provider cost** and **user price** in n$, with user = ceil(provider × 125 / 100) applied **once to each total**, never per line, exactly as settlement will charge (PE-007);
   - a per-token-type breakdown with min / typical / max tokens and provider n$, flagging input image and output as **exact** and input text and reasoning as **estimated**.
9. Docs worked maximum (high, 1536x1024, 400-char prompt, 2 references):
   - provider 286,225,000 / 330,050,000 / **373,875,000 n$**;
   - user 357,781,250 / 412,562,500 / **467,343,750 n$** (max **$0.467343750**).
10. The fixture pins these further cases, all at today's rates (user price in n$):

    | Prompt (code points) | Quality · size · refs | User min / typical / max (n$) |
    |---|---|---|
    | `""` (0) | low · 1024x1024 · 0 | 14,000,000 / 17,906,250 / 21,812,500 (cheapest possible) |
    | `"a"` (1) | medium · 1024x1024 · 0 | 58,125,000 / 72,193,750 / 86,262,500 |
    | 4 × `👍🏽` (8) | low · 1024x1024 · 0 | 14,006,250 / 17,918,750 / 21,831,250 |
    | `"東京の夜景"` (5) | low · 1024x1024 · 0 | 14,006,250 / 17,918,750 / 21,831,250 |
    | decomposed `"café"` (5) | low · 1024x1024 · 0 | 14,006,250 / 17,918,750 / 21,831,250 |
    | precomposed `"café"` (4) | low · 1024x1024 · 0 | 14,000,000 / 17,912,500 / 21,825,000 |
    | `"[fail] a cat"` (12) | low · 1024x1024 · 0 | 14,012,500 / 17,925,000 / 21,837,500 |
    | 40 chars | high · 1024x1024 · 1 | 240,237,500 / 294,937,500 / 349,637,500 |
    | 40 chars | medium · 1536x1024 · 1 | 95,237,500 / 109,312,500 / 123,387,500 |
    | 40 chars | medium · 1024x1536 · 1 | 95,237,500 / 109,312,500 / 123,387,500 |
    | 40 chars | low · 1536x1024 · 1 | 30,612,500 / 34,531,250 / 38,450,000 |
    | 40 chars | low · 1024x1536 · 1 | 30,612,500 / 34,531,250 / 38,450,000 |
    | 4,000 chars | high · 1024x1536 · 4 | 381,687,500 / 437,312,500 / 492,937,500 (most expensive possible, max $0.492937500) |

    Together with AC9, every quality×size combination appears at least once.
11. Markup round-up is pinned with a synthetic snapshot whose four rates are all `"0.001"` (1 n$/token). For a 5-char prompt, low, 1024x1024 and 0 refs:
    - provider 281 / 532 / 783 → user **352 / 665 / 979 n$** (351.25 / 665.00 / 978.75 rounded up);
    - with the odd reasoning range 0–499 from AC7: provider 281 / 532 / 782 → user **352 / 665 / 978 n$**.

**Contract and edge cases**

12. One fixture file is the single source of expected values.
    - A PHPUnit data provider and a Vitest suite each iterate **every** case and assert identical token counts and n$ values.
    - Adding a case to the fixture runs it in both suites with no code change.
    - A case may override parts of the pricing block (synthetic rates or ranges).
13. The TS mirror is a pure module with no Vue or Inertia imports.
    - It is integer-only: no `× 0.85`, `× 1.15` or `× 1.25`, and no float division whose result is then rounded.
    - It throws if given a non-integer or unsafe-integer rate or count.
    - The suite asserts that every fixture n$ value is a JSON integer below 2⁵³.
14. The estimator prices whatever it is given and does not enforce input limits: a 4,001-code-point prompt (text 850 / 1,001 / 1,152 tokens) or 5 references still gets a price, because validation belongs to PE-005 and PE-011. A negative reference count, or an unknown quality or size, is rejected in both languages.

## Scope

### In Scope
- Pinned pricing snapshot in config, shaped like `GET /v1/pricing` (checked 2026-11-18), and the pricing table built from it, with an array form for Inertia props.
- Size and quality enums (1024x1024 / 1536x1024 / 1024x1536; low / medium / high). The estimator is their first consumer, and PE-006 reuses them.
- PHP estimate action (Billing domain): min / typical / max provider and user n$, plus the per-token-type breakdown flagged exact vs estimated.
- `resources/js/lib/estimate.ts`: the pure TS mirror, plus pricing and estimate types in `resources/js/types/`.
- Shared fixture (e.g. `tests/fixtures/estimates.json`) with the pricing block and the cases in AC4 and AC9–AC11.
- PHPUnit data-provider suite and Vitest unit suite over the same fixture, plus edge cases: zero tokens, an invalid snapshot throws, and unsafe or non-integer inputs throw in TS.
- Decisions-log rows in requirements.md §5 (next free D-numbers): the pinned pricing snapshot (see Technical Notes) and the typical-midpoint rule.

### Out of Scope
- `lib/money.ts`, any UI and the `useEstimate` composable (PE-005).
- Persisting the estimate on a generation (PE-006) and holding the max (PE-007).
- Fetching live `/v1/pricing` at runtime, and checking the snapshot against the live endpoint (PE-015).
- Input validation: 4,000 code points, 4 references, http(s) URLs (PE-005 hints, PE-011 server validation).
- Affordability against a balance (PE-017).

## Technical Notes
- **Sources:** requirements §2.2 (pricing tables, code points, test keywords count) and §3 (estimate formulas; "computed in two places … one shared JSON fixture"). D-03, D-04 and D-14 apply: the max computed here is what PE-007 will hold.
- **Depends on:** PE-001 (rate conversion, markup, nanodollar amount). The Vitest unit project (`tests/js/unit`, node environment) is already in place from the bootstrap (PR #5).
- **Pinning is a deliberate deviation.** The docs advise reading rates from `/v1/pricing` rather than hardcoding them.
  - A pinned snapshot keeps PHP, TS and the fixture deterministic.
  - It only affects the **estimate**: settlement always uses the generation's own `rates` (PE-001, PE-007), so a stale snapshot can never mis-charge.
  - Record this in the decisions-log row. Drift is detected by the PE-015 live smoke.
- **Live payload quirk:** in live `/v1/pricing`, `text_token_variance` is the JSON number `0.15` and `characters_per_text_token` is `4`.
  - Hold the variance exactly in the snapshot, for example as an integer percent (15). Never use a float in arithmetic.
  - Keep the other keys mirroring `/v1/pricing` so PE-015 can diff the snapshot against the endpoint without float maths.
- **Rates on the wire:** the pricing-table prop carries rates as integer n$/token, so the TS mirror never parses decimal strings. The PHP suite proves that the pinned config produces exactly the fixture's pricing block (AC2), which locks the prop shape too.
- **Fixture placement and format:**
  - Keep the fixture outside `resources/js` so it is never bundled, but inside the Vitest root so the unit project can import it.
  - Store all money as JSON integers, never decimal strings or floats.
  - How long prompts (4,000 chars) are expressed compactly is the Architect's call.
- **Why the text bounds are integer-only:** float `e × 1.15` happens to give the right answer for e ≤ 1,000. But D-03 forbids floats in money paths, and the rule must hold for any snapshot.
- **Newlines:** a textarea's value uses `\n`, so the TS count and a JSON-posted prompt agree. PE-011 must count, store and send the identical string.
- **Consumers:**
  - PHP estimate action: the fixture suite here, then PE-006, which recomputes it server-side. The client's figure is never trusted.
  - `estimate.ts`: the Vitest suite here, then PE-005 (`useEstimate`).
  - Pricing-table array form: the contract test here, then the PE-005 page props.
- **Honesty (requirements §6):** if Pixelforge changes rates between estimate and creation, the actual can exceed the max. Settlement charges the actual anyway (PE-007), and PE-005's copy must not promise the max as a ceiling.
- **Required test layers:**
  - PHP unit:
    - the fixture data provider over every case;
    - the pricing table from the pinned config equals the fixture pricing block;
    - the AC3 invalid snapshots throw;
    - enum rejection.
  - JS unit (Vitest):
    - the same fixture over every case;
    - non-integer or unsafe inputs throw;
    - every fixture n$ value is a safe integer.
  - No PHP feature, JS integration or E2E tests: there is no HTTP and no UI yet.
- **PR size:** estimated 450–600 changed lines, because the JSON fixture is test data and counts toward the budget. That is likely over the ~400 budget. If it is, split it before planning into two stories:
  - (a) PHP snapshot, enums, estimator and fixture;
  - (b) the TS mirror and Vitest suite against the committed fixture. PE-005 then depends on (b).

## Mockups / References
No UI.

- Pricing, "How tokens are counted" and the worked maximum: https://pixelforge.promptedit.dev/docs#pricing
- Live pricing endpoint the snapshot pins: `GET https://pixelforge.promptedit.dev/v1/pricing` (public)
- Test keywords, which count toward characters: https://pixelforge.promptedit.dev/docs#testing
- requirements.md §2.2, §3, §6; decisions D-03, D-04, D-14
