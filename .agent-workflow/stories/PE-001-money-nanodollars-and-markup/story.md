# Story: PE-001-money-nanodollars-and-markup

## Title
Money in integer nanodollars: exact provider cost and the +25% charge

## Status
`in-review`

## Description
Every later money write (estimate, hold, settlement, operator report) needs one exact way to represent USD. This story adds the Billing domain's money primitives:

- an integer nanodollar amount (n$, 10⁻⁹ USD);
- conversion of Pixelforge's decimal "USD per 1M tokens" rate strings into whole n$ per token;
- the provider cost of a generation: Σ tokens × **that generation's own** rates;
- the user charge: Pixelforge cost + 25%, rounded **up** to the next n$.

Pixelforge states that amounts are never rounded and that floats drift, so nothing here may pass through a float (D-03, D-04). The work is pure PHP with no I/O, written test-first. It is the foundation of grading criterion #1 (correct money) and of the required pricing feature.

## Acceptance Criteria

**Rate conversion** (n$ per token = USD per 1M tokens × 1,000)

1. The published rate strings convert exactly: `"5.00"` → 5,000, `"10.00"` → 10,000, `"40.00"` → 40,000 and `"12.50"` → 12,500 n$/token. `"12.5"` and `"12.500000"` also give 12,500, `"5"` gives 5,000, `"0.001"` gives 1, and `"0"` / `"0.00"` give 0.
2. A rate that is not a whole number of n$/token throws a dedicated exception whose message names the offending rate. It never rounds. Examples: `"0.0005"` (0.5 n$) and `"12.5005"` (12,500.5 n$).
3. A malformed rate throws the same exception type. Only plain unsigned decimal strings, the format Pixelforge sends, are accepted, even where brick/math itself would parse the input: `""`, `"abc"`, `"5,00"`, `" 5.00"`, `"5.00 "`, `"-5.00"`, `"+5.00"`, `"1e3"`, `"5."`, `".5"`.
4. A rates value is built from a generation's `rates` object and requires exactly the four keys `input_text_tokens`, `input_image_tokens`, `output_tokens` and `reasoning_tokens`. It throws when:
   - a key is missing;
   - an extra key is present, because a token type we cannot price must never be silently dropped;
   - a value is not a string. A JSON number such as `12.5` has already been through a float.
5. A token-usage value holds the four counts from Pixelforge's `usage` object. It throws when:
   - a key is missing;
   - a count is negative or not an integer (`12.0`, `"12"`);
   - a token key other than the four and `total_tokens` is present.

   When `total_tokens` is present it must equal the sum of the four. The docs example 12 + 765 + 6,450 + 3,184 = 10,411 builds; the same counts with `total_tokens` 10,412 throw. `total_tokens` is never used to price.

**Provider cost** (Σ tokensᵢ × rateᵢ, using only the usage and rates passed in)

6. Docs worked maximum: 115 / 1,530 / 6,450 / 8,000 tokens at the published rates cost 575,000 + 15,300,000 + 258,000,000 + 100,000,000 = **373,875,000 n$ ($0.373875000)**. This matches the docs digit for digit.
7. Docs succeeded example: usage 12 / 765 / 6,450 / 3,184 costs 60,000 + 7,650,000 + 258,000,000 + 39,800,000 = **305,510,000 n$ ($0.305510000)**.
8. The same usage priced at two different rate sets gives two different costs. The AC7 usage with `output_tokens` at `"20.00"` (20,000 n$/token) costs 60,000 + 7,650,000 + 129,000,000 + 39,800,000 = **176,510,000 n$ ($0.176510000)**. The calculator's only inputs are the usage and the rates; it reads no configured or pinned price table.
9. Zero usage (all four counts 0) costs 0 n$ at any rates, and a `"0"` rate contributes 0 n$ for any count.

**User charge** (D-04: ceil(provider × 125 / 100))

10. 373,875,000 → **467,343,750 n$ ($0.467343750)**; 305,510,000 → **381,887,500 n$ ($0.381887500)**; 176,510,000 → **220,637,500 n$ ($0.220637500)**; 0 → 0.
11. The charge always rounds up to the next whole n$, never half-up or down: 1 → 2 (1.25), 2 → 3 (2.5), 3 → 4 (3.75), 4 → 5 (exact), 5 → 7 (6.25), 8 → 10 (exact). The over-charge is at most 0.75 n$ per generation.
12. A negative provider cost throws.

**Nanodollar amount**

13. Addition, subtraction and comparison are exact. Results may be negative, because the PE-007 ledger arithmetic needs that: 100 − 250 = −150 n$. Equal amounts compare equal, and zero is available as a named value.
14. Any operation whose result falls outside the signed 64-bit range throws instead of silently turning into a PHP float. That covers add, subtract, tokens × rate and the markup. The limit is ±9,223,372,036,854,775,807 n$, about $9.22 billion.
15. Amounts render as 9-decimal USD strings with no rounding: 373,875,000 → `"0.373875000"`, 0 → `"0.000000000"`, 1 → `"0.000000001"`, −150 → `"-0.000000150"`, 1,234,567,890,123 → `"1234.567890123"`, PHP_INT_MAX → `"9223372036.854775807"`.
16. USD decimal strings with up to 9 decimals parse exactly: `"0.373875000"` → 373,875,000 and `"1.5"` → 1,500,000,000. Parsing then rendering returns the original string for every example in AC15. A string with sub-nanodollar precision (`"0.0000000001"`) or a malformed string (`""`, `"1,5"`, `"1e-9"`) throws.
17. No money API accepts or returns a float.
    - Passing a float (e.g. `0.1`) to any constructor or operation fails with a `TypeError` under `declare(strict_types=1)`.
    - An automated check, such as a reflection-based test over the money types, fails if any public method declares a `float` parameter or return type.
    - Larastan passes at the project level.

## Scope

### In Scope
- Nanodollar amount (int, BIGINT-safe): add, subtract, compare, zero, overflow guard, and 9-decimal USD render/parse.
- Rate conversion from Pixelforge's per-1M decimal strings to whole n$/token via brick/math, with a dedicated exception for malformed or non-whole rates.
- A rates value (exactly four token types, from a generation's `rates` object) and a token-usage value (the four counts from `usage`, with the `total_tokens` consistency check).
- Provider cost: Σ tokens × the rates passed in.
- Markup: ceil(provider × 125 / 100), integer-only.
- PHPUnit unit tests for every example above, as data-provider rows.

### Out of Scope
- Pricing snapshot, upfront estimate, size/quality enums and the TS mirror (PE-002).
- Persistence: Eloquent casts and generation columns (PE-006).
- Holds, settlement and ledger entries (PE-007).
- UI formatting such as `$0.3739` in `resources/js/lib/money.ts` (PE-005).
- Calling `GET /v1/usage` and comparing `cost_usd` (PE-018). This story only provides the 9-decimal parse it will use.
- Currencies other than USD.

## Technical Notes
- **Decisions:** D-03 (integer nanodollars) and D-04 (markup rounds up). See requirements.md §2.2 (pricing, docs worked maximum, "amounts are never rounded … use integers (for example nanodollars)") and §3 (money model).
- **Unit maths:** a rate in USD per 1M tokens × 10⁹ n$/USD ÷ 10⁶ tokens = rate × 1,000 n$/token. All four published rates have at most 3 decimals, so they convert to whole n$/token, and provider cost matches `/v1/usage` `cost_usd` (9 decimals) digit for digit.
- **Why AC11 uses synthetic amounts:** at today's rates every provider cost is a multiple of 2,500 n$ (the GCD of 5,000 / 10,000 / 40,000 / 12,500), so × 125 / 100 always comes out exact. The ceil path only matters at other rates, for example 1 token at `"0.001"` = 1 n$ → charged 2 n$. Rates are fixed per generation and can change (requirements §6), so the path must be tested anyway.
- **Strictness is deliberate (AC4, AC5):** a token type we don't price, or a `total_tokens` that doesn't add up, would mean recording the wrong cost and drifting from `/v1/usage`. Failing loudly beats silently under-recording. PE-007 and PE-008 decide how a caller surfaces that failure (webhook or poller).
- **No float paths:** besides the AC17 type check, review must find none of the following in money code: `/` on ints, `round`, `ceil`, `floor`, `number_format`, `(float)` or float literals. Ceil division is integer-only.
- **Dependency:** composer.lock has brick/math 1.0.0 only as a transitive framework dependency. Using it directly means promoting it to a direct `require`. That is a dependency change, which needs the user's approval under CLAUDE.md, so flag it in the PR description. requirements §3 names brick/math as the parser.
- **Location:** the Billing domain (CLAUDE.md domain map). Inputs are plain arrays. The Pixelforge snapshot DTO that will feed them arrives in PE-006, and it keeps rates as received strings.
- **Keep the surface minimal.** The named consumers are:
  - PE-002: rate conversion and markup;
  - PE-007: usage × the generation's rates, the charge, and ledger arithmetic;
  - PE-016 and PE-018: 9-decimal render/parse against `/v1/usage`.
- **Headroom:** the most expensive request possible today (4,000-character prompt, high, 4 references, maximum tokens) costs 394,350,000 n$ from the provider and 492,937,500 n$ to the user. So int64 is never a per-generation concern. Later SQL aggregates (PE-016) must stay BIGINT/NUMERIC and never pass through float.
- **Required test layers:**
  - PHP unit (`tests/Unit`), each test written first (TDD):
    - amount arithmetic and overflow;
    - render/parse round-trips;
    - rate conversion and every rejection case;
    - rates and usage construction, including extra keys and the `total_tokens` mismatch;
    - provider cost and markup, with every AC example as a data-provider row;
    - the float type check.
  - No PHP feature, JS unit, JS integration or E2E tests: there is no HTTP and no UI.
- **Depends on:** the bootstrap only (PRs #2–#6: Postgres, Inertia/Vue/TS, the Vitest/Playwright harness, queue worker and scheduler).
- **PR size:** about 300–380 changed lines including tests, inside the ~400-line budget. Use one commit per primitive, each with its test.

## Mockups / References
No UI.

- Pricing, token counting and worked maximum: https://pixelforge.promptedit.dev/docs#pricing
- Generation object (`rates` as strings fixed at creation, `usage` with `total_tokens`, succeeded example): https://pixelforge.promptedit.dev/docs#generation-object
- Usage report (`cost_usd`, 9 decimals, source of truth): https://pixelforge.promptedit.dev/docs#usage
- requirements.md §2.2, §3, §6; decisions D-03, D-04
