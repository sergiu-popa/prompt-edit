# Review — PE-002-upfront-estimate-shared-fixture

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the `[PE-002]` commits `0e971f0..f7bba3a` on `pe-002-upfront-estimate-shared-fixture` (`git diff origin/main...HEAD`), against `story.md` and `plan.md`.

Checks run on HEAD:
- `make lint`: Pint passes on 63 files.
- `make stan`: Larastan reports no errors.
- `make test`: PHPUnit runs 250 tests with 799 assertions, all passing.
- `make js-check` and `make js-test`: ESLint, Prettier and vue-tsc are clean, and Vitest runs 49 tests (44 in `estimate.test.ts`), all passing.

### Summary

The implementation matches the plan class for class: `ImageQuality`, `ImageSize`, `TokenBounds`, `CostBounds`, `PricingTable`, `InvalidPricingException`, `TokenType::isKnownUpfront()`, `TokenRates::forType()`, the two DTOs, `EstimateGenerationCostAction`, the singleton binding, `lib/estimate.ts` and its types.

- **Money paths:** all PHP arithmetic goes through `BigInteger` or `Nanodollars`. A grep of `app/Domain/Billing` finds no `/`, `intdiv`, `ceil`, `floor` or `round`. In `lib/estimate.ts`, all arithmetic is in `bigint`, and a grep finds no `Math.`, `toFixed`, `parseFloat` or decimal literal. The markup is applied once to each total in both languages, through the same `CalculateUserChargeAction` settlement uses on the PHP side.
- **Text bounds:** `floor(e(100−v)/100) + ceil(e(100+v)/100) = 2e` for any v, so the typical midpoint is exactly e, as D-16 says. Both languages compute it the same way.
- **Fixture (AC9–AC11, AC14):** I checked every case's user min / typical / max in `tests/fixtures/estimates.json` against the story table, and every value agrees. Decomposed and precomposed café are escaped (`café`, `café`; bytes checked), and the newline is `\n`. Every number is a JSON integer.
- **Contract:**
  - The PHP data provider and Vitest `it.each` both iterate every case by `name`, with no per-case code.
  - The PHP suite rebuilds each case's table through the production `fromSnapshot()`, and asserts it equals the merged fixture block, so both languages price the same table.
  - `PinnedPricingTableTest` locks `config('pricing')` → `toArray()` → the fixture block (AC1–2).
- **Validation (AC3, AC13, AC14):**
  - `PricingTable` validates everything at build time, and the error names the path.
  - The TS mirror rejects non-integer, negative, NaN and unsafe values, naming the field.
  - Quality and size are looked up with `Object.hasOwn`, so `__proto__`, `constructor` and `toString` throw.
  - An unsafe result throws instead of returning an imprecise number.
- **PE-001 follow-up:** the duplicated `PUBLISHED_RATES` constant is gone. Both tests read the snapshot through `InteractsWithPricing`, as the PE-001 review suggested.
- **Security and performance:** pure code with no I/O, HTTP or queries, so there is no authorization, mass-assignment, SQL, XSS or N+1 surface.

### Commit hygiene

The history reads cleanly:
- one bookkeeping commit for the plan (`0e971f0`) and one for the checkboxes (`f7bba3a`);
- one code commit per plan task, each with its tests;
- subjects in `[PE-002] Imperative …` form, at most 72 characters, and every commit carries the trailer.

The D-15 and D-16 rows in `requirements.md` ride with the code commits that introduce them (`57b3f89`, `f573b8a`), as the plan specified. They document the code change rather than pipeline bookkeeping, so that is fine. `TokenRates::forType()` lands in `0d2b6f7` because `toArray()` needs it, so it belongs to that change.

### Warnings

- [x] **The pipeline prompt is not in the prompt log.** `.agent-workflow/CLAUDE.md` § Prompt log says to append every user prompt verbatim to `.agent-workflow/prompts.md`. PE-001 did this in its first commit (`6ffcc27 [PE-001] Log the pipeline prompt`). This branch has no such commit, and `prompts.md` ends at Prompt 6. The prompt that started this story, `Merged. Continue with 002`, is missing.
  - Fix: append it verbatim under a dated PE-002 heading as Prompt 7, together with any other user prompt since Prompt 6.
  - Commit it alone as bookkeeping: `[PE-002] Log the pipeline prompt`.

  — fixed in `9078ac3`: Prompt 7 appended verbatim under the Session 2 heading.
- [x] **PR size breaks the project rule.** `.agent-workflow/CLAUDE.md` § Git hygiene sets about 400 changed lines per PR and says to split the story if it is larger. The story's own Technical Notes said to split it before planning if it went over. This branch has about 2,280 changed lines:
  - `app/` 579, `config/` 43, `resources/js` 219;
  - `tests/Unit` 724, `tests/Concerns` 67, `tests/js` 139, and the fixture 374;
  - `.agent-workflow` 139.

  Instead of splitting, the plan kept one PR and named a cut point. The natural cut is after `f6c9ed1`. The PHP side and the fixture are about 1,790 lines, and the TypeScript mirror (`57d2358`, `fc34829`) is about 360 lines, which fits the budget on its own.

  — acknowledged: no engineering action in this pipeline can fix it, because splitting now means rewriting history. Each commit can be reviewed alone. The largest is `0bda616` at 372 lines, and most test lines are data-provider rows or fixture JSON. **The user must decide this before the PR is opened.** The options are one PR of 15 commits, or sequential (not stacked) PRs split at `f6c9ed1`. The PR description must state the size and the choice.

### Suggestions

- [x] `app/Domain/Billing/Actions/EstimateGenerationCostAction.php:62`: `BigInteger::…->toInt()` throws brick's `IntegerOverflowException` when `765 × referenceImageCount` exceeds `PHP_INT_MAX`. That breaks PE-001's rule that Billing overflow surfaces only as `NanodollarOverflowException`, and the docblock at lines 45–46 does not list it.
  - It is only reachable with a count above about 1.2 × 10¹⁶, which PE-011 validation blocks.
  - Fix: catch and rethrow as `NanodollarOverflowException`, or add an `@throws` for brick's exception, plus one test row.

  — fixed in `b2694fc`: `imageTokens()` rethrows as `NanodollarOverflowException::forTokenCount()`; a test with `PHP_INT_MAX` references pins it.
- [x] `tests/Unit/Domain/Billing/ValueObjects/PricingTableTest.php:247-250` and `tests/Unit/Domain/Billing/Actions/EstimateGenerationCostActionTest.php:134` both run `array_replace_recursive(self::pinnedPricingSnapshot(), …)`.
  - Fix: add `pinnedPricingSnapshotWith(array $overrides)` to `Tests\Concerns\InteractsWithPricing` (guidelines §6). The action test keeps only its n$ → rate-string conversion.
  - Do this before PE-005/PE-006 add a third copy.

  — fixed in `66059a2`: both tests use `InteractsWithPricing::pinnedPricingSnapshotWith()`.
- [x] `resources/js/lib/estimate.ts:121,131-132`: if a malformed prop makes `pricing.tokens.reasoning[quality]` `null` or a number, `reasoning.min` throws a generic `TypeError` that does not name the field, and the same goes for a non-string `prompt` at line 118. AC13 does not require handling this.
  - Fix, if wanted: guard with the same `field`-naming error as `wholeNumber`.

  — fixed in `cfec57a`: `objectAt()` guards the per-quality output and reasoning entries, and a non-string prompt throws; Vitest rows cover null, number, array and undefined.
