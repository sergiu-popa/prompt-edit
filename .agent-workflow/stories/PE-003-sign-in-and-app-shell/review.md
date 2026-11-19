# Review — PE-003-sign-in-and-app-shell

## Round 1

**Verdict: APPROVE**

Scope reviewed: the `[PE-003]` commits `6accb7d..e8baa20` on `pe-003-sign-in-and-app-shell` (`git diff origin/main...HEAD`), against `story.md` and `plan.md`.

Checks run on HEAD:
- `make check`: Pint passes on 76 files, Larastan reports no errors, and PHPUnit runs 362 tests with 1,331 assertions, all passing.
- `make js-check` and `make js-test`: ESLint, Prettier and vue-tsc are clean. Vitest runs 102 tests in 9 files, all passing.
- `make seed`, then `make e2e`: 12 Playwright tests pass, 6 each in `desktop` and `mobile`.
- `php artisan route:list`: Fortify registers only the 8 routes in the plan's Routes table (login, logout, register, and the three password-confirmation routes). There are no reset, verification, profile, password, two-factor or passkey routes.
- `composer.lock`: the only additions are Fortify and its own dependency tree (`laravel/passkeys`, `web-auth/*`, `spomky-labs/*`, `bacon/bacon-qr-code`, `pragmarx/google2fa`, the `symfony/serializer` family and `phpdocumentor/*`). No package that was already locked changed version.

### Summary

The implementation matches the plan file for file. Where it differs from the story's Technical Notes, it does so for the reasons the plan gave (D-17, D-18).

- **Fortify backend:**
  - `config/fortify.php` sets five keys, each with a one-line reason. `limiters.login = null` keeps the throttle in the pipeline (`EnsureLoginIsNotThrottled`), which counts failures only.
  - `FortifyServiceProvider` sets the login pipeline explicitly (Fortify's default without two-factor), renders the two Inertia views, and answers 404 for the confirm-password view.
  - `CreateNewUser` lowercases a string email before the `unique` rule runs. A crafted `email[]` therefore gets a field error, not a `TypeError` (covered by `RegistrationTest::test_an_email_submitted_as_a_list_is_a_field_error`).
- **AC1–AC6:** `SignInTest` and `LoginThrottleTest` cover every row in the plan:
  - the intended-URL redirect;
  - one identical message for a wrong password and for an unknown email, with the input kept and the password not flashed;
  - empty fields make no `Attempting` event and no limiter hit;
  - the 59 s / 60 s window boundary;
  - other email, other IP, and case-insensitive buckets;
  - a success clears the counter, and 10 sign-in/sign-out cycles in a row all succeed.

  The lockout is sent with Inertia headers and comes back as a redirect with an inline error, never a 429. The session-fixation test has the control row the plan required, so it can fail.
- **AC7–AC9, AC10, AC11–AC13:** `RegistrationTest`, `SignOutTest` and `FortifyRoutesTest`:
  - a data provider with exact messages, including the case-only duplicate;
  - the 255-character and 8-character boundaries, and trimming;
  - the CSRF token compared before and after sign-out;
  - GET `/logout` answers 405 and the user stays signed in;
  - a route inventory that fails if a Fortify upgrade adds a route;
  - 25 disabled-feature routes answer 404, both for a guest and for a signed-in user.
- **AC14–AC15:** `AuthenticatedUserResource` is shared with `->resolve($request)`, so it is not wrapped in `data`. `SharedAuthUserPropTest` asserts exactly `id`, `name`, `email` with closure-scoped `has()`. `types/auth.ts` and the `InertiaConfig` declaration merge type `usePage().props.auth` everywhere, and vue-tsc passes.
- **AC16–AC18 (UI):**
  - The primitives import nothing from Inertia. `Field` derives the hint and error ids and exposes `{ id, describedBy, invalid }` to the input.
  - The pages are thin. Both forms are `novalidate`, return early while `form.processing` is true, and reset the password fields in `onFinish`.
  - The integration suites prove the busy label, the disabled button and one `router.post` on a double submit. They also prove the aria linkage by following `aria-describedby` to the text, the way a screen reader does.
  - E2E covers sign-up with a 60-character unbroken name and no horizontal scroll, the wrong-credentials message through `toHaveAccessibleDescription`, a keyboard-only demo sign-in, and the AC18 rows in both projects.
- **AC19:** `DemoUserSeeder` uses `updateOrCreate` keyed by email and refuses to run in production. `DatabaseSeeder` calls only that seeder. The CI `e2e` job seeds after migrating, and the image's `.env` is copied from `.env.example` (`APP_ENV=local`), so the seeder runs there. The README documents the account and the `make seed` step.
- **Security:**
  - `User` is `#[Fillable(['name', 'email', 'password'])]`, and `CreateNewUser` passes only validated keys.
  - No raw SQL, and no `v-html`.
  - The shared prop carries no hash, token or timestamps.
  - The E2E wrong-password test uses a unique email, so parallel projects can't lock out the demo user.
- **Performance:** `auth.user` is a lazy closure over the already-resolved `$request->user()`, so it adds no query.

### Commit hygiene

The history reads cleanly overall:
- one bookkeeping commit for the plan (`6accb7d`) and one for the checkboxes and status (`e8baa20`);
- one code commit per plan task, each with its tests;
- subjects in `[PE-003] Imperative …` form, at most 72 characters, and every commit carries the trailer.

Three other points:
- The D-17 and D-18 rows in `requirements.md` ride with the code commits that introduce them (`cf630e9`, `8d70465`), as the plan specified and as PE-002 did. They document the code change rather than pipeline bookkeeping, so that is fine.
- `dce2d1a` moves the Login test's label helpers into `support/form.ts` while building Register. The body says why, and the move exists because Register is the second consumer, so it belongs to that change.
- `composer require --no-scripts` left `CLAUDE.md` and `AGENTS.md` untouched, as the plan intended. Running `boost:update` to add any Fortify guidelines is the user's call.

### Warnings

- [x] **`11c0568 [PE-003] Pin sign-out behaviour` does not pass `make stan`. The next commit, `037b4fa [PE-003] Drop a redundant string assertion from the sign-out test`, is a fix-up for it.** The project rule is that each commit passes the suite (`.agent-workflow/CLAUDE.md` § Git hygiene). `037b4fa` removes one line, `$this->assertIsString($tokenAfterSignOut)` in `tests/Feature/Auth/SignOutTest.php`, which Larastan flags as always true. So `11c0568` fails static analysis on its own, and the reviewer reads two commits for one change.
  - Fix: squash `037b4fa` into `11c0568` (`git rebase -i` with `fixup`) before the PR is opened.
  - **Resolved before the PR was opened:** the orchestrator squashed `037b4fa` into `11c0568`, which is now `9792f53 [PE-003] Pin sign-out behaviour`. It passes `make stan` on its own. Later commits got new SHAs, and the SHAs in these notes have been updated to match.

  — acknowledged: no engineering action in this pipeline can fix it, because agents may not rewrite history. HEAD passes `make check`. **The user decides whether to squash before merge.**
- [x] **PR size breaks the project rule.** `.agent-workflow/CLAUDE.md` § Git hygiene sets about 400 changed lines per PR. This branch has about 2,240 changed lines, excluding `composer.lock`:
  - `app/`, `config/`, `routes/`, `resources/js`, `database/`, CI, README and `composer.json`: about 660;
  - `tests/`: about 1,550;
  - `.agent-workflow`: about 30.

  The plan foresaw this and named a cut after `38cd7e6`. That gives PR A, the backend with stub pages (about 1,150 lines), and PR B, the UI and E2E (about 1,080 lines). Each commit is reviewable alone: the largest, `dce2d1a`, is 263 lines.

  — acknowledged: splitting now means rewriting history, which no engineering action in this pipeline can do. **The user must decide this before the PR is opened.** The options are one PR of 18 commits, or sequential (not stacked) PRs split at `38cd7e6`. The PR description must state the size and the choice, and mention the new `laravel/fortify` dependency (D-02).

### Suggestions

These are optional and do not block the verdict.

- [ ] `resources/js/layouts/AppLayout.vue:16-18`: **Sign out** has no in-flight guard, unlike the two forms (AC16).
  - Sign-out invalidates the session and regenerates the CSRF token. A second POST that reaches the server after the first would carry the old token and could answer 419, which Inertia shows as a modal.
  - I couldn't reproduce it locally: a scratch Playwright `dblclick()` sent one request in both projects, because Inertia cancels the first visit. It may still appear on a slow network.
  - Fix: track a `signingOut` ref, set in `onStart` and cleared in `onFinish`. Pass it as `:busy` to the Button (busy label "Signing out…") and return early while it is set, plus one `AppLayout.test.ts` row.
- [ ] `app/Providers/FortifyServiceProvider.php:41`: `fn () => abort(404)` is the only closure in the file without a return type, against the "explicit return types" guideline. Fix: `fn (): never => abort(404)`.
- [ ] `tests/Feature/Auth/SignInTest.php:74-75`: the data-provider parameter `array $emptyFields` has no `@param list<string> $emptyFields`, unlike `RegistrationTest::test_an_invalid_registration_…` (lines 33–41). Fix: add the PHPDoc.
- [ ] `tests/e2e/auth.spec.ts:69-72`: the keyboard test reaches **Sign out** with `signOut.focus()`. That proves Enter activates it, but not that Tab can reach it (AC17's "reachable by keyboard"). Fix: press Tab from the brand link (or from `body`) until the button is focused, and assert `toBeFocused()`.
