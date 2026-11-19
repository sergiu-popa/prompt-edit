# QA — PE-001-money-nanodollars-and-markup

**Verdict: QA PASSED**

Run on branch `pe-001-money-nanodollars-and-markup` at `465d706`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE`.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` + `make js-check` + `make js-test` | Exit 0. PHPUnit 145 passed (624 assertions). Vitest 5 passed. |
| Billing unit tests | `php artisan test --testdox tests/Unit/Domain/Billing` | 140 passed (610 assertions) |
| Existing E2E (desktop + mobile) | `make e2e` | 4 passed (welcome page renders via Inertia; no horizontal scroll) |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-001/make-check.log` and `.../PE-001/make-e2e.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 rate strings convert exactly | `TokenRatesTest` (published rates, `12.5`, `12.500000`, `5`, `0.001`, `0`, `0.00`) | Pass |
| 2 non-whole rate throws, names the rate, no rounding | `TokenRatesTest` (`0.0005`, `12.5005`) | Pass |
| 3 malformed rate throws `InvalidRateException` | `TokenRatesTest` (all listed malformed inputs, plus `\n` end-anchor and non-ASCII digits) | Pass |
| 4 rates keys exact, values strings | `TokenRatesTest` (missing key ×4, unpriced key, JSON number, int, null, array) | Pass |
| 5 usage keys, counts, `total_tokens` | `TokenUsageTest` (docs 10,411 builds; 10,412 throws; missing, negative, float, string, null, unpriced key) | Pass |
| 6 docs worked maximum = 373,875,000 n$ | `CalculateProviderCostActionTest` | Pass |
| 7 docs succeeded = 305,510,000 n$ | `CalculateProviderCostActionTest` | Pass |
| 8 same usage, different rates, different cost (176,510,000 n$) | `CalculateProviderCostActionTest` | Pass |
| 9 zero usage = 0; `"0"` rate contributes 0 | `CalculateProviderCostActionTest` | Pass |
| 10 charges 467,343,750 / 381,887,500 / 220,637,500 / 0 | `CalculateUserChargeActionTest` | Pass |
| 11 ceil: 1→2, 2→3, 3→4, 4→5, 5→7, 8→10 | `CalculateUserChargeActionTest` | Pass |
| 12 negative cost throws | `CalculateUserChargeActionTest` (`-1`, `PHP_INT_MIN`) | Pass |
| 13 exact add/sub/compare, negatives, zero | `NanodollarsTest` | Pass |
| 14 out-of-range throws (add, sub, multiply, markup) | `NanodollarsTest`, both Action tests | Pass |
| 15 9-decimal render, no rounding | `NanodollarsTest` (all listed values incl. `PHP_INT_MAX`, `PHP_INT_MIN`) | Pass |
| 16 9-decimal parse, round-trip, malformed/sub-nano throws | `NanodollarsTest` | Pass |
| 17 no float in public API | `NanodollarsTest` and `TokenUsageTest` `TypeError` rows; `BillingTypesDeclareNoFloatsTest` reflection guard (with self-test); Larastan in `make check` | Pass |

## Edge cases probed

- Overflow boundaries: `PHP_INT_MAX` + 1, `PHP_INT_MIN` − 1, `PHP_INT_MAX` × 2, markup at `7,378,697,629,483,820,645` (fits) and `…646` (throws).
- Parsers: trailing newline, leading `+`, leading space, exponent, decimal comma, non-ASCII digits, `-9223372036.854775808` accepted, `9223372036.854775808` rejected.
- Source read of `app/Domain/Billing`: no `/` on ints, `round`, `ceil`, `floor`, `number_format`, `(float)` or float literals. The only match for a decimal pattern is a docblock example string.

## Visual verification

No UI in this story (`story.md`: "No UI"; pure domain code with no HTTP surface). No screens to screenshot and no viewport, scroll or interaction checks apply. The existing E2E suite passes (see table above). No fixtures were created, so no cleanup or database counts were needed.

## Notes (non-blocking, from review)

- PR size: review.md records about 1,370 changed lines against the ~400-line budget in `.agent-workflow/CLAUDE.md`. This needs your decision before the PR is opened (one PR of 8 commits, or two PRs split at `786a40e`). The `brick/math` promotion to a direct `require` also needs your approval.
- Review suggestion still open: `CalculateUserChargeAction::execute` docblock omits `@throws InvalidArgumentException` for negative cost. Not a failure.
