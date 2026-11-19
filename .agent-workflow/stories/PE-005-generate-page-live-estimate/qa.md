# QA — PE-005-generate-page-live-estimate

**Verdict: QA PASSED**

Run on branch `pe-005-generate-page-live-estimate` at `759f870` (the QA-fix round after the tap fix). Prerequisites checked: story status `qa-ready`, review verdict `APPROVE`, clean tree. Status moves to `qa-passed`.

## Failing items

None.

## Fixed since the last run (re-verified)

- **AC5 tap (previous failing item 1): fixed and verified in a browser.** On the `mobile` project (Pixel 7, `hasTouch`, `maxTouchPoints: 1`, coarse pointer), a tap on `$0.4571` opens the `$0.457137500` tip. It is still open 600 ms later (box x 148, y 777, 92×24 px). A second tap closes it. A reopen followed by a tap on the heading closes it. The control button's accessible description is `$0.457137500`. Screenshot: `tap-open-600ms.png` (see Visual verification).
- **AC11 at 375px (earlier failure): still holds.** The breakdown table ends at 342 px and the card content edge is at 359 px. No amount crosses the card, and there is 0 px document overflow.
- **Token counts on one line: still holds.** Checked visually at 375, 768 and 1440 (see Visual verification). I did not re-run the numeric token-line script this round.

## Non-blocking

- Review suggestions still open (`EstimateBreakdown` hard-coded flags, `25%` copy, live-region scope, `ReferenceImagesField` count and keys). None is a QA failure.
- 768px is a single column (the panel sits after the form). Breakpoint is `lg` (1024px). The story says "desktop shows two columns" without a number, so this is treated as correct.
- Real-device touch was not tested. The check uses Chromium emulation with `hasTouch`.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| PHP lint, Larastan, PHPUnit | `make check` (lint, stan, test) | Pass. Pint 87 files PASS, Larastan no errors, PHPUnit 415 tests, 1,592 assertions. |
| JS lint, Prettier, vue-tsc | `make js-check` (in `make check`) | Pass. |
| Vitest | `make js-test` (in `make check`) | Pass. 21 files, 279 tests. |
| E2E, both projects | `make e2e` | 15 passed, 1 skipped. The skip is the desktop run of "a tap toggles the exact value", which is mobile-only by design. Users created by the run (IDs 68, 69) were deleted. |
| Browser pass 375/768/1440, both projects | Temporary `tests/e2e/qa-shots.spec.ts` (deleted) | Pass. 0 px horizontal overflow, 0 console or page errors, 0 XHR or fetch while editing. |
| Touch tooltip (mobile project) | Same temporary spec (deleted) | Pass. |
| Mouse hover (both projects) | Same temporary spec (deleted) | Pass. |

## Acceptance criteria

| AC | Covered by | Result |
| --- | --- | --- |
| 1 Generate page is the signed-in home; guests to `/login`; props and TS types | `GenerationFormPageTest` (guest redirect, signed-in home, props exactly `auth`, `errors`, `pricing`, `limits`, limits 4,000 and 4); E2E redirect | Pass |
| 2 Defaults `$0.0581 – $0.0863`, typically `$0.0722`, "0 tokens. Start typing your prompt." | `EstimatePanel.test.ts`; E2E; browser pass at 375, 768, 1440 | Pass |
| 3 Live figures for Medium, High, Landscape, one reference; no request | `useEstimate.test.ts`; E2E walk-through; browser pass at all widths (headline `$0.3477 – $0.4571 typically $0.4024`) | Pass |
| 4 Breakdown table, exact and estimated flags, markup applied once | `EstimatePanel.test.ts` breakdown tests; browser text matches each row | Pass |
| 5 Exact 9-decimal value on hover, keyboard focus or tap; exposed to AT | `MoneyAmount.test.ts` (27 cases); `EstimatePanel.test.ts`; E2E hover, focus and tap; browser checks below | Pass |
| 6 Pricing copy, no ceiling wording | `EstimatePanel.test.ts`; browser text has no "at most", "guaranteed" or "never more than" | Pass |
| 7 Prompt counter in code points, `👍🏽` = 2, no `maxlength`, over-limit error | `PromptField.test.ts`; browser over-limit state at all widths | Pass |
| 8 Size and quality groups, keyboard operable, visible labels | `RadioGroup.test.ts`; E2E arrow-key step | Pass |
| 9 Reference rows: add/remove, cap 4, +765 each, blanks ignored, invalid URL still counted, http(s) hint | `ReferenceImagesField.test.ts`; `useEstimate.test.ts`; `referenceImages.test.ts`; browser cap and invalid-URL states | Pass |
| 10 Unavailable message on malformed pricing; nothing throws | `EstimatePanel.test.ts`; `useEstimate.test.ts` | Pass |
| 11 Two columns on desktop; single column on mobile; no horizontal scroll | E2E both projects; browser measurements at all widths | Pass |
| 12 `lib/money.ts` formatting | `money.test.ts` (46 cases) | Pass |
| 13 `lib/money.ts` guards; no other formatting | `money.test.ts`; `moneyFormatting.test.ts` (7 tests run the project's ESLint config) | Pass |

## Edge cases probed

- Prompt over the limit (4,012): `4,012 / 4,000` in red, error text shown, estimate keeps updating (`$0.3530 – $0.4643`).
- Four references (cap): add button disabled, "Up to 4 reference images." shown, headline `$0.3764 – $0.4858 typically $0.4311`.
- Invalid URL on a reference row: hint shown, headline unchanged (still counted).
- Touch: tap opens, stays open after 600 ms, second tap closes, outside tap closes.
- Mouse hover on the desktop project: reveals `$0.457137500`, hides on leave. Same on the mobile project.
- Keyboard: Tab reaches the AC3 max in 8 presses and the cap max in 12. Each reveals its exact value and exposes it through `aria-describedby`.
- Guest access: redirect to sign-in (`test_a_guest_is_sent_to_sign_in`).

## Visual verification

Browser: Chromium via Playwright, the `desktop` and `mobile` (Pixel 7, `hasTouch`) projects. Signed in as the demo user, then `/dashboard` at 375, 768 and 1440. Temporary specs were deleted from the repo, and `test-results/` was removed.

Screenshots and measurements (not committed): `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-005/qa2/` (`desktop/` and `mobile/`: `defaults-{w}.png`, `ac3-{w}.png`, `ac3-panel-{w}.png`, `ac3-exact-focus-{w}.png`, `over-limit-{w}.png`, `cap-{w}.png`, `invalid-url-{w}.png`, `hover-open.png`, `measurements.json`; `mobile/tap-open-600ms.png`, `mobile/tap-result.json`).

| Check | 375 | 768 | 1440 |
| --- | --- | --- | --- |
| Defaults headline | `$0.0581 – $0.0863 typically $0.0722` | same | same |
| AC3 headline | `$0.3477 – $0.4571 typically $0.4024` | same | same |
| Layout | single column, panel after form | single column | two columns: form x 232–792, panel x 824–1208 |
| Document horizontal overflow | 0px | 0px | 0px |
| Breakdown table right / card content right | 342 / 359 (inside) | 670 / 744 (inside) | 1183 / 1208 (inside) |
| Panel overflow past inner edge | 0px | 0px | 0px |
| Sticky panel after scroll to bottom | not sticky (stacked) | not sticky (stacked) | sticky: panel y 96 |
| Over-limit counter and headline | `4,012 / 4,000` red; `$0.3530 – $0.4643` | same | same |
| Cap headline and add button | `$0.3764 – $0.4858`, add disabled | same | same |
| Keyboard to AC3 max | 8 Tab presses; tooltip `$0.457137500` | same | same |
| Keyboard to cap max | 12 Tab presses; tooltip `$0.485825000` | same | same |
| Tap to AC3 max (`mobile`, Pixel 7) | opens; open after 600 ms; second tap closes; outside tap closes | — | — |
| Hover to AC3 max (1280 desktop) | `$0.457137500` shown; hidden after leave | — | — |
| XHR or fetch while editing | 0 | 0 | 0 |
| Console or page errors | 0 | 0 | 0 |

What I looked at:
- **375 defaults (`defaults-375.png`):** single column, all breakdown rows on one line for each count, with each cost right-aligned under it. The estimate reads `$0.0581 – $0.0863`, typically `$0.0722`.
- **375 AC3 (`ac3-375.png`):** the form stacks above the card. Every amount is inside the card. The reference row shows the `×` and `+$0.0096 each` hint.
- **375 tap (`mobile/tap-open-600ms.png`):** the `$0.457137500` tip is still open 600 ms after the tap, above the amount.
- **1440 AC3 (`ac3-1440.png`):** the panel sits beside the form. Each token count is on one line with its cost right-aligned.
- **1440 keyboard focus (`ac3-exact-focus-1440.png`):** the focused `$0.4571` shows the `$0.457137500` tip.
- **375 over-limit (`over-limit-375.png`):** the textarea and counter go red, with the error under the field. The estimate stays on screen.
- **375 cap (`cap-375.png`):** four rows, the add button disabled, "Up to 4 reference images." shown.
- **375 invalid URL (`invalid-url-375.png`):** the bad row is red with the hint, and the headline is unchanged.
- **Hover (`hover-open.png`):** the mouse hover shows the `$0.457137500` tip.

## Cleanup

- The browser pass and tap checks sign in only; they create no rows.
- `make e2e` created two sign-up users (IDs 68 and 69). Both were deleted by ID.
- User count: 53 before the run, 53 after cleanup. The demo user is present.
- Temporary spec `tests/e2e/qa-shots.spec.ts` removed; `test-results/` removed. Only this file and `story.md` change in the repo.
