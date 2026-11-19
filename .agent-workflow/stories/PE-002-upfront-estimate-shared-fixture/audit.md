# Audit — PE-002-upfront-estimate-shared-fixture

**Verdict: PASS**

Run on branch `pe-002-upfront-estimate-shared-fixture` from `e41a8a6` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 2) and `qa.md` (QA PASSED) present.

## Scope

The whole `[PE-002]` diff (`git diff origin/main...HEAD`) was checked against `story.md` AC1–14, `plan.md`, both review rounds, the QA findings and `.agent-workflow/CLAUDE.md`. That covers `app/Domain/Billing`, `app/Domain/Shared/Enums`, `config/pricing.php`, `AppServiceProvider`, `resources/js/lib/estimate.ts`, `resources/js/types/{pricing,estimate}.ts`, `tests/fixtures/estimates.json`, `tests/Concerns/InteractsWithPricing.php` and every PHPUnit and Vitest file they touch. Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead. This story has no HTTP surface, page or prop wiring yet, so the controller and prop rules have nothing to check.

## What was verified

- **The fixture is a contract, not a recording.** An independent integer-only recomputation, a Python script that imports neither implementation, re-derived all 19 cases from the fixture's own `pricing` block and each case's overrides. It covered code points, all four lines' tokens and provider n$, the totals and the user price, and matched every `expected` exactly with 0 mismatches. Every user min / typical / max agrees with the story's AC9–AC11 and AC14 figures.
- **Every case runs in both suites.** `EstimateGenerationCostActionTest::test_the_estimate_matches_the_shared_fixture` reports 19 passed. Vitest's `against the shared fixture` block lists the same 19 names. Both iterate `cases` with no per-case code, so a new case runs in both. PHP also asserts each case's rebuilt table equals the merged fixture block, so both languages price the same table.
- **No float paths, PHP.** A grep of `app/Domain/Billing`, `app/Domain/Shared` and `config/pricing.php` for `intdiv`, `ceil(`, `floor(`, `round(`, `float`, `fdiv`, `fmod`, `**`, `/` between operands and `* 0.` / `* 1.x` matches docblocks only.
  - Text bounds, the midpoint and the markup all use `BigInteger::dividedBy(…, RoundingMode::Floor|Ceiling)`.
  - The markup goes through the same `CalculateUserChargeAction` (125 / 100, ceiling) that settlement uses, once per total.
  - The live float `0.15` is held as the integer `15`, and `PricingTable` rejects `0.15` and `"15"`.
- **No float paths, TS.** A grep of `lib/estimate.ts` for `Math.`, `toFixed`, `parseFloat`, `parseInt` and decimal literals finds nothing.
  - Every operation is on `bigint`, and bigint `/` is the only division: `ceilDiv` is `(a + b − 1n) / b`, and floor is plain bigint `/`.
  - The markup uses the named constants `125n` / `100n`.
  - The only `Number()` call converts a bigint that has already been checked against `MAX_SAFE_INTEGER`.
- **Overflow and unsafe integers.**
  - TS: every number read (rates, counts, rules, reference count) goes through `wholeNumber`, which requires a non-negative safe integer. Intermediates are unbounded bigints, and every emitted number is range-checked by `toSafeNumber`. An unsafe result throws instead of losing precision.
  - PHP: `TokenBounds::between` sums in `BigInteger`, so `PHP_INT_MAX − 1` to `PHP_INT_MAX` is pinned. An image-token overflow surfaces as `NanodollarOverflowException` (review round 1, `b2694fc`), and line and total costs overflow to `NanodollarOverflowException` through `Nanodollars`.
  - The Vitest walk asserts every fixture number is a safe integer. PHP `assertSame` would fail on a `5000.0` literal.
- **Code-point parity.** PHP uses `mb_strlen($prompt, 'UTF-8')` and TS uses `[...prompt].length`, with no trim or normalisation in either. The fixture escapes decomposed `café`, precomposed `café` and `a\nb`, so no editor can normalise them. Emoji and `東京の夜景` stay literal. The independent recount matched all six AC4 counts.
- **Rule parity.** Both languages reject a reasoning min above its max, `characters_per_text_token` < 1, a variance outside 0–100 and negative counts. They also reject an unknown quality or size: PHP through the enums, TS through an own-key lookup, so `__proto__`, `constructor` and `toString` throw.
- **Conventions.**
  - Every new PHP file declares `strict_types`, including `AppServiceProvider` (PE-001 audit #5), and every class is `final`, with value objects and DTOs `readonly`.
  - `estimate.ts` imports only types, with no Vue or Inertia.
  - `config/pricing.php` is pure data.
  - The review fixes from round 1 (prompt log, overflow, shared override helper, named-field errors) are all present.
- **PE-001 follow-ups.** PE-001 audit #3 (the duplicated `PUBLISHED_RATES`) is resolved: both tests read the pinned snapshot through `InteractsWithPricing`.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The PR is about 2,460 added lines against the ≈400-line budget (carried from review rounds 1–2 and QA). | `.agent-workflow/CLAUDE.md` § Git hygiene | Warning | Deferred | This is a process decision, not a code defect. Splitting now would rewrite history, which the pipeline forbids. The user decides before opening the PR: one PR of 21 commits, or sequential (not stacked) PRs split at `f6c9ed1`. The TS mirror commits `57d2358`, `fc34829`, `cfec57a` and `a2a5604` go in the second. The PR description must state the size and the choice. |
| 2 | A malformed top-level pricing prop block made the TS mirror throw a bare `TypeError` ("Cannot convert undefined or null to object") that does not name the field. This applied to `tokens`, `tokens.output`, `tokens.reasoning` and `nanodollars_per_token` being null, undefined or non-objects. It was inconsistent with the per-quality guards that `cfec57a` added. | `resources/js/lib/estimate.ts:140-143` | Suggestion | Fixed | Every block is now narrowed through `objectAt()`, so each throws the field-naming `Error`, and four Vitest rows assert the full messages (`a2a5604`). |
| 3 | The TS mirror validates only the table entries an estimate reads, for example the selected quality's reasoning range. PHP validates the whole table at build time. | `resources/js/lib/estimate.ts:134` | Suggestion | Skipped | The prop is always `PricingTable::toArray()` of a table that `fromSnapshot()` has already fully validated. TS still validates every value it uses, so it never prices from an invalid entry. Eager validation on every keystroke would add cost for no reachable case. |
| 4 | Lone UTF-16 surrogates (TS) and invalid UTF-8 bytes (PHP) are counted by different rules, so the two languages can disagree on such strings. | `resources/js/lib/estimate.ts:139`, `app/Domain/Billing/Actions/EstimateGenerationCostAction.php:58` | Suggestion | Deferred | Every fixture string is valid Unicode, where `mb_strlen` and `[...s]` agree. Rejecting malformed prompts is server validation's job: PE-011 (plan § Risks "Unicode"). The server recomputes the estimate and never trusts the client's figure. |
| 5 | The overflow ceilings differ: PHP fails above 2⁶³ − 1 n$ and TS above 2⁵³ − 1. | `resources/js/lib/estimate.ts:74`, `app/Domain/Billing/ValueObjects/Nanodollars.php` | Suggestion | Skipped | TS throws instead of approximating, so the two never disagree on a value, and AC13 asks for exactly this. The most expensive possible estimate is 492,937,500 n$, about 10⁷ times below either ceiling. |
| 6 | The Vitest `mergeRecursive` replaces arrays whole, while PHP `array_replace_recursive` merges them by index. | `tests/js/unit/lib/estimate.test.ts:34` | Suggestion | Skipped | The `pricing` block contains no lists (only objects and integers), so this cannot happen with the current fixture shape. The PHP suite's per-case `toArray()` parity check would catch any override that PHP merges differently from the fixture block. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0. Pint passes on 63 files, Larastan reports no errors, PHPUnit 251 passed (801 assertions), Vitest 61 passed (56 in `estimate.test.ts`). |
| Fixture data provider | `php artisan test --filter=test_the_estimate_matches_the_shared_fixture` (in container) | 19 passed (38 assertions) |
| Independent fixture recomputation | `python3 -I …/scratchpad/PE-002/recompute.py tests/fixtures/estimates.json` | 19 cases, 0 mismatches |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-002/audit-check.log`.

The fix touched only a pure `lib/` module and its unit test, with no page, component or build input, so `make e2e` was not re-run. QA ran it on `ff73209`: 4 passed.
