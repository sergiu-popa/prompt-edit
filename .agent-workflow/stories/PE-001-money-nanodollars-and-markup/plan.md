# Plan — PE-001-money-nanodollars-and-markup

## Approach
Add immutable Billing primitives: `Nanodollars`, `TokenRates` and `TokenUsage`, plus two calculation Actions. All arithmetic goes through brick/math `BigInteger`/`BigDecimal` and returns to `int` through one overflow-checked factory (`Nanodollars::fromBigInteger`), so no value ever touches a float or a native int overflow. Pixelforge input (rate strings, usage counts) is validated strictly at construction, with an anchored plain-decimal pattern checked before brick/math sees it. Anything we can't price exactly throws a dedicated Billing exception.

## Tasks
Each task is one TDD commit (failing test first, then code, `make check` green before committing).

- [x] 1. Promote `brick/math` to a direct `require` at `^1.0` (the locked 1.0.0), using `composer require --no-update` then `composer update --lock` inside the container so the lock diff is only the content-hash. Commit: `[PE-001] Require brick/math directly for exact decimal maths`
- [x] 2. Add `Nanodollars` (constructor, `zero`, `fromBigInteger`, `plus`, `minus`, `multipliedBy`, `equals`, `compareTo`) and `NanodollarOverflowException`, test-first for AC13, the add/subtract/multiply part of AC14, and the AC17 `TypeError` rows. Commit: `[PE-001] Add Nanodollars with overflow-checked arithmetic`
- [x] 3. Add `Nanodollars::toUsdString()` / `Nanodollars::fromUsdString()` and `InvalidUsdAmountException`, test-first for AC15–16 and the parse boundary rows below. Commit: `[PE-001] Render and parse Nanodollars as 9-decimal USD strings`
- [x] 4. Add `BillingTypesDeclareNoFloatsTest`, a reflection scan of every class under `app/Domain/Billing` that fails on a `float`, `mixed` or missing type in any public method parameter or return, or on a public property declared by the class itself (AC17). It must also assert that it found at least one class. Commit: `[PE-001] Guard Billing signatures against float types`
- [x] 5. Add the `TokenType` enum, `TokenRates::from()` and `InvalidRateException`, test-first for AC1–4 and the rate boundary rows below. Commit: `[PE-001] Build TokenRates from Pixelforge rate strings`
- [x] 6. Add `TokenUsage` (guarded public constructor plus `from()`) and `InvalidTokenUsageException`, test-first for AC5, its `TypeError` row and the usage boundary rows below. Commit: `[PE-001] Build TokenUsage from Pixelforge usage counts`
- [x] 7. Add `CalculateProviderCostAction`, test-first for AC6–9 and the tokens × rate and sum overflow rows of AC14. Commit: `[PE-001] Calculate provider cost from a generation's usage and rates`
- [x] 8. Add `CalculateUserChargeAction`, test-first for AC10–12 and the markup overflow boundary of AC14. Commit: `[PE-001] Calculate the user charge as cost +25% rounded up`

## Domain
All classes are `final`. The value objects are `readonly`. Namespace root: `App\Domain\Billing`.

| Class | Location | Role |
| --- | --- | --- |
| `Nanodollars` | `ValueObjects/` | `public int $amount`. Exact `plus` / `minus` / `multipliedBy(int)`, `equals`, `compareTo`, `zero()`. `fromBigInteger()` is the one place a big number becomes an `int`, and it maps brick's `IntegerOverflowException` to `NanodollarOverflowException`. |
| `Nanodollars::toUsdString` / `fromUsdString` | `ValueObjects/` | Render: unscaled value at scale 9 via `BigDecimal`. Parse: optional `-`, digits, then at most 9 decimals; any bad or out-of-range input throws `InvalidUsdAmountException`. Consumers: PE-016 and PE-018. |
| `TokenType` | `Enums/` | String-backed: `InputText` = `input_text_tokens`, `InputImage`, `Output`, `Reasoning`. The single list of priced token types, used by both `from()` key checks. |
| `TokenRates` | `ValueObjects/` | Private constructor holding four `Nanodollars` per-token prices (`inputTextToken`, `inputImageToken`, `outputToken`, `reasoningToken`). `from(array)` requires exactly the `TokenType` keys and string values in plain unsigned decimal form, and rate × 1,000 must be a whole number. Consumers: PE-002 (pricing table) and PE-007 (snapshot rates). |
| `TokenUsage` | `ValueObjects/` | Public constructor taking four non-negative ints (`inputTextTokens`, `inputImageTokens`, `outputTokens`, `reasoningTokens`). The constructor is the only non-negative guard. `from(array)` accepts the `TokenType` keys plus an optional `total_tokens`, ints only, and the total must equal the sum. The total is never stored. Consumers: PE-002 (estimate bounds via the constructor) and PE-007 (via `from`). |
| `CalculateProviderCostAction` | `Actions/` | `execute(TokenUsage, TokenRates): Nanodollars`, computed as Σ `rate->multipliedBy(tokens)` folded with `plus`. It has no other inputs and no config. Consumer: PE-007. |
| `CalculateUserChargeAction` | `Actions/` | `execute(Nanodollars $providerCost): Nanodollars`. The ratio is a 125/100 class constant, ceil-divided with `BigInteger` and `RoundingMode::Ceiling`, then passed through `fromBigInteger`. A negative cost throws SPL `InvalidArgumentException`, because no external input can reach it. Consumers: PE-002 and PE-007. |
| `InvalidRateException` | `Exceptions/` | Extends `\InvalidArgumentException`. Covers AC2–4 and a rate whose n$/token overflows int64. The message names the key and the offending value. |
| `InvalidTokenUsageException` | `Exceptions/` | Extends `\InvalidArgumentException`. Covers AC5. The message names the key and the value. |
| `InvalidUsdAmountException` | `Exceptions/` | Extends `\InvalidArgumentException`. Covers AC16: malformed, sub-nanodollar or out-of-range USD strings. |
| `NanodollarOverflowException` | `Exceptions/` | Extends `\OverflowException`. Covers AC14: add, subtract, multiply or markup results outside `PHP_INT_MIN`…`PHP_INT_MAX`. |

Rule of thumb: a parser (`TokenRates::from`, `TokenUsage::from`, `fromUsdString`) throws its own exception for every bad input, including out-of-range input. Arithmetic throws `NanodollarOverflowException`.

## Tests
All tests are unit tests extending `PHPUnit\Framework\TestCase` (no app boot). Every AC example is a `#[DataProvider]` row. Test paths mirror `app/`.

| Test | Location | Covers |
| --- | --- | --- |
| `NanodollarsTest` | `tests/Unit/Domain/Billing/ValueObjects/` | AC13–16, AC17 `TypeError` (constructor, `multipliedBy`) |
| `BillingTypesDeclareNoFloatsTest` | `tests/Unit/Domain/Billing/` | AC17 reflection guard |
| `TokenRatesTest` | `tests/Unit/Domain/Billing/ValueObjects/` | AC1–4 |
| `TokenUsageTest` | `tests/Unit/Domain/Billing/ValueObjects/` | AC5, AC17 `TypeError` (constructor) |
| `CalculateProviderCostActionTest` | `tests/Unit/Domain/Billing/Actions/` | AC6–9, AC14 |
| `CalculateUserChargeActionTest` | `tests/Unit/Domain/Billing/Actions/` | AC10–12, AC14 |

Boundary rows to add beyond the ACs:

| Subject | Row | Expect |
| --- | --- | --- |
| `TokenRates` | `"9223372036854775.807"` | `PHP_INT_MAX` n$/token |
| `TokenRates` | `"9223372036854775.808"` | `InvalidRateException` |
| `TokenRates` | `"5.00\n"` | `InvalidRateException` (end anchor) |
| `TokenRates` | values `5`, `null`, `["5.00"]` | `InvalidRateException` |
| `Nanodollars` | `PHP_INT_MAX` + 1, `PHP_INT_MIN` − 1, `PHP_INT_MAX` × 2 | `NanodollarOverflowException` |
| `fromUsdString` | `"9223372036.854775808"` | `InvalidUsdAmountException` |
| `fromUsdString` | `"-9223372036.854775808"` | `PHP_INT_MIN`; renders back identically |
| `fromUsdString` | `"+1.5"`, `".5"`, `"1."`, `" 1.5"`, `"1.5\n"` | `InvalidUsdAmountException` |
| `TokenUsage` | extra key `cached_tokens`; `total_tokens` given as `"10411"` | `InvalidTokenUsageException` |
| Provider cost | `PHP_INT_MAX` output tokens at `"0.002"` | `NanodollarOverflowException` (tokens × rate) |
| Provider cost | `PHP_INT_MAX` text tokens at `"0.001"` + 1 reasoning token at `"0.001"` | `NanodollarOverflowException` (sum) |
| Provider cost | `PHP_INT_MAX` tokens at `"0"` | 0 n$ |
| User charge | 7,378,697,629,483,820,645 | `PHP_INT_MAX`, with no spurious intermediate overflow |
| User charge | 7,378,697,629,483,820,646; `PHP_INT_MAX` | `NanodollarOverflowException` |

## Risks
- **PR size is over budget.** The estimate is ≈650–750 changed lines: ≈300 production and ≈400 tests, most of them data-provider rows. The story's 300–380 estimate leaves out the AC test rows.
  - Recommended: ship it as one PR of eight commits, each ≤≈170 lines and reviewable alone. The code is pure, has no I/O, and every line maps to an AC.
  - If the user wants ≤400 per PR, cut after task 4. PR A (tasks 1–4) is the amount and the float guard, ≈280 lines. PR B (tasks 5–8) is rates, usage, cost and charge, ≈420 lines.
- **Dependency change.** Promoting `brick/math` edits `composer.json`. CLAUDE.md requires user approval for that, so flag it in the PR description.
- **Pattern pitfalls.** PHP's `$` accepts a trailing `"\n"`, so anchor with `\z` (or the `D` modifier). Use `[0-9]`, not `\d`, so the `u` modifier can never admit non-ASCII digits. The boundary rows above pin both.
- **Larastan vs. deliberate float calls.** `tests/` is analysed at level 8, so pass the `TypeError` inputs through a `mixed`-typed data-provider parameter. Otherwise `make stan` fails on the literal `float`.
- **`total_tokens` check.** Sum the four counts with `BigInteger`. A native `+` on absurd counts silently becomes a float.
- **No float paths.** Money code contains no `/` on ints, `round` / `ceil` / `floor`, `number_format`, `(float)` or float literals (story Technical Notes). Review checks this by reading the code. Task 4 guards it by reflection.
