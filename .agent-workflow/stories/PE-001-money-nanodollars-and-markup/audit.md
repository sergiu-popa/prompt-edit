# Audit — PE-001-money-nanodollars-and-markup

**Verdict: PASS**

Run on branch `pe-001-money-nanodollars-and-markup` from `c183ed0` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE) and `qa.md` (QA PASSED) present.

## Scope

Every file under `app/Domain/Billing` and `tests/Unit/Domain/Billing`, plus the `composer.json` change, checked against `story.md` AC1–17, `plan.md`, the review and QA findings, and `.agent-workflow/CLAUDE.md`. Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no HTTP surface or UI, so those rules have nothing to check.

## What was verified

- **Float paths.** Reading the code and grepping `app/Domain/Billing` for `round(`, `ceil(`, `floor(`, `number_format`, `(float)`, `floatval`, `intdiv`, `/` and decimal literals finds one match, which is a docblock example string.
  - All arithmetic runs through `BigInteger`/`BigDecimal`.
  - The only path back to `int` is `Nanodollars::fromBigInteger()`, which uses brick's checked `toInt()`.
  - The markup ceil is `BigInteger::dividedBy(100, RoundingMode::Ceiling)`. The brick/math 1.0 signature was confirmed in `vendor/`.
- **Rounding.** Rates are never rounded. `TokenRates` rejects any rate with a non-zero fractional part after the ×10³ shift. `fromUsdString` rejects more than 9 decimals before parsing, so `toBigInteger()` is always exact. The markup only rounds up, and the 0–99 sweep checks that the over-charge stays between 0 and 0.75 n$.
- **Overflow.** Add, subtract, multiply, markup, the rate conversion and the USD parse all overflow to an exception, never to a float. The `total_tokens` sum uses `BigInteger::sum`, so absurd counts cannot become a float either. Boundary rows pin both sides of every edge, for example markup at `…645` fits and `…646` throws.
- **Exactness against the story's examples.** Every AC number (373,875,000 / 305,510,000 / 176,510,000 n$ cost; 467,343,750 / 381,887,500 / 220,637,500 n$ charge; the AC11 ceil rows; the AC15 render strings) is a data-provider row and passes.
- **Input strictness.** Both patterns use `[0-9]` and `\z`. Integer array keys are rejected as unexpected. `is_string`/`is_int` checks run before any parsing, so a JSON float never reaches brick/math.
- **AC17.** The `TypeError` rows pass and the reflection guard passes, including its self-test. Larastan level 8 is clean.
- **Dependency.** `composer.json` adds `brick/math: ^1.0` (locked 1.0.0). The lock diff is the content-hash only.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The PR is about 1,370 changed lines against the ≈400-line budget (carried from review). | `.agent-workflow/CLAUDE.md` § Git hygiene | Warning | Deferred | This is a process decision, not a code defect. Fixing it would mean dropping AC-required test rows or rewriting history, which the pipeline forbids. The user decides when opening the PR: one PR of small per-primitive commits, or two sequential PRs split at `786a40e`. The PR description must state the size and flag the `brick/math` promotion for approval. |
| 2 | `execute()` throws `InvalidArgumentException` for a negative cost, but its docblock listed only `NanodollarOverflowException` (review suggestion 1). | `app/Domain/Billing/Actions/CalculateUserChargeAction.php:24` | Suggestion | Fixed | Added `@throws InvalidArgumentException when the provider cost is negative` (`036a631`). |
| 3 | `PUBLISHED_RATES` is declared in two test files (review suggestion 2). | `tests/Unit/Domain/Billing/ValueObjects/TokenRatesTest.php:14`, `tests/Unit/Domain/Billing/Actions/CalculateProviderCostActionTest.php:16` | Suggestion | Deferred | Two copies are within the guideline (extract at 3 or more). PE-002 introduces the shared pricing JSON fixture. Source these rates from that fixture there rather than adding a third copy. |
| 4 | `USD_PATTERN` accepts non-canonical input (`"-0.0"`, `"007.5"`). It parses to the right amount but does not round-trip to the same string (review suggestion 3). | `app/Domain/Billing/ValueObjects/Nanodollars.php:26` | Suggestion | Deferred | No AC requires rejecting it, and the parsed value is exact. `/v1/usage` sends canonical 9-decimal strings. If PE-018 wants strict canonical input, tighten the pattern there. |
| 5 | A caller file without `declare(strict_types=1)` can pass `12.0` or `"12"` to `Nanodollars::__construct`, `multipliedBy` or `TokenUsage::__construct`, and PHP coerces them silently. A probe confirmed `new Nanodollars(12.0)` gives `12`. Five non-Billing files lack the declaration (`app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Http/Controllers/Controller.php`, both `ExampleTest.php`). | `app/Domain/Billing/ValueObjects/Nanodollars.php:28`, `app/Domain/Billing/ValueObjects/TokenUsage.php:24` | Suggestion | Deferred | This cannot be enforced from the callee while the parameters are typed `int`. AC17 is specified under strict types and passes. Every Billing file and test declares strict types, and the Pixelforge input path (`TokenRates::from` / `TokenUsage::from`) type-checks with `is_string`/`is_int`, so no current caller is affected. Follow-up: add Pint's `declare_strict_types` rule on a `bootstrap/*` branch so that every future money caller (PE-002, PE-007) is strict. That is tooling outside this story's scope. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint 45 files pass, Larastan no errors, PHPUnit 145 passed (624 assertions), Vitest 5 passed. |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-001/audit-check.log`.

No frontend or E2E surface changed, so `make e2e` was not re-run. QA ran it on `465d706`: 4 passed.
