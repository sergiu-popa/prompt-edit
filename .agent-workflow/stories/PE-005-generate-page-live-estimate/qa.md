# QA — PE-005-generate-page-live-estimate

**Verdict: QA FAILED**

Run on branch `pe-005-generate-page-live-estimate` at `94bbb0b` (the QA-fix round). Prerequisites checked: story status `qa-ready`, review verdict `APPROVE`. Status stays `qa-ready`.

## Failing items

1. **AC5 tap: the exact value closes on the tap that opens it (touch, Chromium emulation).** Tapping the `$0.4571` amount in the AC3 state (Playwright `mobile` project, Pixel 7 device, `hasTouch`, 412px) opens the tip, then closes it about 14ms later.
   - Trace (`performance.now()` ms after the tap starts): `pointerdown@43, touchstart@43, touchend@43, mouseover@43, mouseenter@43, mousedown@44, focus@44, mouseup@44, click@44, mouseout@58, mouseleave@58`. The tooltip is shown after `click` and hidden by `mouseleave`.
   - Control: plain buttons ("Add reference image") and labels ("Low") get the same `mouseout` and `mouseleave` 12–16ms after a tap, with no user action. The emulator emits them, and `MoneyAmount.vue` closes the tip on `mouseleave`.
   - Measured: `toBeVisible()` passed once, then the tip's bounding box was `null` in the same test. A later check read `isVisible() === false` 300ms after the tap. The screenshot taken after the tap shows no tooltip (`recheck-shots/tap-revealed.png`).
   - Tap elsewhere closes the tip correctly (`toBeHidden` passed), and the button's accessible description is `$0.457137500`, so the value is exposed.
   - Real-device behaviour was not tested; this is the browser the pass drives.
   - Fix: stop relying on `mouseenter`/`mouseleave` for touch input. Either check `pointerType === 'mouse'` before hover handling, or open on `click` and close on outside `pointerdown`, `blur` or Escape. Then add a `hasTouch` assertion to `generate.spec.ts` that the tip stays visible after `tap()` and hides after tapping elsewhere. This is the review's open touch-close suggestion, now a required fix.

## Fixed since the last run (re-verified)

- **AC11 at 375px (previous failing item 1): fixed.** The breakdown's table right edge is at 342px and the card's content edge at 359px at 375, and no amount crosses the card (`outsideAmounts: []` at 375, 768 and 1440, across 22 amount elements). The stacked layout is applied at 375.
- **Token counts on one line (previous non-blocking item): fixed.** Each count is one line at 375, 768 and 1440 (`≈ 8–12`, `765 × 1 = 765`, `6,450`, `1,000–8,000`). `High · 1536×1024` is a secondary line by design. At 1440 the panel is 384px wide and the label, token and cost sit on one row.

## Non-blocking

- Review suggestions still open (`EstimateBreakdown` hard-coded flags, `25%` copy, live-region scope, `ReferenceImagesField` count and keys). None is a QA failure.
- 768px is a single column (the panel sits after the form). Breakpoint is `lg` (1024px). The story says "desktop shows two columns" without a number, so this is treated as correct.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| PHP lint, Larastan, PHPUnit | `make check`, plus `make lint`, `make stan`, `make test` individually | Exit 0. Pint 87 files PASS, Larastan clean, PHPUnit 415 tests, 1,592 assertions, all passed. |
| JS lint, Prettier, vue-tsc | `make js-check` (in `make check`) | Clean. |
| Vitest | `make js-test` (in `make check`) | 21 files, 262 tests, all passed. |
| E2E, both projects | `make e2e` | 14 passed (7 desktop, 7 mobile). Includes `generate.spec.ts` in both. Users created by the run (IDs 64, 65) were deleted. |
| Browser pass 375/768/1440 | Temporary `tests/e2e/qa-shots.spec.ts` (deleted; copy in scratch) | 1 passed. No console or page errors (fixture guard), no horizontal scroll (0px at all widths), no XHR or fetch while editing. |
| Token lines and amount fit | Temporary `tests/e2e/qa-tokens.spec.ts` (deleted; copy in scratch) | 1 passed at all widths. Measurements in `recheck-shots/token-lines.json`. |
| Touch tap | Temporary `tests/e2e/qa-tap.spec.ts` (deleted; copies in scratch) | Failed. See Failing item 1. |

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 Generate page is the signed-in home; guests to `/login`; props and TS types | `GenerationFormPageTest` (guest redirect, signed-in home, props exactly `auth`, `errors`, `pricing`, `limits`, limits 4,000 and 4); E2E redirect to `/dashboard` | Pass |
| 2 Defaults `$0.0581 – $0.0863`, typically `$0.0722`, "0 tokens. Start typing your prompt." | `EstimatePanel.test.ts`; E2E; browser pass at 375, 768, 1440 | Pass |
| 3 Live figures for Medium, High, Landscape, one reference; no request | `useEstimate.test.ts`; E2E walk-through (no XHR/fetch); browser pass at all widths | Pass |
| 4 Breakdown table, exact and estimated flags, markup applied once | `EstimatePanel.test.ts` breakdown tests; browser `innerText` matches every row at all widths | Pass |
| 5 Exact 9-decimal value on hover, keyboard focus or tap; exposed to AT | `MoneyAmount.test.ts`; `EstimatePanel.test.ts`; browser keyboard reveal at all widths (8 Tab presses to AC3 max, 12 to cap max); E2E hover/focus | Pass for hover and keyboard. **Fail for tap** (Failing item 1) |
| 6 Pricing copy, no ceiling wording | `EstimatePanel.test.ts` ("always shows the pricing copy", "never presents the max as a ceiling"); browser `innerText` has no "at most", "guaranteed" or "never more than" | Pass |
| 7 Prompt counter in code points, `👍🏽` = 2, no `maxlength`, over-limit error | `PromptField.test.ts`; browser over-limit state at all widths | Pass |
| 8 Size and quality groups, keyboard operable, visible labels | `RadioGroup.test.ts`; E2E arrow-key step | Pass |
| 9 Reference rows: add/remove, cap 4, +765 each, blanks ignored, invalid URL still counted, http(s) hint | `ReferenceImagesField.test.ts`; `useEstimate.test.ts`; `referenceImages.test.ts`; browser cap and invalid-URL states | Pass |
| 10 Unavailable message on malformed pricing; nothing throws | `EstimatePanel.test.ts`; `useEstimate.test.ts` | Pass |
| 11 Two columns on desktop; single column on mobile; no horizontal scroll | E2E both projects; browser: two columns at 1440, stacked at 375 and 768, 0px overflow everywhere, breakdown inside card at 375 | Pass (fixed since last run) |
| 12 `lib/money.ts` formatting | `money.test.ts` (46 cases) | Pass |
| 13 `lib/money.ts` guards; no other formatting | `money.test.ts`; `moneyFormatting.test.ts` (7 tests run the project's real ESLint config) | Pass |

## Edge cases probed

- Prompt over the limit (4,012): `4,012 / 4,000` in red, error text, estimate keeps updating (`$0.3530 – $0.4643`).
- Four references (cap): add button disabled, hint shown, headline `$0.3764 – $0.4858 typically $0.4311`; 4,000 and 4,001 boundaries covered by unit tests.
- Invalid URL on a reference row: hint after blur, headline unchanged (still counted).
- Touch: tap reveal fails (Failing item 1); tap elsewhere dismisses.
- Guest access: redirect to sign-in (`test_a_guest_is_sent_to_sign_in`).

## Visual verification

Browser: Chromium via Playwright. Desktop profile at 375, 768 and 1440 for the browser pass; `mobile` (Pixel 7, `hasTouch`) for the tap checks. Signed in as the demo user, then `goto /dashboard` per width. Temporary specs were deleted from the repo.

Screenshots and measurements (not committed): `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-005/recheck-shots/` (`defaults-{w}.png`, `ac3-{w}.png`, `ac3-panel-{w}.png`, `ac3-exact-focus-{w}.png`, `over-limit-{w}.png`, `cap-{w}.png`, `invalid-url-{w}.png`, `tap-after-tap.png`, `tap-revealed.png`; `measurements.json`, `token-lines.json`).

| Check | 375 | 768 | 1440 |
| --- | --- | --- | --- |
| Defaults headline | `$0.0581 – $0.0863 typically $0.0722` | same | same |
| AC3 headline | `$0.3477 – $0.4571 typically $0.4024` | same | same |
| Layout | single column, panel after form | single column | two columns: form x 232–792, panel x 824–1208 |
| Document horizontal overflow | 0px | 0px | 0px |
| Breakdown table right / card content right | 342 / 359 (inside) | 670 table, inside | inside |
| Amounts outside card | none of 22 | none of 22 | none of 22 |
| Token lines (AC3 state) | one line each | one line each | one line each |
| Sticky panel after scroll to bottom | not sticky (stacked); scrolls with page | not sticky (stacked) | sticky: panel y 96, bottom 868 of 900 |
| Over-limit counter and headline | `4,012 / 4,000` red; `$0.3530 – $0.4643` | same | same |
| Cap headline and add button | `$0.3764 – $0.4858`, disabled | same | same |
| Keyboard to AC3 max | 8 Tab presses; tooltip `$0.457137500` | same | same |
| Keyboard to cap max | 12 Tab presses; tooltip `$0.485825000` | same | same |
| Tap to AC3 max (`mobile` project) | tip opens then closes ~14ms later | — | — |
| XHR or fetch while editing | 0 | 0 | 0 |
| Console or page errors | 0 | 0 | 0 |

What I looked at:
- **375 AC3 (`ac3-375.png`, `ac3-panel-375.png`):** the form stacks above the card. Every amount is inside the card, with costs right-aligned under each token count. The reference row shows the `×` and `+$0.0096 each` hint.
- **1440 defaults and AC3 (`defaults-1440.png`, `ac3-panel-1440.png`):** the panel sits beside the form. Each token count is on one line with its cost right-aligned. `High · 1536×1024` is a secondary line.
- **375 over-limit (`over-limit-375.png`):** the textarea and counter go red, with the error under the field. The estimate stays on screen.
- **Tap (`tap-revealed.png`):** taken after the tooltip had closed. The estimate headline is visible with no tooltip.

## Cleanup

- The browser pass and tap checks sign in only; they create no rows.
- `make e2e` created two sign-up users (IDs 64 and 65, `e2e-desktop-0-0-…` and `e2e-mobile-7-0-…`). Both were deleted by ID.
- User count: 53 before the run, 53 after cleanup. The demo user is present.
- Temporary specs (`qa-shots`, `qa-tokens`, `qa-tap`) were removed from the repo. `test-results/` was removed. `git status` shows only this file.
