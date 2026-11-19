# Review — PE-005-generate-page-live-estimate

## Round 1

**Verdict: APPROVE**

Scope reviewed: the 21 `[PE-005]` commits `629d387..9ffab90` on `pe-005-generate-page-live-estimate` (`git diff origin/main...HEAD`), checked against `story.md` and `plan.md`.

Checks run on HEAD:
- `make check`:
  - Pint passes on 87 files and Larastan reports no errors.
  - PHPUnit runs 415 tests with 1,592 assertions, all passing.
  - ESLint (with the new D-24 rule) and vue-tsc are clean.
  - Vitest runs 262 tests in 21 files, all passing.
- `make e2e`: 14 Playwright tests pass in the `desktop` and `mobile` projects, including `generate.spec.ts` in both.
- `.github/workflows/ci.yml` runs `npm run lint` and `npm test`, so CI enforces the AC13 rule and its test.

Only HEAD was run. Earlier commits were checked by reading their file lists, not by running each one.

### Summary

The implementation matches the plan file for file. I accept the deviations the Engineer notes record:
- `AmountRange.vue` is a small, reused presentational piece.
- The breakdown derives the per-image count from the estimate, so a malformed prop can't throw in the template.
- `lib/counting.ts` has its own unit test.
- `visibleText` and `useInnerText` read text as it is rendered.
- The vue-tsc `placeholder` workaround has a comment saying why.

What I checked in each part:
- **Backend (AC1):**
  - `ShowGenerationFormController` is `final` and invokable, and does nothing but render. There is no input to validate or authorize: the `auth` group handles access, and the guest redirect is tested.
  - `GenerationInputLimits` is `final`, has typed constants and an array-shape `toArray()`, and PE-011 can reuse its constants.
  - `GenerationFormPageTest` proves:
    - the pricing prop is `assertSame` to the fixture block;
    - the limits are exactly 4,000 and 4;
    - the top-level props are exactly `auth`, `errors`, `pricing`, `limits`.
- **`lib/money.ts` (AC12–13):**
  - It uses bigint only and rounds half away from zero, with the magnitude rounded and the sign kept separately.
  - `<$0.0001` and `-<$0.0001` cover the sub-display amounts.
  - Its guard is `Number.isSafeInteger`.
  - Every AC12 row and every plan boundary row is in `money.test.ts`, including the carry into dollars and `MAX_SAFE_INTEGER`.
- **Lint rule (AC13):**
  - Both `no-restricted-syntax` and `vue/no-restricted-syntax` carry the selectors, so template expressions are caught.
  - `money.ts` is the only file ignored.
  - `moneyFormatting.test.ts` runs the project's real config. It fails on a parse error instead of passing silently.
- **`useEstimate`:**
  - It catches whatever `estimateGenerationCost` throws and returns `null` (AC10).
  - It counts only non-blank rows, so invalid URLs still count (AC9).
  - `perReferenceNanodollars` is `max(n+1) − max(n)`. Because 765 × 10,000 × 1.25 is a whole number, the ceil markup can't move it, and it is 9,562,500 at every count.
- **Components:**
  - **Textarea** never sets `maxlength`.
  - **RadioGroup** uses native radios in a `fieldset` with a `legend` (AC8, proven by keyboard in E2E).
  - **MoneyAmount** uses `aria-describedby` to a `display: none` tooltip (AC5, with the accessible description asserted in E2E).
  - **PromptField** counts code points, so 👍🏽 counts 2, and it never truncates (AC7).
  - **ReferenceImagesField:**
    - it adds and removes rows and caps them at 4;
    - its URL hint appears only after the field loses focus;
    - the mousedown fix is pinned by a test.
  - **EstimatePanel and EstimateBreakdown** cover AC2, AC4 (the full table), AC6 and AC10. The ceiling-wording test reads only the panel, as the plan's Risks asked.
- **Page and layout (AC2, AC3, AC11):**
  - `@submit.prevent` stops implicit submission.
  - The panel sits in an `<aside>` after the form, sticky from `lg`.
  - The Generate link carries `aria-current`, which ignores the query string.
- **E2E:**
  - It checks the AC2 figures, every AC3 row, the AC8 arrow key, "+$0.0096 each", and both exact-value reveals (`$0.447575000` and `$0.457137500`).
  - It checks the layout in both projects and finds no horizontal scroll.
  - It records no XHR or fetch requests from the moment the page has loaded.
- **Security:**
  - There is no new input on the server.
  - Vue escapes every value, so there is no XSS path.
  - The secrets are not touched.

### Commit hygiene

The history reads cleanly:
- one bookkeeping commit for the plan (`629d387`) and one for the checkboxes and status (`9ffab90`);
- one code commit per plan task, each with its tests;
- subjects in `[PE-005] Imperative …` form, at most 67 characters, and every commit has the trailer.

Notes on individual commits:
- The D-24 row in `requirements.md` comes with the lint rule in `3938def`, as the plan specified. PE-002 and PE-003 did the same, so this is fine.
- The four browser-check follow-ups (`9ad2606`, `86c30b3`, `db3cc16`, `4665d94`) are each one small change, and the Engineer notes explain them.
  - Only `db3cc16` changes behaviour, and it adds a test.
  - The other three change only CSS and layout, and the existing suites still pass on them.
- The follow-ups fall on the right side of the planned cut. `9ad2606` and `86c30b3` fix tasks 4, 10 and 11 and come before `0be8409`. `db3cc16` and `4665d94` fix task 14 and come after it. A split at `86c30b3` therefore stays clean.
- `40dc9ba` also changes `MoneyAmount`'s wrapper from `inline-flex` to `inline-block`. That one-line change is what lets the amount sit inline in the breakdown, so it belongs to that commit.

### Warnings

- [x] **The PR is far over the size rule, and the story asked for a split.** `.agent-workflow/CLAUDE.md` § Git hygiene sets about 400 changed lines per PR, and the story's Technical Notes say to "split it before planning into two stories". This branch has about 2,520 insertions:
  - production code (`app/`, `routes/`, `resources/js`, `eslint.config.js`): about 890;
  - tests: about 1,450;
  - `.agent-workflow`: about 180.

  The plan kept one story but named a cut after task 12 (§ Size). In the history, that cut is clean at `86c30b3`:
  - PR A, `629d387..86c30b3`, is the page without references: about 1,950 lines, covering AC1–8 and AC10–13, with AC3 and AC4 ending at High · Landscape.
  - PR B, `0be8409..4665d94` plus the bookkeeping, is the reference rows: about 600 lines, covering AC9, the last AC3 row and the full AC4 table.

  The largest commits are `40dc9ba` (409 lines) and `627e31f` (401 lines), and more than half of each is tests.

  — acknowledged: splitting needs new branches or rewritten history, and agents in this pipeline may do neither. **The user decides before the PR is opened.** The options are one PR of 21 commits, as with PE-001 to PE-004 (merged at 1,600–4,300 lines), or sequential, not stacked, PRs split at `86c30b3`. The PR description should state the size and the choice.

### Suggestions

- [ ] `resources/js/components/estimate/EstimateBreakdown.vue:59,71,84,91`: the exact and estimated flags are hard-coded per line. `lib/estimate.ts` already returns them as `EstimateLine.exact` (from `KNOWN_UPFRONT`, line 27).
  - Today both agree. But if the mirror ever changes, the panel would label a line wrongly and no test would fail, because `EstimatePanel.test.ts` asserts the hard-coded words.
  - Fix: build each flag from the estimate: `flag: line.exact ? 'exact' : 'estimated'`. The empty prompt keeps its `null`.
- [ ] `resources/js/components/estimate/EstimateBreakdown.vue:104` and `EstimatePanel.vue:26`: "25%" is written as text in two places. The rate it describes lives in `lib/estimate.ts:22-23` (`MARKUP_NUMERATOR` / `MARKUP_DENOMINATOR`, D-04).
  - Fix: export a `MARKUP_PERCENT` (25) from `lib/estimate.ts` and use it in both strings, so the copy can't drift from the rate that is charged.
- [ ] `resources/js/components/estimate/EstimatePanel.vue:42`: the `aria-live="polite"` headline contains the MoneyAmount tooltips. Focusing an amount there switches its tooltip from `display: none` to shown, and a screen reader may announce that as a live-region addition, on top of reading the button's description. This is not verified with a screen reader.
  - Fix, if QA hears the value read twice: keep the visible headline out of the live region, and add an `sr-only` `<p aria-live="polite">` holding the formatted range and typical figure.
- [x] `resources/js/components/ui/MoneyAmount.vue:45`: a click only reveals the tip and never hides it. On touch, the tip closes only on blur or mouseleave. Safari does not focus buttons on tap, so the tip can stay open until the user taps another hover target.
  - Fix: make the click toggle (`isRevealed = !isRevealed`) and keep Escape and blur closing it. Add one `MoneyAmount.test.ts` row: a second click hides the tip.
  - Resolved in `e764477` (QA fix): a touch or pen tap toggles the tip. A mouse click keeps it open, since hover already opened it.
- [ ] `resources/js/components/generation/ReferenceImagesField.vue:80`: the "N of 4" count sits outside the `fieldset` (`4665d94`), so assistive technology doesn't tie it to the group, and it is read as stray text before the legend.
  - Fix: keep the visible count where it is, mark it `aria-hidden="true"`, and add `<span class="sr-only">, {{ rows.length }} of {{ max }}</span>` inside the `legend`.
- [ ] `resources/js/components/generation/ReferenceImagesField.vue:85`: `:key="index"` on rows that can be removed from the middle. The values stay correct because the inputs are controlled. But each DOM input's own state, such as its undo history and any half-composed IME input, then belongs to the wrong row.
  - Fix: keep a stable id per row, for example a parallel `ref<number[]>` of ids that grows on add and is spliced on remove, and key on that id.
