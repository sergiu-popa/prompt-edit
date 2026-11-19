# Plan — PE-002-upfront-estimate-shared-fixture

## Approach
Pin `/v1/pricing` as plain data in `config/pricing.php`, and build one validated, immutable `PricingTable` from it as a container singleton that reuses PE-001's `TokenRates` for the n$/token conversion. `EstimateGenerationCostAction` turns a prompt, quality, size and reference count into four `TokenBounds`, then prices min, typical and max through the same `CalculateProviderCostAction` and `CalculateUserChargeAction` that settlement uses, so the markup is applied once to each total. `lib/estimate.ts` mirrors this in `bigint`, and both suites iterate `tests/fixtures/estimates.json`, whose `pricing` block is the table's array form and also the PE-005 prop.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and run `make check` green before committing. Tasks 1–11 are PHP and tasks 12–13 are TypeScript, so the PR can be cut after task 11 if asked (see Risks).

- [x] 1. Add `ImageQuality` and `ImageSize` in `app/Domain/Shared/Enums`, test-first for their exact Pixelforge values and the rejected strings below. Commit: `[PE-002] Add image quality and size enums`
- [x] 2. Add `TokenBounds` (`exact`, and `between` with the round-up midpoint), test-first for AC7 and the bounds rows below, and record decision D-16. Commit: `[PE-002] Add token bounds with a round-up typical midpoint`
- [x] 3. Pin the snapshot in `config/pricing.php`. Replace both `PUBLISHED_RATES` copies (`TokenRatesTest`, `CalculateProviderCostActionTest`) with `publishedRates()` from the new `tests/Concerns/InteractsWithPricing` trait, and record decision D-15. Commit: `[PE-002] Pin the Pixelforge pricing snapshot in config`
- [x] 4. Build `PricingTable::fromSnapshot()` for the rates and the text and reference-image rules, with `InvalidPricingException`, test-first for AC3's rate row and the scalar rows below. Commit: `[PE-002] Build the pricing table's rates and text rules`
- [x] 5. Add the output and reasoning tables and the `outputTokens()` / `reasoningTokens()` lookups, test-first for AC3's missing-entry and min > max rows and the transposition row. Commit: `[PE-002] Add output and reasoning tables to the pricing table`
- [x] 6. Expose `PricingTable::toArray()` and bind the table as a singleton. Add the fixture with only its `pricing` block, and `PinnedPricingTableTest` for AC1–2. Commit: `[PE-002] Bind the pricing table and pin its array form in a fixture`
- [x] 7. Add `EstimateGenerationCostAction` and its result types, test-first through the fixture data-provider suite, with the AC9 case as the fixture's first case. Commit: `[PE-002] Estimate a generation's cost against the shared fixture`
- [x] 8. Add the task-8 cases to the fixture, with no code change. Commit: `[PE-002] Cover every quality and size in the estimate fixture`
- [x] 9. Add the task-9 code-point counting cases to the fixture, with no code change. Commit: `[PE-002] Pin code-point counting in the estimate fixture`
- [x] 10. Support `pricing_overrides` in the PHP suite, including the per-case table parity check, and add the two AC11 cases. Commit: `[PE-002] Pin the markup round-up with synthetic pricing cases`
- [x] 11. Reject a negative reference count in the action, and add the AC14 beyond-the-limits case. Commit: `[PE-002] Price inputs beyond the limits and reject negative references`
- [x] 12. Mirror the estimator in `lib/estimate.ts` with its types, test-first through a Vitest suite over every fixture case plus the safe-integer walk. Commit: `[PE-002] Mirror the estimate in TypeScript against the shared fixture`
- [x] 13. Guard every TS input, test-first for the TS rejection rows below (AC13–14). Commit: `[PE-002] Reject malformed or unsafe estimate inputs in TypeScript`

## Domain
All PHP classes are `final`. Value objects and DTOs are `readonly`. Billing arithmetic goes through `BigInteger` or `Nanodollars`, as in PE-001: no `/`, `intdiv`, `ceil`, `floor` or `round`.

| Class / file | Location | Role |
| --- | --- | --- |
| `ImageQuality` | `app/Domain/Shared/Enums/` | String-backed: `Low` / `Medium` / `High` = `low` / `medium` / `high`. It is Shared because Billing (estimate), PE-006 (columns) and PE-009 (payload) all use it. |
| `ImageSize` | `app/Domain/Shared/Enums/` | `Square` = `1024x1024`, `Landscape` = `1536x1024`, `Portrait` = `1024x1536`, matching PE-005's labels. |
| `config/pricing.php` | `config/` | Plain data with no `env()` and no closures. It holds the live `rates` strings and the live `tokens` keys verbatim (`characters_per_text_token`, `per_reference_image`, `output`, `reasoning`). The one exception: live `text_token_variance: 0.15` is stored as `text_token_variance_percent: 15`. A docblock names the 2026-11-18 check, D-15 and PE-015. |
| `TokenBounds` | `Billing/ValueObjects/` | Private constructor. Public `int` fields `min`, `typical` and `max`. `exact(n)` sets all three to n. `between(min, max)` sets typical = ceil((min + max) / 2). A negative value or min > max throws SPL `InvalidArgumentException`. |
| `CostBounds` | `Billing/ValueObjects/` | Three `Nanodollars`: `min`, `typical`, `max`. No logic. |
| `PricingTable` | `Billing/ValueObjects/` | `fromSnapshot(array)` is the only constructor, and it validates everything up front, so estimate time never meets a bad table. Rates go through `TokenRates::from`, and its `InvalidRateException` propagates unchanged. Public fields: `TokenRates $rates`, `int $charactersPerTextToken` (≥ 1), `int $textTokenVariancePercent` (0–100) and `int $tokensPerReferenceImage` (≥ 0). Private: the nine output counts and one reasoning `TokenBounds` per quality. Methods: `outputTokens(ImageQuality, ImageSize): int`, `reasoningTokens(ImageQuality): TokenBounds`, and `toArray()`, which returns the fixture `pricing` block and has a PHPDoc array shape. |
| `InvalidPricingException` | `Billing/Exceptions/` | Extends `\InvalidArgumentException`. The message names the snapshot path (e.g. `tokens.output.medium.1024x1536`) and the offending value. |
| `TokenType::isKnownUpfront()` | `Billing/Enums/` | True for `InputImage` and `Output`. It is the source of each line's exact flag. |
| `EstimateLine` | `Billing/DataTransferObjects/` | `TokenType $type`, `bool $isExact`, `TokenBounds $tokens`, `CostBounds $providerCost`. |
| `GenerationCostEstimate` | `Billing/DataTransferObjects/` | `int $promptCharacters`; `lines`, keyed by token-type value in `TokenType::cases()` order; `CostBounds $providerCost`; `CostBounds $userPrice`. Consumers: the fixture suite, then PE-006 (stores `userPrice`) and PE-017. |
| `EstimateGenerationCostAction` | `Billing/Actions/` | The constructor takes `PricingTable`, `CalculateProviderCostAction` and `CalculateUserChargeAction`. Signature: `execute(string $prompt, ImageQuality $quality, ImageSize $size, int $referenceImageCount): GenerationCostEstimate`. The steps are in the rows below. |
| ↳ text tokens | | Characters = `mb_strlen($prompt, 'UTF-8')`, with no trim and no normalisation. e = ceil(characters ÷ characters_per_text_token). Bounds = `between(floor(e × (100 − v) / 100), ceil(e × (100 + v) / 100))`, so typical comes out as exactly e. |
| ↳ other token types | | Image = `exact(per_reference_image × count)`. Output = `exact(outputTokens(...))`. Reasoning = `reasoningTokens(quality)`. |
| ↳ prices | | Totals: three `TokenUsage` (min, typical, max), each priced through the provider-cost action, then the user charge applied to each total. Each line's provider cost is rate × count, per bound. A negative count throws SPL `InvalidArgumentException`. |
| `AppServiceProvider::register` | `app/Providers/` | Binds `PricingTable` as a singleton, built from `config('pricing')`. Add `declare(strict_types=1)` to this file, since it now builds a money object (PE-001 audit #5). |
| `resources/js/types/pricing.ts` | | `ImageQuality`, `ImageSize` and `TokenType` unions. `PricingTable` is the fixture `pricing` block and also the PE-005 prop type. |
| `resources/js/types/estimate.ts` | | `EstimateInput` (`prompt`, `quality`, `size`, `reference_image_count`), `EstimateBounds` (`min`, `typical`, `max`), `EstimateLine` and `GenerationCostEstimate`. Keys are snake_case, identical to the fixture's `expected`, so the suite compares results with no mapper. |
| `resources/js/lib/estimate.ts` | | Pure, with no Vue or Inertia imports. `estimateGenerationCost(pricing, input)`. Code points are `[...prompt].length`. Every number read must be a non-negative safe integer and becomes a `bigint`. Results become numbers again only if they are ≤ `Number.MAX_SAFE_INTEGER`. Ceil is `(a + b − 1n) / b`. The 125 / 100 markup uses named constants. Quality and size are found by own-key lookup (`Object.hasOwn`). Like the PHP build, it rejects reasoning min > max, `characters_per_text_token` < 1 and variance > 100. Errors are `Error`s that name the field. |

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-15 | Estimates read a pinned `/v1/pricing` snapshot (`config/pricing.php`, checked 2026-11-18). It is never fetched at runtime. | It keeps PHP, TS and the fixture deterministic. Only the estimate depends on it: settlement uses each generation's own `rates`, so a stale snapshot cannot mis-charge. PE-015's live smoke detects drift. This deliberately deviates from the docs' advice to read `/v1/pricing`. |
| D-16 | Each token type's typical count is the midpoint of its min and max, rounded up to a whole token. It is priced like the bounds, and the markup is applied once to each total. | It is an integer rule both languages share. With today's tables every midpoint is whole, and the text midpoint is always exactly e. |

## Fixture
`tests/fixtures/estimates.json` sits outside `resources/js`, so it is never bundled, but inside the Vitest root. Every number in it is a JSON integer, and there are no money strings. Hand-write it from the story's figures. Never generate it by running either implementation, because then it would stop being a contract.

| Key | Content |
| --- | --- |
| `pricing` | The output of `PricingTable::toArray()`. `nanodollars_per_token` holds the four token-type keys: 5000 / 10000 / 40000 / 12500. `tokens` holds `characters_per_text_token`, `text_token_variance_percent`, `per_reference_image`, `output` (quality → size → count) and `reasoning` (quality → `min` / `max`). The `tokens` keys are the snapshot's, so one override applies to both. |
| `cases[]` | Each case has a unique `name`, which is the PHP provider key and the Vitest title. It also has `prompt`, an optional `prompt_repeat` (default 1), `quality`, `size`, `reference_image_count`, an optional `pricing_overrides` (a partial `pricing` block, merged leaf by leaf) and `expected`. |
| `expected` | `prompt_characters`; `lines`, with one entry per token type holding `exact`, `tokens` (min / typical / max) and `provider_nanodollars` (min / typical / max); then `provider_nanodollars` and `user_nanodollars` for the totals. Key order must match PHP's output, because `assertSame` is order-sensitive. |

Formatting:
- Write each breakdown line on one line.
- Escape the combining mark, the precomposed é and the newline (`café`, `café`, `a\nb`) so that no editor can normalise them. Emoji and `東京の夜景` stay literal.
- The 40-character cases use `A lighthouse on a cliff at dawn, in oils`, PE-005's prompt.
- The 400-, 4,000- and 4,001-character prompts are `a` with `prompt_repeat`.

| Task | Cases (user min / typical / max n$, where the story doesn't list them) |
| --- | --- |
| 7 | AC9 docs worked maximum: `a` × 400, high, 1536x1024, 2 refs |
| 8 | AC10, all with refs as listed in the story: `""` low 1024x1024 (cheapest); `a` medium 1024x1024; the five 40-character rows; `a` × 4,000 high 1024x1536 with 4 refs (most expensive). Together with AC9, every quality × size appears. |
| 9 | AC10/AC4, all low 1024x1024 with 0 refs: 4 × `👍🏽`, `東京の夜景`, decomposed and precomposed café, `[fail] a cat`; then ` cat ` → 14,006,250 / 17,918,750 / 21,831,250 and `a\nb` → 14,000,000 / 17,912,500 / 21,825,000 |
| 10 | AC11: `abcde`, low, 1024x1024, 0 refs, with every `nanodollars_per_token` rate = 1. Then the same case with `tokens.reasoning.low.max` = 499 as well. |
| 11 | AC14: `a` × 4,001, low, 1024x1024, 5 refs → text 850 / 1,001 / 1,152, image 3,825, user 67,125,000 / 71,975,000 / 76,825,000 |

All of these figures were recomputed with integer arithmetic, and every AC4–AC11 and AC14 figure in the story agrees.

## Tests
`InteractsWithPricing` (`tests/Concerns/`) is a test trait with static helpers, so data providers can use them:
- `pinnedPricingSnapshot()` requires `config/pricing.php` directly, without booting the app.
- `publishedRates()` returns the snapshot's `rates`.
- `estimatesFixture()` decodes the fixture with `JSON_THROW_ON_ERROR`.

Five test files use it.

| Test | Location | Covers |
| --- | --- | --- |
| `ImageQualityTest`, `ImageSizeTest` | `tests/Unit/Domain/Shared/Enums/` | The exact wire values in Pixelforge's order, and the rejected strings (the PHP half of AC14) |
| `TokenBoundsTest` | `tests/Unit/Domain/Billing/ValueObjects/` | AC7 midpoints, including 0–499 → 250; the guards; the min + max overflow row, which no fixture case reaches |
| `PricingTableTest` | `tests/Unit/Domain/Billing/ValueObjects/` | AC3, the scalar rows, and transposition. The live table's 1536x1024 and 1024x1536 cells are equal, so only a snapshot with nine distinct cells can catch swapped sizes. |
| `PinnedPricingTableTest` | `tests/Unit/Domain/Billing/ValueObjects/`, extends `Tests\TestCase` | AC1–2: the container-resolved table's `toArray()` is identical (`assertSame`) to the fixture's `pricing` block |
| `EstimateGenerationCostActionTest` | `tests/Unit/Domain/Billing/Actions/` | AC4–11 and AC14, in the rows below |
| ↳ fixture data provider | | Runs every case. Each case builds its table from the pinned snapshot plus its overrides, and asserts that the table's `toArray()` equals the merged fixture block, so both languages price the same table. Then the mapped DTO must equal (`assertSame`) the case's `expected`. |
| ↳ other tests | | A unique-names guard and the negative-count row |
| `estimate.test.ts` | `tests/js/unit/lib/` | AC4–14 in the TS mirror, in the rows below |
| ↳ fixture cases | | `it.each` over every case, with its overrides merged recursively |
| ↳ safe integers | | Every number anywhere in the fixture is a safe integer |
| ↳ rejections | | The TS rejection rows below |
| `BillingTypesDeclareNoFloatsTest` (existing) | | Must stay green over the new Billing classes: no `float` or `mixed` in a public signature |

The PHP suite applies overrides to the snapshot, so every case runs through the production `fromSnapshot()` and no test-only constructor exists:
- `tokens` overrides are merged in with `array_replace_recursive`.
- `nanodollars_per_token` overrides become rate strings again through `BigDecimal::ofUnscaledValue(n, 3)` (1 → `"0.001"`).

**Why `PUBLISHED_RATES` is not sourced from the fixture.** The fixture's `pricing` block holds converted n$/token integers, while both PE-001 tests exercise decimal-string parsing. They now read the strings from the pinned snapshot instead. That is the single production copy, and the fixture's own block is checked against it.

Boundary rows to add beyond the ACs:

| Subject | Row | Expect |
| --- | --- | --- |
| `ImageQuality::from` | `ultra`, `LOW`, `Low`, `""` | `ValueError` |
| `ImageSize::from` | `512x512`, `1024X1024`, `1024×1024` (U+00D7), ` 1024x1024` | `ValueError` |
| `TokenBounds::between` | 0–1; 5–5; `PHP_INT_MAX − 1` to `PHP_INT_MAX` | typical 1; 5; `PHP_INT_MAX`, with no spurious overflow |
| `TokenBounds` | `between(501, 500)`, `between(-1, 5)`, `exact(-1)` | `InvalidArgumentException` |
| `PricingTable` | rate `"0.0005"` | `InvalidRateException` (AC3) |
| `PricingTable` | each of the nine `tokens.output` entries removed; each of the three `tokens.reasoning` ranges removed; reasoning low 600–500; a reasoning range without `max` | `InvalidPricingException` naming the path (AC3) |
| `PricingTable` | variance `0.15` (the live float), `"15"`, `-1`, `101`; `characters_per_text_token` `0`; `per_reference_image` `-1`; an output count of `"280"`; the `rates` or `tokens` block missing | `InvalidPricingException` |
| Action | `referenceImageCount` −1 | `InvalidArgumentException` |
| `estimate.ts` | rate `0.5`, `2 ** 53`, `-1`, `NaN`; `reference_image_count` `1.5`, `-1`; output count `280.5`; reasoning 600–500; `characters_per_text_token` `0`; variance `0.15`, `101` | Throws, and the message names the field |
| `estimate.ts` | quality `ultra`, `LOW`, `__proto__`, `constructor`; size `512x512`, `1024X1024`, `toString`, each passed through a cast, the way a malformed prop would arrive | Throws |
| `estimate.ts` | output rate `Number.MAX_SAFE_INTEGER` | Throws instead of returning an imprecise total |

## Risks
- **PR size is well over budget.** The estimate is ≈1,600–1,800 changed lines, about 330 of them fixture JSON. That is roughly 450 lines of PHP production code, 600 of PHP tests, 330 of fixture and 350 of TypeScript and Vitest.
  - The story's 450–600 estimate leaves out the per-case fixture rows and the AC3 / AC13 rejection rows.
  - It ships as one PR of 13 commits. Tasks 4, 5 and 7 are the largest at ≈170–300 lines; the rest are ≤ ≈150.
  - If the user asks for a cut, cut after task 11. PR A is the PHP side plus the fixture (≈1,400 lines). PR B is the TypeScript mirror (≈350), and PE-005 then depends on PR B.
- **Key order.** PHP `assertSame` on arrays is order-sensitive. `toArray()` and the test's DTO mapper must emit keys in fixture order: `TokenType` order for rates and lines, low / medium / high, and 1024x1024 / 1536x1024 / 1024x1536.
- **JSON integer form.** After parsing, JS cannot tell `5000` from `5000.0`. PHP decodes `5000.0` as a float and `assertSame` fails, so the PHP suite guards the integer literal form and Vitest guards the < 2⁵³ range.
- **Keyed data provider.** A duplicate `name` would silently overwrite an earlier case. The unique-names test prevents this.
- **Config must stay pure data.** Pure unit tests `require` `config/pricing.php` without booting the app, so `env()` and closures must never go into it. PE-009's driver and key settings belong elsewhere. `PinnedPricingTableTest` proves that `config('pricing')` yields the same table.
- **Variance quirk.** Never copy live `text_token_variance: 0.15` into config; the `0.15` rows pin its rejection in both languages. PE-015 diffs `rates` and `tokens`, mapping that one key.
- **Unicode.** Neither language trims or normalises. PHP `mb_strlen` and JS `[...s]` agree for valid UTF-8, which every fixture string is. Rejecting lone surrogates is PE-011's job.
- **No float paths.** Review greps `app/Domain/Billing` as in PE-001, and greps `lib/estimate.ts` for `Math.`, `toFixed`, `parseFloat` and decimal literals. In TS, `bigint` `/` is the only allowed division.
- **Override merge semantics.** Overrides replace leaves and never remove keys. PHP `array_replace_recursive` and the Vitest suite's merge must agree, and the per-case `toArray()` parity check enforces that on the PHP side.
