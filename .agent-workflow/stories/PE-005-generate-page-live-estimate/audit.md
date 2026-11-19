# Audit — PE-005-generate-page-live-estimate

**Verdict: PASS**

Run on branch `pe-005-generate-page-live-estimate` from `d5f29a4` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 1) and `qa.md` (QA PASSED) present.

## Scope

I checked the whole `[PE-005]` diff (`git diff main...HEAD`) against `story.md` AC1–13, `plan.md`, the review, both QA rounds and `.agent-workflow/CLAUDE.md`. Where the auditor checklist mentions Livewire, the project's Inertia/Vue rules apply instead. I gave the touch exact-value tip (AC5) the closest look, because the user asked for it to be fixed. PR size is not a finding, since one PR per story is the rule. PE-005's D-24 is left as it is. If PE-006 (#15) merges first, the orchestrator renumbers it.

## What was verified

- **`lib/money.ts` rounds exactly, with no float path.** I ran the module in the container (Node 22, `--experimental-strip-types`), outside the test suite:
  - every AC12 row matches: 373,875,000 → `$0.3739` / `$0.373875000`; 467,343,750 → `$0.4673`; 9,562,500 → `$0.0096`; 0 → `$0.0000`; 50,000 and 60,000 → `$0.0001`; 40,000 → `<$0.0001`; 1,234,567,890,123 → `$1,234.5679` / `$1,234.567890123`; −14,000,000 → `-$0.0140`; −50,000 → `-$0.0001`.
  - the half-unit boundary: 49,999 → `<$0.0001`; 50,000 → `$0.0001`; 149,999 → `$0.0001`; 150,000 → `$0.0002`; −49,999 → `-<$0.0001`. The carry into dollars works: 999,999,950,000 → `$1,000.0000`, and 999,999,949,999 → `$999.9999`.
  - ±(2⁵³ − 1) → `±$9,007,199.2547` / `±$9,007,199.254740991`, and `-0` → `$0.0000`.
  - 0.5, NaN, ±Infinity and ±2⁵³ throw a `RangeError` from both functions.
  - The only arithmetic is on bigint. `Number.isSafeInteger` guards the input before `BigInt()`.
- **The displayed figures are the mirror's output.**
  - Every displayed amount is a `MoneyAmount` (or the live-region text) fed straight from `estimateGenerationCost`. The one derived figure is the markup line (`user − provider`), and it is integer subtraction.
  - The mirror equals PHP through `tests/fixtures/estimates.json`, which both suites run. `GenerationFormPageTest` proves the pricing prop `assertSame`s the fixture's `pricing` block.
- **Nothing outside `lib/money.ts` formats money.**
  - `grep` finds no `toFixed` or currency style in `resources/js` outside `money.ts`. `formatCount` uses decimal `Intl.NumberFormat` for token and character counts only.
  - A lint probe (temporary files in `resources/js/zzprobe/`, deleted) showed that the rule fails as it should on `x.toFixed()`, `x?.toFixed()`, `{ style: 'currency' }`, `{ 'style': 'currency' }` and template expressions. Two spellings got through: `x['toFixed']` and ``style: `currency` `` (#1, fixed).
- **The copy never promises a ceiling.** The panel has no "at most", "never more than" or "guarantee". The only "at most" is AC7's prompt error, which the story words that way and which is not about cost.
- **The touch exact-value tip, in a real browser (Playwright `mobile` project, Pixel 7, `hasTouch`) at 375 px.** I used a temporary spec, `tests/e2e/zz-audit-touch.spec.ts`, then deleted it.
  - I tapped all 16 amounts on the AC3 + one-reference page in turn. Each tip opened fully on screen (x between 33 and 342), with 0 px horizontal overflow and exactly one tip open at a time, so a tap on a sibling amount closes the previous tip.
  - A swipe that starts on an amount scrolls the page and opens nothing (the press is cancelled). Keyboard focus on that amount afterwards still opens its tip.
  - The committed E2E still covers tap → open after 500 ms → second tap closes → tap elsewhere closes.
- **Keyboard and screen readers.**
  - Each amount is a `<button>` described by its tip, so its exact value is read even while the tip is closed.
  - Size and quality are native radios in a `fieldset` with a `legend`, so the arrow keys work. The E2E presses ArrowRight.
  - The prompt error and the reference URL hints are in their input's `aria-describedby`, with `aria-invalid` set.
- **The Vue layer.**
  - There is no `any` in `resources/js`.
  - `components/ui/` has no `@inertiajs` import.
  - `Generations/Create.vue` only wires props to components, and the estimate state lives in `useEstimate`.
- **No network request while editing.** The E2E records no XHR or fetch from page load to the end of the walk-through, in both projects.
- **Backend.** The controller is final and invokable and only renders. The route sits in the `auth` group. There is no new input, so there is nothing to validate or authorize.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
|---|---|---|---|---|---|
| 1 | **Two spellings got past the AC13 lint rule.** `x['toFixed'](2)` and ``{ style: `currency` }`` passed. The selectors matched only `property.name` and a string-literal `value`. | `eslint.config.js:12-16` | Warning | Fixed | `432afa7`. One `named()` helper matches an identifier, a string or a template literal, for the method name and for the style key and value. There are two new rows in `moneyFormatting.test.ts`, and both fail on the old config. Plain `style: 'decimal'` is still allowed. |
| 2 | **A hovered tip failed WCAG 1.4.13.** <ul><li>It closed when the mouse crossed the 6 px margin onto it, so it wasn't hoverable.</li><li>Escape closed it only while the amount had focus, so a mouse-opened tip couldn't be dismissed.</li></ul> | `resources/js/components/ui/MoneyAmount.vue:44-54,104-131` | Warning | Fixed | `748dcc2`. <ul><li>Mouse hover now listens on the wrapper that holds the amount and the tip.</li><li>The tip is spaced by padding, not a margin, so there is no gap to cross.</li><li>A document `keydown` listener closes an open tip on Escape. It is added and removed with the `pointerdown` one.</li></ul> There are five new Vitest rows and a desktop E2E. The E2E failed on the old component at "moved onto the tip" and passes now. The touch behaviour is unchanged, and the touch E2E and the probe above still pass. |
| 3 | **The breakdown's exact and estimated flags were hard-coded** (review suggestion). | `resources/js/components/estimate/EstimateBreakdown.vue:59,71,84,91` | Suggestion | Fixed | `57e9bcf`. `flagOf(line)` reads `EstimateLine.exact`, and the empty prompt keeps its `null`. A new panel test flips every line's `exact` and expects the labels to follow. |
| 4 | **"25%" was written out twice** (review suggestion). | `EstimateBreakdown.vue:104`, `EstimatePanel.vue:26` | Suggestion | Fixed | `83c9ea1`. `lib/estimate.ts` exports `MARKUP_PERCENT`, derived from `MARKUP_NUMERATOR / MARKUP_DENOMINATOR`. Both strings use it. A new test checks it against every fixture case: `user = ceil(provider × (100 + MARKUP_PERCENT) / 100)`. |
| 5 | **The live region held the tips** (review suggestion). Showing a tip inside the `aria-live` headline is itself a live-region addition, so a tap or focus could be announced on top of the description. | `resources/js/components/estimate/EstimatePanel.vue:42` | Suggestion | Fixed | `9cc7564`. <ul><li>The visible headline (`data-headline`) is no longer live.</li><li>An `sr-only` `<p aria-live="polite">` holds a text-only summary built with `formatUsd`: "Estimated cost $0.3477 to $0.4571, typically $0.4024", or the unavailable message.</li></ul> The panel tests prove the region has no child elements and is updated. The E2E checks both the headline and the announcement at every AC2/AC3 step. Not checked with a real screen reader. |
| 6 | **The "N of 4" count sat outside its fieldset** (review suggestion). | `resources/js/components/generation/ReferenceImagesField.vue:80` | Suggestion | Fixed | `eb6cbe8`. The visible count is `aria-hidden`. The legend carries `<span class="sr-only">, N of 4</span>`, so the group is named "Reference images (optional), 1 of 4". |
| 7 | **Removable rows were keyed by their index** (review suggestion). | `resources/js/components/generation/ReferenceImagesField.vue:85` | Suggestion | Fixed | `ba6e6ff`. Each row gets a stable key: it is pushed on add and spliced on remove, and a length watch keeps the keys in step if the parent replaces the rows. One new test proves that removing row 1 keeps rows 2 and 3 on their own DOM inputs, and fails on index keys. Another covers a replacement by the parent. |
| 8 | **The touch tip toggled closed only with a mouse click** (review suggestion). | `resources/js/components/ui/MoneyAmount.vue:45` | Suggestion | Fixed (before the audit) | `e764477` and `ebb500f`, from the QA round, re-verified above. |
| 9 | **A press that ends with no click and no `pointercancel` leaves the pressing pointer recorded.** Until the next click or blur, keyboard focus on that same amount doesn't open its tip. A long press might do this on some touch browsers. | `resources/js/components/ui/MoneyAmount.vue:56-58` | Suggestion | Skipped | It isn't reproducible in Chromium touch emulation: the swipe probe cancels the press, and focus afterwards opens the tip. Even when it happens, the exact value is still in the button's description, and Enter or Space opens the tip. Clearing it reliably would need a timing heuristic, because a tap's focus and click arrive after `pointerup`. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Exit 0: <ul><li>Pint passes on 87 files, and Larastan reports no errors.</li><li>PHPUnit: 415 passed (1,592 assertions).</li><li>Vitest: 309 passed in 21 files, up from 279.</li></ul> |
| Playwright, desktop and mobile | `make e2e` | 16 passed, 2 skipped. The skips are by design: the tap test on `desktop` and the new hover test on `mobile`. |
| `money.ts` boundaries | Node 22 in the container, scratch copy | As listed under *What was verified* |
| AC13 lint probe | `npx eslint` on temporary `resources/js/zzprobe/` (deleted) | Before #1: 6 of 8 money-formatting spellings caught. After: all 8, and plain or decimal formatting is not flagged |
| Touch tip at 375 px | Temporary `tests/e2e/zz-audit-touch.spec.ts` (deleted) | 16 of 16 amounts open in view, with 0 px overflow and one tip at a time. A swipe opens nothing |
| Hover regression proof | The new hover E2E against the old `MoneyAmount.vue` | Failed at "moved onto the tip", as expected. The component was then restored |

Cleanup:
- `make e2e` created two sign-up users (IDs 70 and 71). I deleted both by ID, which brings the user count back to 53, the same as QA's baseline.
- I removed `test-results/`.
- Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-005/audit/` (`audit-make-check.log`, `audit-e2e.log`, `touch-probe.log`).
