# QA — PE-005-generate-page-live-estimate

**Verdict: QA FAILED**

Run on branch `pe-005-generate-page-live-estimate` at `623ae83`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE`. Status stays `qa-ready`.

## Failing items

1. **AC11 layout at 375px: the breakdown overflows the estimate card.** In the AC3 state (High, Landscape, 1 reference), the breakdown table's right edge is at 365.66px, but the card's inner edge is at 359px. That is 6.66px past the card border, so the right-hand amounts (`$0.0077`, `<$0.0001 – $0.0001`, `$0.4571`) cross the card edge. The table is 333px wide with no internal scroll, and the document never scrolls sideways, so the existing no-horizontal-scroll check does not catch it. The Playwright mobile project (Pixel 7, 412px) does not reproduce it either. Measured overflow at 768 and 1440: 0px.
   - Fix: make the breakdown fit the card at phone width (narrower label or token columns, or let the token cell wrap below about 400px). Then re-run the 375 browser pass and add a 375 layout assertion to `generate.spec.ts`.
   - Screenshots: `ac3-375.png`, `ac3-panel-375.png` (see Visual verification).

## Non-blocking

- At 1440 the Tokens column wraps: `1,000–` / `8,000`, `765 × 1 =` / `765`, `High ·` / `1536×1024`. The mockup keeps these on one line. Cosmetic only.
- 768px is a single column (the panel sits after the form). Breakpoint is `lg` (1024px). The story says "desktop shows two columns" without a number, so this is treated as correct.
- Touch tap was not exercised. The pass used click and keyboard focus, not a `hasTouch` context. The review's open touch-close suggestion (`MoneyAmount`) is untested.
- Review suggestions remain open (`EstimateBreakdown` hard-coded flags, `25%` copy, live-region scope, `ReferenceImagesField` count and keys). None is a QA failure.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| PHP lint, Larastan, PHPUnit | `make check` (lint, stan, test, js-check, js-test in order) | Exit 0. PHPUnit 415 tests, 1,592 assertions, all passed. Lint and stan ran before tests and did not stop the target. |
| JS lint, Prettier, vue-tsc | `make js-check` | Clean. |
| Vitest | `make js-test` | 21 files, 262 tests, all passed. |
| E2E, both projects | `make e2e` | 14 passed (7 desktop, 7 mobile). Includes `generate.spec.ts` in both. Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-005/e2e-baseline.log` |
| Browser pass | Temporary `tests/e2e/qa-shots.spec.ts` (deleted, copy in scratch) | 1 passed at 375, 768 and 1440. No console or page errors (fixture guard), no horizontal scroll, no XHR or fetch while editing. |

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 Generate page is the signed-in home; guests to `/login`; props and TS types | `GenerationFormPageTest` (`test_a_guest_is_sent_to_sign_in`, `test_the_signed_in_home_is_the_generate_page`, `test_the_page_gets_only_the_shared_props_pricing_and_limits`, `test_the_limits_prop_holds_pixelforges_input_limits`); E2E redirects to `/dashboard` | Pass |
| 2 Defaults `$0.0581 – $0.0863`, typically `$0.0722`, "0 tokens. Start typing your prompt." | `EstimatePanel.test.ts` ("headlines the defaults", "asks for a prompt while it is empty"); E2E; browser pass at 375, 768, 1440 | Pass |
| 3 Live figures for Medium, High, Landscape and one reference, no request | `useEstimate.test.ts` (follows prompt, quality, size); E2E walk-through; browser pass at all widths | Pass |
| 4 Breakdown table, exact and estimated flags, markup applied once | `EstimatePanel.test.ts` ("breaks the cost down per token type…", "adds a references line…", "multiplies the references line…"); browser `innerText` matches every row | Pass |
| 5 Exact 9-decimal value on hover, keyboard focus or tap, exposed to AT | `MoneyAmount.test.ts` ("describes the button with the exact 9-decimal value…", "hides the exact value until asked for"); `EstimatePanel.test.ts` ("reveals the exact value of every amount it shows"); browser: keyboard reveal (see Visual verification) | Pass (tap not exercised) |
| 6 Pricing copy, no ceiling wording | `EstimatePanel.test.ts` ("always shows the pricing copy", "never presents the max as a ceiling"); browser `innerText` of the panel contains no "at most", "guaranteed" or "never more than" | Pass |
| 7 Prompt counter in code points, `👍🏽` = 2, no `maxlength`, over-limit error | `PromptField.test.ts` (counts code points, accepts exactly 4,000, "shorten by 1" at 4,001, over-limit error, "never limits the textarea length", describedby); browser over-limit state | Pass |
| 8 Size and quality groups, keyboard operable with visible labels | `RadioGroup.test.ts`; E2E arrow-key step | Pass |
| 9 Reference rows: add and remove, cap of 4, +765 tokens each, blank rows ignored, invalid URL still counted, http(s) hint | `ReferenceImagesField.test.ts` (adds, removes, caps at 4, hint after blur, no flash while typing); `useEstimate.test.ts` (ignores blank rows, counts an invalid row, per-reference figure); `referenceImages.test.ts`; browser cap and invalid-URL states | Pass |
| 10 "We couldn't calculate an estimate right now." on malformed pricing; nothing throws | `EstimatePanel.test.ts` ("shows a message instead of numbers when the estimate is unavailable"); `useEstimate.test.ts` (unavailable state) | Pass |
| 11 Two columns on desktop, panel in view; single column on mobile; no horizontal scroll | E2E `generate.spec.ts` (both projects, no horizontal scroll); browser pass: two columns at 1440 and sticky (see Visual verification); **375: breakdown overflows the card by 6.66px** | **Fail** (see Failing item 1) |
| 12 `lib/money.ts` formatting: 4 decimals, half away from zero, `<$0.0001`, 9 decimals, negatives | `money.test.ts` (46 cases, every AC12 row and plan boundary) | Pass |
| 13 `lib/money.ts` throws on non-integer, NaN, Infinity, out-of-range; no other formatting | `money.test.ts` (guards); `moneyFormatting.test.ts` (7 tests run the project's real ESLint config; `toFixed` in a `.ts` file is rejected) | Pass |

## Edge cases probed

- Prompt over the limit: `4,012 / 4,000` in red, error text "Shorten your prompt by 12.", and the estimate keeps updating (`$0.3530 – $0.4643`).
- Four references (cap): add button disabled, "Up to 4 reference images." shown, headline `$0.3764 – $0.4858 typically $0.4311`.
- Invalid URL on a reference row: hint shown after blur, headline unchanged at the cap figures (still counted).
- Emoji counting, 4,000 and 4,001 boundaries, blank rows, malformed pricing: unit and integration tests above.
- Guest access: redirect to sign-in (`test_a_guest_is_sent_to_sign_in`).

## Visual verification

Browser: Chromium via Playwright, the `desktop` device profile with the viewport set to 375, 768 and 1440 (the mobile project's Pixel 7 device was not used). Signed in once as the demo user, then `goto /dashboard` per width. The temporary spec was deleted; its copy and all screenshots are in scratch.

Screenshots: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-005/shots/` (full pages: `defaults-{w}.png`, `ac3-{w}.png`, `ac3-panel-{w}.png`, `ac3-exact-focus-{w}.png`, `over-limit-{w}.png`, `cap-{w}.png`, `invalid-url-{w}.png`; `measurements.json`).

| Check | 375 | 768 | 1440 |
| --- | --- | --- | --- |
| Defaults headline | `$0.0581 – $0.0863 typically $0.0722` | same | same |
| AC3 headline (High, Landscape, 1 ref) | `$0.3477 – $0.4571 typically $0.4024` | same | same |
| Layout | single column, panel after form | single column, panel after form | two columns: form x 232–792, panel x 824–1208 |
| Document horizontal overflow | 0px | 0px | 0px |
| Breakdown past card edge | **6.66px** (table right 365.66, card inner 359) | 0px | 0px |
| Over-limit counter and error | `4,012 / 4,000` red, error shown | same | same |
| Over-limit headline | `$0.3530 – $0.4643 typically $0.4086` | same | same |
| Cap at 4: headline, add button, hint | `$0.3764 – $0.4858 typically $0.4311`, disabled, hint shown | same | same |
| Invalid URL: hint and headline | hint shown, headline unchanged | same | same |
| Keyboard to AC3 max (`$0.4571`) | 8 Tab presses; tooltip `$0.457137500`; accessible description matches | same | same |
| Keyboard to cap max (`$0.4858`) | 12 Tab presses; tooltip `$0.485825000`; accessible description matches | same | same |
| XHR or fetch while editing | 0 | 0 | 0 |
| Console or page errors | 0 | 0 | 0 |
| Sticky panel after scroll to bottom | not sticky (stacked); page scrolls with it | not sticky (stacked) | page taller than viewport; panel top 60px, bottom 868px of 900px |

Cross-check of the cap figures against the story's arithmetic: 447,575,000 + 4 × 9,562,500 = 485,825,000 (max, `$0.4858`); 338,175,000 + 38,250,000 = 376,425,000 (min, `$0.3764`); 392,875,000 + 38,250,000 = 431,125,000 (typical, `$0.4311`). All match the screen.

What I looked at:
- **1440 AC3:** two columns, the panel beside the form, the estimate headline and the breakdown matching the AC4 table (`<$0.0001 – $0.0001`, `$0.0077`, `$0.2580`, `$0.0125 – $0.1000`, `$0.2782 – $0.3657`, `$0.0695 – $0.0914`, `$0.3477 – $0.4571`). The amounts are right-aligned in their column. The exact-value tooltip sits above the focused amount. Tokens wrap (see Non-blocking).
- **Error states (1440, 375):** the textarea border and counter go red and the error sits under the field with an icon. The estimate stays on screen.
- **Cap and invalid URL (1440, 768, 375):** the disabled add button and the hint read as the mockup shows. The invalid row is red with its message beneath, and the figures do not change.
- **375 AC3 and cap:** the form and panel stack with no page scroll. The breakdown runs past the card border on the right (Failing item 1).

## Cleanup

- The browser pass created no rows; it only signs in as the demo user and does not submit.
- `make e2e` signed up two users (`e2e-desktop-0-0-…`, `e2e-mobile-7-0-…`, IDs 56 and 57). I deleted those two. User count is back to the pre-run 47, and the demo user is still present.
- `tests/e2e/auth.spec.ts` never deletes its sign-up users, so 46 older `e2e-*@promptedit.test` rows from earlier runs today remain. They are not from this step, so they were left alone.
- The temporary spec was removed from the repo. `test-results/qa/` was removed. `git status` is clean apart from this file.
