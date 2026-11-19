# Audit — PE-003-sign-in-and-app-shell

**Verdict: PASS**

Run on branch `pe-003-sign-in-and-app-shell` from `8ac9648` (QA recorded). Prerequisites checked: story status `qa-passed`, `review.md` (APPROVE, round 1) and `qa.md` (QA PASSED) present.

## Scope

The whole `[PE-003]` diff (`git diff main...HEAD`) was checked against `story.md` AC1–19, `plan.md`, review round 1, the QA notes and `.agent-workflow/CLAUDE.md`. That covers the Fortify config and provider, `CreateNewUser`, `HandleInertiaRequests` and `AuthenticatedUserResource`, the routes, the seeders, CI and README, the Vue primitives, layouts and pages, and every PHPUnit, Vitest and Playwright file they touch. The installed Fortify v1.43 source was read for each claim below. Where the auditor checklist mentions Livewire, the Inertia/Vue rules apply instead.

## What was verified

- **Session fixation (AC5).** The explicit pipeline ends with `PrepareAuthenticatedSession`, which calls `session()->regenerate()` and then clears the limiter.
  - `SignInTest` sends a known 40-character id as the encrypted session cookie and asserts that it changes.
  - The control row (a failed sign-in keeps the id) proves that the test can fail.
- **Sign-out (AC10).** Fortify's `destroy()` logs out, then calls `invalidate()` and `regenerateToken()`.
  - `SignOutTest` asserts the new token is 40 characters and differs from the old one. Without `regenerateToken()`, `invalidate()` leaves no token, so the length assertion would fail.
  - GET `/logout` answers 405 and the user stays signed in.
- **Throttle mode and key (AC6, D-17).**
  - `fortify.limiters.login = null`, so `POST /login` gets no `throttle:` middleware (`routes/routes.php:42-46`). The explicit pipeline always includes `EnsureLoginIsNotThrottled`.
  - `LoginRateLimiter` keys on `transliterate(lower(email)) . '|' . ip`. Only `AttemptToAuthenticate::throwFailedAuthenticationException` increments it, and `PrepareAuthenticatedSession` clears it, so only failures count.
  - `LoginRequest` validation runs before the pipeline, so empty fields never count (`SignInTest` asserts no `Attempting` event and zero hits).
  - `LoginThrottleTest` pins the 59 s / 60 s boundary, other email, other IP, case-insensitive buckets, clear-on-success and 10 sign-in/sign-out cycles. The lockout is sent with Inertia headers and comes back as a redirect, never a 429.
- **Account enumeration (AC3).**
  - A wrong password and an unknown email both go through `trans('auth.failed')` on `email` alone, and `SignInTest` asserts the error bag keys.
  - The lockout applies to unknown emails too, so it reveals nothing.
  - Laravel 13's `SessionGuard` attempts inside a `Timebox`, which evens out the response time. Registration necessarily reveals that an account exists (plan Risks).
- **Case-insensitive uniqueness on Postgres (AC8, AC9, D-18).** The test suite runs on Postgres (`phpunit.xml`: `pgsql`), where `=` is case-sensitive, so the case-only duplicate row is a real check.
  - `CreateNewUser` lowercases a string email before `Rule::unique`.
  - Sign-in lowercases in `CanonicalizeUsername`.
  - The seeder's email is a lowercase constant.
- **Mass assignment.** `User` is `#[Fillable(['name', 'email', 'password'])]`. `CreateNewUser` passes only the three validated keys, and the seeder sets only name and password. Nothing in this story can set `email_verified_at` or `remember_token` from input.
- **`auth.user` (AC14).** `AuthenticatedUserResource::toArray()` returns exactly `id`, `name`, `email` and is shared through `->resolve($request)`, so it is not wrapped in `data`. `SharedAuthUserPropTest` uses closure-scoped `has()` with no `etc()`, so an extra key fails. `types/auth.ts` matches the resource.
- **Fortify routes (AC12, AC13).**
  - The route inventory test pins exactly eight Fortify routes.
  - 25 disabled-feature routes, including every passkey route, answer 404 for a guest and for a signed-in user.
  - Each remaining route is tested for a guest and a signed-in user. GET `/user/confirm-password` is 404 through the view callback.
  - A scratch probe (not committed) found no 500 from crafted array values on any Fortify route: `email[]` and `password[]` on `/login` and `/register`, `remember[]`, and `password[]` on `/user/confirm-password`. It did find a non-view 500 from invalid UTF-8 (#9).
- **Accessibility (AC17).**
  - `Field` derives the hint and error ids and exposes `{ id, describedBy, invalid }`.
  - Every page binds `aria-describedby` and `invalid`, and `TextInput` sets `aria-invalid="true"` only when invalid.
  - The integration suites follow `aria-describedby` to the text, and E2E checks `toHaveAccessibleDescription`.
  - A Playwright probe showed that a clicked submit dropped focus to `<body>` after a server error (#4, fixed).
- **Vue layer.**
  - A grep of `resources/js`, `tests/js` and `tests/e2e` finds no `any` type, only Vitest's `expect.any(Object)` matcher.
  - Nothing in `components/ui/` imports from `@inertiajs`.
  - Pages hold only their `useForm`, a guarded `submit()` and markup, and URLs come from `lib/paths.ts`.
  - `vue-tsc --noEmit` passes.

## Issues

| # | Issue | Location | Severity | Status | Resolution |
| --- | --- | --- | --- | --- | --- |
| 1 | The PR is about 2,240 changed lines against the ≈400-line budget (carried from review round 1 and QA). | `.agent-workflow/CLAUDE.md` § Git hygiene | Warning | Deferred | This is a process decision, not a code defect. Splitting now would rewrite history, which the pipeline forbids. The user decides before opening the PR: one PR, or sequential (not stacked) PRs split at `c3d5821`. The PR description must state the size, the choice and the new `laravel/fortify` dependency (D-02). This audit adds about 90 lines. |
| 2 | `11c0568` fails `make stan` on its own, and `037b4fa` is its fix-up (carried from review round 1). | `tests/Feature/Auth/SignOutTest.php` | Warning | Deferred | HEAD passes. Squashing needs a history rewrite, which the pipeline forbids, so the user decides whether to squash `037b4fa` into `11c0568` before merge. |
| 3 | **Sign out** had no in-flight guard (review suggestion). Sign-out regenerates the CSRF token, so a second POST that reached the server late would answer 419. | `resources/js/layouts/AppLayout.vue:16-26` | Suggestion | Fixed | An `isSigningOut` flag is set before the POST and cleared in `onFinish`. The button shows "Signing out…", is disabled and sends one request. A new `AppLayout.test.ts` row covers it (`1035616`). |
| 4 | After a failed sign-in or sign-up, a clicked (or Enter-on-button) submit left focus on `<body>`: the busy button goes disabled, so the browser drops its focus. Nothing announced the server's messages, so keyboard users had to Tab from the top and screen-reader users got no feedback. Found with a Playwright probe in both projects. | `resources/js/pages/Auth/Login.vue:17-23`, `resources/js/pages/Auth/Register.vue:17-23` | Warning | Fixed | New `composables/useFocusFirstInvalidField.ts`, called from each form's `onError`, focuses the first `aria-invalid` field after the re-render, so its message is read through `aria-describedby`. Covered by 3 rows in `Login.test.ts`, 1 in `Register.test.ts`, and a `toBeFocused()` assertion in the E2E wrong-credentials test (`6ea60e7`). |
| 5 | The keyboard E2E reached **Sign out** with `focus()`, which proved Enter works but not that Tab reaches it (review suggestion). | `tests/e2e/auth.spec.ts:69-74` | Suggestion | Fixed | It now focuses the brand link, presses Tab and asserts that Sign out is focused (`40bd015`). |
| 6 | `fn () => abort(404)` was the only closure in the provider without a return type (review suggestion). | `app/Providers/FortifyServiceProvider.php:41` | Suggestion | Fixed | Changed to `fn (): never => abort(404)` (`0e74aa7`). |
| 7 | The data-provider parameter `array $emptyFields` had no PHPDoc type (review suggestion). | `tests/Feature/Auth/SignInTest.php:74` | Suggestion | Fixed | Added `@param list<string> $emptyFields` (`3ac20db`). |
| 8 | POST `/user/confirm-password` is not throttled. Someone holding a stolen session could guess the user's password there without limit. | Fortify `routes/routes.php:128` (always registered) | Suggestion | Deferred | This is Fortify's default, and it needs a hijacked session first, which already gives full access. No page uses password confirmation. PE-015's abuse-limits item (AC15) should add a throttle on it or remove the route. |
| 9 | A crafted `POST /register` whose name is invalid UTF-8 (`"A\xffda"`) answers 500 (Postgres `SQLSTATE[22021]`). Emails are not affected, because `Str::lower` replaces the bad bytes. Browsers always send valid UTF-8. | `app/Actions/Fortify/CreateNewUser.php:35-39` | Suggestion | Deferred | It exposes no data, and with `APP_DEBUG=false` it renders the generic 500 page, which is PE-015 AC11. Rejecting malformed UTF-8 is server input validation that PE-011 owns for prompts (PE-002 audit #4). It should cover names in the same rule. |
| 10 | The throttle key uses `$request->ip()`. Behind Laravel Cloud's proxy, without trusted proxies, every client gets the proxy's IP. The bucket then becomes per email, so anyone could lock any account out for a minute. | `bootstrap/app.php` | Suggestion | Deferred | Not reachable locally or in CI. PE-004 configures trusted proxies (its Technical Notes). It should also assert that `$request->ip()` is the client IP from `X-Forwarded-For`, so the D-17 key stays email + client IP. |
| 11 | Lowercase emails rely on every write path lowercasing, because the `users.email` unique index is case-sensitive on Postgres. | `database/migrations/0001_01_01_000000_create_users_table.php:17` | Suggestion | Deferred | Both entry points in this story lowercase, and the seeder uses a lowercase constant. PE-004's account command must lowercase the same way (plan Risks). A `lower(email)` unique index would add a migration, which is outside this story's scope. |
| 12 | The backend accepts `remember=1` on POST `/login`, although "remember me" is out of scope. | Fortify `AttemptToAuthenticate::handle` | Suggestion | Skipped | It can only opt the requester's own browser into a remember cookie, and sign-out cycles the token. Blocking it would need a custom pipeline step. |
| 13 | While a request is in flight, the previous server errors stay visible (QA note). | `resources/js/pages/Auth/Login.vue`, `Register.vue` | Suggestion | Skipped | Inertia replaces the errors when the response arrives, and the busy button shows the request is in flight. Clearing them on start would make the form jump twice per submit. |
| 14 | At 1 second left, the lockout reads "Please try again in 1 seconds." | Laravel `lang/en/auth.php` `throttle` | Suggestion | Skipped | The AC fixes the message as "…in N seconds.", and it shows for one second at most. |

No Critical issues were found.

## Checks after fixes

| Check | Command | Result |
| --- | --- | --- |
| Pint, Larastan, PHPUnit, ESLint, Prettier, vue-tsc, Vitest | `make check` | Pint passes on 76 files, Larastan reports no errors, PHPUnit 362 passed (1,331 assertions), Vitest 107 passed in 9 files (102 before, plus 5 new rows) |
| Playwright, desktop and mobile | `make seed`, then `make e2e` | 12 passed |
| Malformed-input probe | Scratch `AuditProbeTest`, 15 rows, deleted after the run | One 500 (#9), every other row a redirect with field errors |
| Focus probe | Scratch Playwright spec, deleted after the run | Before #4: `<body>` focused after a clicked failed sign-in, in both projects. After: the E2E `toBeFocused()` assertion passes |

Neither probe file is in the repository.
