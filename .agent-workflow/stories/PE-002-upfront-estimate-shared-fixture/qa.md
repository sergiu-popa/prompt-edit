# QA — PE-002-upfront-estimate-shared-fixture

**Verdict: QA PASSED**

Run on branch `pe-002-upfront-estimate-shared-fixture` at `ff73209`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 2).

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Lint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint clean, Larastan no errors, PHPUnit 251 passed (801 assertions), Vitest 57 passed (52 in `estimate.test.ts`). |
| JS lint/format/types, Vitest | `make js-check` + `make js-test` (also run inside `make check`) | Clean. Vitest 57 passed. |
| Billing unit tests | `docker exec prompt-edit-app php artisan test --compact tests/Unit/Domain/Billing` | 235 passed (774 assertions) |
| Existing E2E (desktop + mobile) | `make e2e` | 4 passed (welcome page renders via Inertia; no horizontal scroll) |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-002/make-check.log`, `.../PE-002/make-e2e.log`, `.../PE-002/phpunit-billing-testdox.log`.

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 pinned snapshot, never fetched at runtime | `PinnedPricingTableTest` (`config holds the pinned snapshot verbatim`). No HTTP calls in `app/Domain/Billing`, `config/pricing.php` or `estimate.ts` (grep). | Pass |
| 2 table rates are integer n$/token; array form equals fixture `pricing` block | `PinnedPricingTableTest` (`resolved table is exactly the fixture pricing block`, `pinned snapshot builds rates in whole nanodollars per token`); `PricingTableTest` | Pass |
| 3 build throws on non-whole rate, missing output/reasoning entry, reasoning min > max | `PricingTableTest` (`rate that is not a whole nanodollar per token throws at build time`, `rejects a snapshot missing an output or reasoning entry`, `reasoning min above max` row); `estimate.test.ts` (`rejects a reasoning min above its max`) | Pass |
| 4 code-point counting; all six fixture count cases | Fixture rows: 8 code points, 5 Japanese, decomposed and precomposed café, 12 with `[fail]`, 5 with spaces, 3 with newline; `estimate.test.ts` and `EstimateGenerationCostActionTest` both iterate them | Pass |
| 5 text token bounds (0/1/40/400/4,000 chars) | `TokenBoundsTest`; fixture rows for 0, 1, 40, 400 (docs maximum) and 4,000 chars | Pass |
| 6 image exact, output exact, reasoning range with midpoint typical | `EstimateGenerationCostActionTest` fixture rows (0/2/4 refs via fixture); `output tokens are exact per quality and size` (9 cases); `reasoning tokens are a range per quality with a midpoint typical` (low/medium/high) | Pass |
| 7 typical = midpoint rounded up; reasoning 0–499 → 250 | `typical is the midpoint rounded up to a whole token` (PHP); fixture row `odd low reasoning range 0-499` | Pass |
| 8 min/typical/max provider and user n$; user charged once per total; breakdown flags exact vs estimated | `EstimateGenerationCostActionTest` asserts `'exact' => $line->isExact` per line and user totals via fixture; `estimate.test.ts` fixture rows | Pass |
| 9 docs worked maximum = 286,225,000 / 330,050,000 / 373,875,000 provider; user 357,781,250 / 412,562,500 / 467,343,750 | Fixture row `docs worked maximum` (PHP and Vitest) | Pass |
| 10 table of cases, every quality×size combination present | All 13 AC10 rows present in fixture (names listed by `every fixture case has a unique name` and the data-provider output); 9 combinations covered by AC9/AC10 rows | Pass |
| 11 markup round-up with synthetic 1 n$/token rates | Fixture rows `markup round-up: every rate 1 n$ per token` and `... odd low reasoning range 0-499` (PHP and Vitest) | Pass |
| 12 one fixture file, both suites iterate every case, no code change to add cases | `EstimateGenerationCostActionTest::fixtureCases` (data provider by name); `estimate.test.ts` `it.each`; `every fixture case has a unique name`; `tests/fixtures/estimates.json` is the only source | Pass |
| 13 TS pure, integer-only, throws on non-integer or unsafe input, fixture n$ are safe integers | `estimate.ts` imports only types (no Vue/Inertia); `estimate.test.ts` rows for fractional, unsafe, negative and NaN rates and counts; `holds every number as a safe integer`; `throws instead of returning an imprecise total`; grep finds no `Math.`, `toFixed`, `parseFloat` | Pass |
| 14 no input-limit enforcement; negative refs and unknown quality/size rejected in both languages | Fixture row `beyond the limits: 4,001 characters and 5 references`; `a negative reference count is rejected` (PHP); `ImageQualityTest` and `ImageSizeTest` (unknown values rejected); `estimate.test.ts` rows `rejects the quality "ultra"`, `"__proto__"`, `"constructor"`, size `"512x512"`, `"toString"`, `-1` references | Pass |

## Edge cases probed

- Test keywords, emoji with skin-tone modifier, decomposed vs precomposed accents, leading/trailing spaces and newlines all count as code points, in both languages, with identical expected totals.
- Prototype-chain keys (`__proto__`, `constructor`, `toString`) are rejected as quality or size in TS.
- Float and string inputs are rejected at the type boundary in PHP (`floats are rejected with a type error`) and at runtime in TS.
- Overflow: `PHP_INT_MAX` references surface as `NanodollarOverflowException` (`an image token count beyond 64 bits surfaces as a nanodollar overflow`); `PHP_INT_MAX` and `PHP_INT_MIN` boundaries in the charge and cost actions.
- Unsafe integer reference count (`9007199254740992`) and NaN rate rejected in TS.

## Visual verification

No UI in this story (`story.md`: "No UI"; "No PHP feature, JS integration or E2E tests: there is no HTTP and no UI yet"). No screens to screenshot and no viewport, scroll or interaction checks apply. The existing E2E suite passes (4 tests, desktop and mobile, see table above). No fixtures were created, so no cleanup or database counts were needed. The `make e2e` run rebuilt `public/build`, which is gitignored.

## Notes (non-blocking)

- PR size is still unresolved from review round 1: the branch is about 2,460 added lines against the ~400-line budget in `.agent-workflow/CLAUDE.md`. The user must choose before the PR is opened: one PR of 20 commits, or sequential PRs split at `f6c9ed1`. The PR description must state the size and the choice. This does not block QA.
- No Critical review items remain open.
