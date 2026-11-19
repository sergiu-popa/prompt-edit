# QA — PE-003-sign-in-and-app-shell

**Verdict: QA PASSED**

Run on branch `pe-003-sign-in-and-app-shell` at `9599f8d`. Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 1).

## Tests

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, JS lint/format/types, Vitest | `make check` | Exit 0. Pint clean on 76 files, Larastan no errors, PHPUnit 362 passed (1,331 assertions), Vitest 102 passed in 9 files. |
| JS lint/format/types | `make js-check` | Included in `make check`: ESLint, Prettier and vue-tsc clean. |
| Vitest unit and integration | `make js-test` | Included in `make check`: 102 passed. |
| Existing E2E (desktop + mobile) | `make e2e` | 12 passed (6 per project), after `npm run build`. |
| Temporary QA browser pass | Playwright, `desktop` project, 375 / 768 / 1440 | 5 passed. Spec deleted afterwards, not committed. |

Logs: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-003/make-check.log`, `.../PE-003/make-e2e.log`, `.../PE-003/qa-shots.log`.

## Acceptance criteria

All 19 criteria are covered by a passing test. Browser-only claims were also driven in the browser pass.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 guest redirect to `/login`, intended URL kept | `SignInTest` (`/dashboard?from=test`), `FortifyRoutesTest`; E2E demo sign-in; browser: `/dashboard` as guest is redirected to `/login` after sign-out | Pass |
| 2 valid sign-in lands on app shell (name, Sign out) | `SignInTest`; E2E demo and sign-up; browser: `dashboard-demo-*` shows "Demo User" and Sign out | Pass |
| 3 wrong password and unknown email: same message under Email, email kept, password cleared, still guest | `SignInTest`; E2E wrong-credentials; browser: `login-wrong-credentials-*` (message under Email, `aria-invalid`, password `""`) | Pass |
| 4 empty fields: field message under each, no attempt, no throttle hit | `SignInTest` (`Attempting` event and limiter hit absent); browser: `login-empty-errors-*` | Pass |
| 5 session id regenerated on sign-in | `SignInTest` (AC5 test with a control row) | Pass |
| 6 throttle: 5 failures then refused; inline message; recovery; other email unaffected; successes never count | `LoginThrottleTest` (59 s / 60 s boundary, other email, other IP, case bucket, 10 sign-in/out cycles); `Login.test.ts`; browser: 6th attempt shows "Too many login attempts. Please try again in 58 seconds." under Email, no 429 page | Pass |
| 7 registration creates account, signs in, lands on home | `RegistrationTest`; E2E sign-up; browser: long-name sign-up reaches `/dashboard` | Pass |
| 8 registration field messages, no account, non-password inputs kept | `RegistrationTest` (data provider with exact messages, including the case-only duplicate); `Register.test.ts`; browser: `register-field-errors-*` and `register-mismatch-*` | Pass |
| 9 lowercase emails stored, sign-in case-insensitive | `SignInTest` (`ADA@example.com`), `RegistrationTest` (`Ada@Example.COM` stored lowercase); DB: stored sign-up email is lowercase | Pass |
| 10 POST `/logout` ends session, changes CSRF, lands on Welcome; GET `/logout` does not sign out | `SignOutTest` (GET answers 405, stays signed in); E2E sign-out; browser: sign-out lands on `/`, `/dashboard` then redirects to `/login` | Pass |
| 11 signed-in user on `/login` or `/register` redirected to home | `FortifyRoutesTest`; browser: `login-when-signed-in-*` and `register-when-signed-in-*` both end on `/dashboard` | Pass |
| 12 unused Fortify routes return 404 | `FortifyRoutesTest` (25 disabled-feature rows, guest and signed-in); browser: `/forgot-password`, `/reset-password/x`, `/email/verify`, `/two-factor-challenge`, `/user/profile-information`, `/user/password` all return 404 (curl from the app container); `route:list` shows only the Fortify routes in the plan | Pass |
| 13 no Fortify route answers 500 | `FortifyRoutesTest` (remaining routes per guest and signed-in, exact answers) | Pass |
| 14 `auth.user` null for guests, exactly `{id, name, email}` for signed-in | `SharedAuthUserPropTest` (closure-scoped `has()`, no `etc()`); `types/auth.ts` with vue-tsc passing | Pass |
| 15 Welcome shows Sign in and Create account to guests, one Open PromptEdit link when signed in | `WelcomePageTest`, `Welcome.test.ts`; browser: `welcome-guest-*` (two CTAs), `welcome-signed-in-*` (one link, 0 sign-in/create links) | Pass |
| 16 busy label, disabled button, no second request while in flight | `Login.test.ts`, `Register.test.ts`; browser: `login-busy-*` ("Signing in…", `disabled`, `aria-busy="true"`), `register-busy-*` ("Creating account…"). Enter pressed while in flight sent no second POST (login 1 POST, register 1 POST) | Pass |
| 17 visible labels, `aria-invalid` plus `aria-describedby`, keyboard reachable | Primitive suites (`Field.test.ts`, `TextInput.test.ts`); E2E keyboard-only demo sign-in with Enter on Sign out; browser: `describedBy` resolves to the message text on every error state | Pass |
| 18 no horizontal scroll on Login, Register and home (Pixel 7); Sign out reachable | E2E AC18 test in both projects; browser at 375 / 768 / 1440 on every screen: overflow 0 px. Sign out inside the viewport at every width (see table) | Pass |
| 19 `make seed` creates demo user, idempotent, not for production | `DemoUserSeederTest`; browser pass: `make seed` run twice, `demo@promptedit.test` count stays 1; E2E uses the seeded user | Pass |

## Edge cases probed

- Mixed-case sign-in and registration: covered by feature tests. The browser pass used lowercase addresses only.
- Registration with the demo address in upper case: covered by `RegistrationTest` (case-only duplicate). Not driven in the browser.
- Throttle recovery after 60 s and the other-email case: covered by `LoginThrottleTest` under time travel. The browser pass did not wait out the window.
- Long single-word name (60 characters): the header truncates it with an ellipsis at 375 / 768 / 1440, with no overflow.
- Busy state with Enter: pressing Enter in the last input while a request is in flight does not send a second request.
- Stale errors during a busy request: see Notes. Not a failure.

## Visual verification

Driven with a temporary Playwright spec (`desktop` project, viewport set per width, fonts stubbed as in the existing fixture). Every screen was checked at 375, 768 and 1440, as guest or signed in as the story requires. Screenshots: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-003/qa-shots/` (41 PNGs, full-page, plus `log.jsonl` with every measurement). Not committed.

| Width | Screens | scroll/client | Console or page errors | Notes |
| --- | --- | --- | --- | --- |
| 375 | welcome guest and signed in, login (default, empty, wrong, throttled, busy), register (default, empty, field errors, mismatch, busy), dashboard (demo and long name) | 375/375 on every screen | none | Sign out at x 274.7 + 84.3 = 359 (inside 375). Header with the long name: 89 px high, two rows |
| 768 | same set | 768/768 on every screen | none | Sign out at x 659.7 + 84.3 = 744 (inside 768) |
| 1440 | same set | 1440/1440 on every screen | none | Sign out at x 1123.7 + 84.3 = 1208 (inside 1440). Auth card centred, width unchanged |

Totals: 56 measurement records, 0 with horizontal overflow, 0 with console errors.

Visual checks on the screenshots (375 unless stated, 1440 and 768 for comparison):
- Login default: centred card, labels above inputs, full-width dark Sign in button, "No account yet? Create one" footer. Matches the mockup.
- Wrong credentials: red border and warning icon on Email, message in red directly under it, password empty, email kept. Matches the mockup.
- Throttled: same placement as the wrong-credentials message, with "try again in 58 seconds". No 429 page.
- Busy login: button greyed with a spinner and "Signing in…", fields unchanged. Matches the mockup.
- Register field errors: Email shows "The email has already been taken."; Password shows its hint "At least 8 characters." and then the minimum-length error. Name and Email kept, both password fields cleared. Matches the mockup.
- Register busy (768): "Creating account…" with a spinner, button disabled.
- Dashboard demo: header with PromptEdit on the left and "Demo User" plus Sign out on the right at 1440 and 768. At 375 the name sits on the second row, as in the mockup. Body "You're signed in." / "Image generation is coming next."
- Dashboard long name (375): the 60-character name is cut with an ellipsis on its own row, Sign out stays top right, no scroll.
- Welcome guest: Sign in (dark) and Create account (outlined) side by side, left-aligned at 375, and wrapping cleanly.
- Welcome signed in: one "Open PromptEdit →" button.

Interactions driven: sign-in and sign-out (click), the empty-submit validation, the throttle on the 6th attempt, the busy state with a delayed POST, and sign-up with a field error. Each produced the state the story describes.

Persistence: the long-name sign-up is stored with the 60-character name and a lowercase email (checked in the `users` table before cleanup). Sign-in after sign-up reaches the same user.

## Edge cases

No failing edge cases.

## Regressions

None. The existing `welcome.spec.ts` passes in both projects, and the `make check` suite passes.

## Cleanup

- Fixture accounts created by the temporary pass: three `qa-long-*` sign-ups (one per width, from the browser pass) and two `e2e-*` sign-ups from my `make e2e` run. All were deleted, with their `sessions` rows.
- `users` count before the pass: 5 (`demo@promptedit.test` plus four `e2e-*` leftovers from earlier runs). After cleanup: 5, with the same rows.
- `make seed` was run twice: `demo@promptedit.test` count stays 1.
- The temporary spec `tests/e2e/qa-shots.spec.ts` was removed from the repo. `test-results/` was removed (gitignored). Nothing created by this pass is in the working tree.
- Note: every `make e2e` run leaves its sign-up accounts in the dev database (existing behaviour, see plan Risks). That is not changed here.

## Verdict

**QA PASSED.** All 19 acceptance criteria pass, the browser pass shows no horizontal scroll and no console errors on any screen at 375 / 768 / 1440, and review round 1 has no Critical items open.

## Notes (non-blocking)

- Stale errors during a busy request: on the login page, the throttle message from a previous email stays visible while a new attempt is in flight (1440 and 375 `login-busy` shots). On the register page, the previous field errors stay visible while the request is in flight (768 `register-busy`). AC16 is met (label, disabled, one request). The UI could clear server errors when a submit starts. This is a suggestion, not a failure.
- Register password errors: when a password is both too short and mismatched, only the first message shows ("at least 8 characters"). The mismatch message appears on its own when the length is valid (`register-mismatch-*`). This matches Laravel's first-error behaviour and is acceptable under AC8.
- Inertia's progress bar shows as a thin dark bar at the top edge during requests. Cosmetic, seen in the busy shots only.
- Carried over from review round 1, for the user: commit `11c0568` fails Larastan on its own (fixed by `037b4fa`), and the branch is about 2,240 changed lines against the ~400-line budget. Both need a decision before the PR is opened. This does not block QA.
