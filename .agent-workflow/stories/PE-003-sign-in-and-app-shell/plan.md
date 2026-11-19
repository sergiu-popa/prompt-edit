# Plan — PE-003-sign-in-and-app-shell

## Approach
Fortify v1.43 is the backend, with only `registration` enabled. Its view callbacks render Inertia pages, and an explicit login pipeline keeps the failure-only throttle (AC6) and lowercases the email. `CreateNewUser` lowercases at registration, after checking the email is a string. An `auth` route group holds the `dashboard` signed-in home. The shared `auth.user` prop is shaped by a resource and has a TS type, and a local-only seeder provides the demo user. The UI is three hand-built primitives (Button, Field, TextInput), two layouts and the styled pages, covered by Vitest integration suites and one Playwright spec that runs in both projects.

## Tasks
Each task is one TDD commit: write the failing test first, then the code, and run `make check` green before committing (plus `make e2e` for task 15). Tasks 1–8 are the backend with stub pages, and tasks 9–15 are the UI, so the PR can be cut after task 8 (see Risks).

- [ ] 1. Require Fortify. Inside the container, run `composer require laravel/fortify --no-scripts`, then `composer dump-autoload` (for package discovery), then `php artisan fortify:install`. Delete the published two-factor and passkeys migrations, plus `ResetUserPassword`, `UpdateUserPassword`, `UpdateUserProfileInformation` and `PasswordValidationRules`. Inline the password rules into `CreateNewUser`, and trim the config and provider as described under Backend. Test-first: `FortifyRoutesTest` with the route inventory and the AC12 404 rows. Commit: `[PE-003] Require laravel/fortify`
- [ ] 2. Register the login, register and confirm-password view callbacks, the stub pages `Auth/Login`, `Auth/Register` and `Dashboard`, and the `auth` group with the `dashboard` route. Test-first: the AC13 per-route rows, the AC1 guest redirect and AC11 in `FortifyRoutesTest`. Commit: `[PE-003] Serve the auth pages and signed-in home through Inertia`
- [ ] 3. Add the explicit login pipeline in `FortifyServiceProvider` and the `InteractsWithAuthentication` test trait, test-first in `SignInTest` for AC1–5 and AC9's sign-in half. The mixed-case sign-in is the row that fails first. Commit: `[PE-003] Sign in through an explicit Fortify login pipeline`
- [ ] 4. Pin AC6 in `LoginThrottleTest` with the throttle rows below; no code should change. Record D-17. Commit: `[PE-003] Pin failure-only login throttling`
- [ ] 5. Lowercase string emails in `CreateNewUser` before validation, test-first in `RegistrationTest` for AC7, AC8, AC9's registration half and the registration rows below. Record D-18. Commit: `[PE-003] Register accounts with lowercase emails`
- [ ] 6. Pin AC10 in `SignOutTest`, including a guest's POST `/logout`; no code should change. Commit: `[PE-003] Pin sign-out behaviour`
- [ ] 7. Share `auth.user` through `AuthenticatedUserResource` in `HandleInertiaRequests::share()`, with `types/auth.ts` and the `types/inertia.d.ts` declaration merge. Test-first: `SharedAuthUserPropTest` (AC14), plus two `WelcomePageTest` cases for AC15's prop. Commit: `[PE-003] Share the signed-in user as an explicit auth prop`
- [ ] 8. Add `DemoUserSeeder`, make `DatabaseSeeder` call only that seeder, add a seed step to the CI `e2e` job, and document the demo account and "run `make seed` before `make e2e`" in the README. Test-first: `DemoUserSeederTest` for AC19 and the seeder rows. Commit: `[PE-003] Seed a local demo user`
- [ ] 9. Add `components/ui/Button.vue`, test-first in `Button.test.ts`. Commit: `[PE-003] Add the Button primitive with a busy state`
- [ ] 10. Add `components/ui/Field.vue` and `components/ui/TextInput.vue`, test-first in `Field.test.ts` and `TextInput.test.ts`. Commit: `[PE-003] Add Field and TextInput primitives with linked errors`
- [ ] 11. Add `lib/paths.ts` and `layouts/AppLayout.vue`, and render the `Dashboard` placeholder in the layout. Extend the integration setup with the `usePage` and `Link` stubs and the `support/inertia.ts` helpers. Test-first: `AppLayout.test.ts`. Commit: `[PE-003] Add the app layout to the signed-in home`
- [ ] 12. Add `layouts/AuthLayout.vue` and build `Auth/Login` on it, test-first in `Login.test.ts` (AC3, AC6's inline message, AC16, AC17). Commit: `[PE-003] Build the sign-in page on the auth layout`
- [ ] 13. Build `Auth/Register`, test-first in `Register.test.ts` (AC8 display, AC16, AC17). Commit: `[PE-003] Build the create-account page`
- [ ] 14. Add the guest and signed-in calls to action to `Welcome`, test-first through new `Welcome.test.ts` cases (AC15). Commit: `[PE-003] Show sign-in calls to action on the Welcome page`
- [ ] 15. Add `tests/e2e/auth.spec.ts` and its `support/` helpers, and switch `welcome.spec.ts` to the shared no-horizontal-scroll helper (AC18 and the browser half of AC1–3, AC7, AC10 and AC17). Commit: `[PE-003] Cover sign-up, sign-in and sign-out end to end`

## Backend
PHP classes are `final` and declare `strict_types`. Every claim about Fortify below was checked against the v1.43.0 source.

| Class / file | Location | Role |
| --- | --- | --- |
| `composer.json` / `composer.lock` | | Adds `laravel/fortify` only. The lock may add only Fortify and its own dependency tree (`laravel/passkeys` → `web-auth/webauthn-lib`, `bacon/bacon-qr-code`, `pragmarx/google2fa` and what they pull in); no locked package may change version. `--no-scripts` skips `post-update-cmd`, whose `boost:update` would rewrite `CLAUDE.md` and `AGENTS.md`. Leave that refresh to the user. |
| `config/fortify.php` | `config/` | Trimmed to five keys, each with a one-line reason; the rest fall back to Fortify's defaults through `mergeConfigFrom`. `home` = `/dashboard`. `views` = true. `features` = `[registration()]`. `limiters` = `['login' => null]`: null keeps the pipeline's `EnsureLoginIsNotThrottled`, which counts failures only (5 per minute per email + IP), clears on success and reports inline. A named limiter would count every attempt and answer 429. `lowercase_usernames` = false, because this app lowercases emails itself (D-18). |
| `FortifyServiceProvider` | `app/Providers/` | Published by `fortify:install` and registered in `bootstrap/providers.php`; the empty `register()` and all rate limiters are removed. `boot()` does four things. It binds `createUsersUsing(CreateNewUser)`. `loginView` and `registerView` return `Inertia::render('Auth/Login')` and `Inertia::render('Auth/Register')`. `confirmPasswordView` aborts with 404, because Fortify always registers that GET and no page uses it. `authenticateThrough` returns `EnsureLoginIsNotThrottled`, `CanonicalizeUsername`, `AttemptToAuthenticate`, `PrepareAuthenticatedSession`, which is Fortify's default pipeline without two-factor, with lowercasing always on. |
| `CreateNewUser` | `app/Actions/Fortify/` | The Fortify path the story names. If the input's `email` is a string, it lowercases it with `Str::lower` (the same call `CanonicalizeUsername` makes) before validating. The rules are the stub's (name required, string, max 255; email required, string, email, max 255, `Rule::unique`), plus inlined password rules: required, string, `Password::default()` (min 8), confirmed. It creates the user and lets the model's `hashed` cast hash the password. |
| `routes/web.php` | | A `Route::middleware('auth')` group with `Route::inertia('/dashboard', 'Dashboard')->name('dashboard')`, the group later pages join. |
| `AuthenticatedUserResource` | `app/Http/Resources/` | `toArray()` returns exactly `id`, `name`, `email`, with a PHPDoc array shape. It is shared via `->resolve($request)`: Inertia would wrap a bare `JsonResource` in `data`, and the AC14 exact-keys test catches that. |
| `HandleInertiaRequests::share()` | `app/Http/Middleware/` | Merges `parent::share()` (errors) with `auth.user`: a lazy closure giving the resolved resource, or null for a guest. |
| `DemoUserSeeder` | `database/seeders/` | Public `EMAIL` (`demo@promptedit.test`), `NAME` (`Demo User`) and `PASSWORD` (`promptedit-demo`) constants, with a docblock saying they are local-only. It throws a `RuntimeException` when the app is in production; PE-016's operator seeder copies this pattern. It calls `updateOrCreate` keyed by email, so re-seeding resets the documented name and password. |
| `DatabaseSeeder` | `database/seeders/` | Calls `DemoUserSeeder` only. The factory `test@example.com` user goes, because it made a second `make seed` fail on the unique email. |
| `.github/workflows/ci.yml` | | `e2e` job: a "Seed the database" step (`artisan db:seed --force`) after the migrate step, with the same `docker run` flags. The CI image's `.env` is `local`, so the seeder runs. |
| `README.md` | | Under Local development: the demo account and that `make seed` creates it. Under Testing: E2E signs in as it, so run `make seed` once. |

## Routes
| Method | Path | Name | Middleware | Answers |
| --- | --- | --- | --- | --- |
| GET | `/dashboard` | `dashboard` | `web`, `auth` | `Dashboard`; guests → `/login`, keeping the intended URL |
| GET / POST | `/login` | `login` / `login.store` | `guest` | Fortify; signed-in users → `/dashboard` |
| POST | `/logout` | `logout` | `auth` | Fortify → `/` |
| GET / POST | `/register` | `register` / `register.store` | `guest` | Fortify; signed-in users → `/dashboard` |
| GET | `/user/confirm-password` | `password.confirm` | `auth` | 404 (view callback) |
| POST | `/user/confirm-password` | `password.confirm.store` | `auth` | Fortify. Harmless: it only stamps the user's own session, and no route requires `password.confirm`. |
| GET | `/user/confirmed-password-status` | `password.confirmation` | `auth` | Fortify JSON `{confirmed}` |

- The route is named `dashboard` because Laravel's `guest` middleware sends signed-in users to a route of that name by default, so AC11 needs no config. PE-005 swaps the page behind the same name.
- Fortify's `home` repeats the path, because config can't call `route()`. `SignInTest` asserts the landing URL with `route('dashboard')`, so the two can't drift apart unnoticed.

## Frontend
`<script setup lang="ts">` only. Primitives take props, emit events and import nothing from Inertia.

| File | Role |
| --- | --- |
| `types/auth.ts` | `AuthUser` (`id: number`, `name`, `email`) and `AuthProps` (`user: AuthUser \| null`). |
| `types/inertia.d.ts` | Declaration merge of `InertiaConfig.sharedPageProps` (`auth: AuthProps`) into `@inertiajs/core`, so `usePage().props.auth` is typed everywhere. |
| `lib/paths.ts` | `as const` paths: home `/`, login, register, logout, dashboard. These are the client's only URL literals. Consumers: both layouts and all four pages. |
| `components/ui/Button.vue` | Props: `type` (`button` default / `submit`), `variant` (`primary` / `secondary`), `disabled`, `busy`, `busyLabel`, and `as` (default `button`, or an element or component). When busy it is natively disabled, sets `aria-busy` and shows a spinner plus `busyLabel` (falling back to the slot). With a non-button `as` it renders that element with attributes passed through and no `type` or `disabled`; pages pass Inertia's `Link` so links look like buttons. |
| `components/ui/Field.vue` | Props: `label`, `hint?`, `error?`, `id?` (defaults to Vue `useId()`). It renders a visible `<label for>`, then the slot, then the hint and error paragraphs with derived ids. The default slot exposes `{ id, describedBy, invalid }` for the page to bind to the input (AC17). |
| `components/ui/TextInput.vue` | `defineModel<string>()` and an `invalid` prop, which sets `aria-invalid="true"` and the red border (the attribute is left off when valid). The root is the `<input>`, so `id`, `type`, `autocomplete` and `aria-describedby` fall through. |
| `layouts/AuthLayout.vue` | A new `resources/js/layouts/` folder, the Inertia and Laravel starter-kit convention for page frames (the story leaves this to the Architect, and CLAUDE.md is unchanged). A centred column: the PromptEdit link to `/`, then a card with an `h1` from the `title` prop, the default slot and a `footer` slot. |
| `layouts/AppLayout.vue` | `<header>` with the PromptEdit link to `dashboard`, a `nav` slot (rendered only when filled), the user's name from `usePage().props.auth.user` (truncated, so long names never scroll the page) and a secondary **Sign out** Button that calls `router.post(paths.logout)`. On mobile the brand and Sign out share the first row and the name wraps below it, as in the mockup. `<main>` holds the default slot. |
| `pages/Dashboard.vue` | `Head` "Dashboard"; in AppLayout: "You're signed in." / "Image generation is coming next." |
| `pages/Auth/Login.vue` | `Head` "Sign in" in AuthLayout. `useForm({ email, password })` on a `novalidate` form, so the server's field messages are the only ones (AC4). Email: `type=email`, `autocomplete=username`. Password: `autocomplete=current-password`. Submit returns early while `form.processing` (AC16; the disabled default button also blocks Enter), then posts to login with `onFinish` resetting `password` (AC3). Submit Button: busy label "Signing in…". Footer: "No account yet? Create one". |
| `pages/Auth/Register.vue` | `Head` "Create account" in AuthLayout. Fields: Name (`autocomplete=name`), Email, Password with the hint "At least 8 characters.", and Confirm password (both `new-password`). It has the same guard, and after a failed submit it resets both password fields and keeps the other inputs (AC8; the mockup's dots are illustrative). Laravel puts the `confirmed` error on `password`, so it shows under Password. Busy label "Creating account…". Footer: "Already have an account? Sign in". |
| `pages/Welcome.vue` | Existing copy stays. A guest sees a primary **Sign in** and a secondary **Create account** Button-`Link`; a signed-in user sees only a primary **Open PromptEdit →** to `dashboard`. |

Visual language (Flux-inspired; no Flux source is read or copied):
- Zinc neutrals on white, `text-sm` controls, and the existing Instrument Sans.
- Cards are white, `rounded-xl`, with a zinc-200 border and `shadow-xs`. Inputs and buttons are `h-10` and `rounded-lg`.
- Primary is zinc-900 on white text. Secondary is white with a zinc-200 border. Both get a visible `focus-visible` outline.
- Invalid inputs get a red-500 border; their message is red-600 with an inline `aria-hidden` warning icon.
- Busy and disabled states show `opacity-75` and a `motion-safe:animate-spin` spinner.
- Icons are inline SVG, with no icon dependency. There is no dark mode, because no existing page has one.

## Decisions log (requirements §5)
| # | Decision | Why |
| --- | --- | --- |
| D-17 | Login throttling is Fortify's pipeline step `EnsureLoginIsNotThrottled` (5 failures per minute per email + IP, cleared on success), not a route rate limiter | Successful sign-ins never count, so parallel E2E and real users can't lock themselves out. A lockout shows inline under Email instead of a 429 page. |
| D-18 | Emails are lowercased by this app at both entry points: an explicit login pipeline, and `CreateNewUser` before its uniqueness check, once the value is known to be a string. Fortify's `lowercase_usernames` is off. | Postgres compares strings case-sensitively, so `Ada@…` and `ada@…` would be two accounts. Fortify v1.43's flag also lowercases at registration, but it does so before validation, so a crafted `email[]` answers 500. |

## Tests
`RefreshDatabase` is used wherever a user exists. `tests/Concerns/InteractsWithAuthentication` has two helpers: `givenAnAccount(email, password)`, which builds a factory user with a known password, and `attemptSignIn(email, password)`, which posts to `/login` from `/login`. Its consumers are `SignInTest`, `LoginThrottleTest`, `RegistrationTest` and `SignOutTest`.

| Test | Location | Covers |
| --- | --- | --- |
| `FortifyRoutesTest` | `tests/Feature/Auth/` | AC1 (guest → `/login`), AC11, AC12, AC13, in the rows below |
| ↳ inventory | | Every route whose action is in `Laravel\Fortify\` or `Laravel\Passkeys\` equals exactly the eight method + URI pairs in Routes. This is the automated form of `route:list`, and it fails if a Fortify upgrade adds a route. |
| ↳ disabled features | | A data provider of every method + URI from Fortify's route file for password reset, email verification, profile, password update, two-factor and passkeys (e.g. `PUT /user/profile-information`, `DELETE /user/passkeys/1`). Each answers 404, both for a guest and for a signed-in user. |
| ↳ remaining routes | | Each Routes row, for a guest and for a signed-in user, gives its listed answer and never a 500. For POST `/user/confirm-password`, a wrong password goes back with the `password` error "The provided password was incorrect." and the right one goes to `dashboard`. |
| `SignInTest` | `tests/Feature/Auth/` | AC1: a guest asks for `/dashboard?from=test`, signs in and lands there, not on plain home. AC2: the `Dashboard` component, signed in. AC3: wrong password and unknown email each give exactly one error, on `email`, the identical credentials message, and still a guest. AC4: empty fields give two field errors, no `Attempting` event and no limiter hit. AC9: `ADA@example.com` signs into `ada@example.com`. |
| ↳ AC5 | | Laravel tests send no cookies, so every request gets a fresh session id unless the session cookie is sent. Send a known id as the session cookie and assert that a successful sign-in changes it. A control row (a failed sign-in keeps it) proves the test can fail. |
| `LoginThrottleTest` | `tests/Feature/Auth/` | AC6 under `freezeTime()`, in the throttle rows below. The refused attempt is sent with the Inertia headers (`X-Inertia`, `X-Requested-With`, an HTML `Accept`), as the browser sends it, and must come back as a redirect with the inline message "… in 60 seconds.", never a 429. |
| `RegistrationTest` | `tests/Feature/Auth/` | AC7: the account is created, signed in, and lands on `dashboard`. AC8: a data provider with exact messages, no account created, and the non-password input flashed. AC9: `Ada@Example.COM` is stored as `ada@example.com`, and the case-only duplicate is refused with "The email has already been taken.". |
| `SignOutTest` | `tests/Feature/Auth/` | AC10: POST `/logout` goes to `/`, leaves a guest and changes the CSRF token, and `/dashboard` then goes to `/login`. A GET to `/logout` answers 405 and stays signed in. A guest's POST goes to `/login`. |
| `SharedAuthUserPropTest` | `tests/Feature/Auth/` | AC14: `auth.user` is null for a guest. For a signed-in user it is exactly `id`, `name`, `email`, asserted with a closure-scoped `has()` and no `etc()`, so any extra key fails. |
| `WelcomePageTest` (existing) | `tests/Feature/` | AC15's prop: `auth.user` is null for a guest and the user for a signed-in visitor |
| `DemoUserSeederTest` | `tests/Feature/Database/` | AC19 and the seeder rows: seeding twice leaves one demo user with the documented name and password, and that user can sign in. |
| `Button.test.ts` | `tests/js/integration/components/ui/` | Slot label; default `type=button`. Busy: disabled, `aria-busy`, busy label. Disabled and busy each block the click handler. `as` renders the given element with `href` and without `type` or `disabled`. |
| `Field.test.ts`, `TextInput.test.ts` | `tests/js/integration/components/ui/` | AC17 wiring: the label's `for` matches the input id; the hint and error ids are in `aria-describedby`; an error sets `aria-invalid="true"`, and no error leaves it off. `v-model` both ways; attributes fall through. |
| `AppLayout.test.ts` | `tests/js/integration/layouts/` | The brand links to `dashboard`. The user's name is shown. **Sign out** is a button that calls `router.post('/logout')` once. The nav slot renders inside `<nav>`. |
| `Login.test.ts` | `tests/js/integration/pages/Auth/` | Posts the email and password. With the visit pending, it shows "Signing in…", disables the button, and two clicks or submits produce one `router.post`. Server errors (credentials, then throttle) show under Email with aria linkage; the email is kept and the password cleared. |
| `Register.test.ts` | `tests/js/integration/pages/Auth/` | Posts four fields. Each field's server error shows under it; the confirmation error shows under Password. Name and email are kept and both passwords cleared. "Creating account…", with one post on a double submit. |
| `Welcome.test.ts` (existing) | `tests/js/integration/pages/` | Plus: a guest gets two CTAs with `/login` and `/register` hrefs; a signed-in user gets only **Open PromptEdit** to `/dashboard`. |
| `auth.spec.ts` | `tests/e2e/` | Both projects, in the rows below |
| ↳ sign up | | Register a unique user (see below), with a 60-character name that has no spaces. Assert the header shows the name, sign out, land on Welcome, and check that `/dashboard` then goes to `/login`. |
| ↳ wrong credentials | | A unique email with no account. The message appears under Email, the URL stays `/login`, the email is kept and the password is empty. |
| ↳ demo | | Sign in as the demo user using only the keyboard (type, Tab, Enter), land on `dashboard` showing "Demo User", then sign out with Enter on the focused button (AC17). |
| ↳ AC18 | | `/login`, `/register` and the signed-in `/dashboard` have no horizontal scroll, and **Sign out** is in the viewport. |

Integration and E2E support:
- `tests/js/integration/setup.ts` keeps the `Head` stub and adds a `usePage` stub that returns test-controlled props, plus a `Link` stub that renders an `<a>` with an inspectable `href`.
- `tests/js/integration/support/inertia.ts` provides:
  - `givenAGuest()` and `givenSignedInAs(user)`;
  - `givenVisitsStayPending()`: spies on `router.post` and calls only the visit's `onStart`;
  - `givenVisitsFailWith(errors)`: `onStart`, then `onError`, then `onFinish`.
- `tests/e2e/support/auth.ts` holds the `DEMO_USER` credentials (mirroring the seeder constants) and `uniqueEmail(testInfo)`, which combines the project name, the worker index and the time.
- `tests/e2e/support/layout.ts` holds `expectNoHorizontalScroll(page)`, also used by `welcome.spec.ts`.
- The primitive suites live in the jsdom `integration` project: they mount components, which the node `unit` project can't. The story's "JS unit" label is loose here.

Boundary rows to add beyond the ACs:

| Subject | Row | Expect |
| --- | --- | --- |
| Throttle | 5 failures, then the correct password 59 s later | Still refused |
| Throttle | The same, 60 s after the lockout | Signs in |
| Throttle | Another email from the same IP; the locked email from another IP | Both unaffected |
| Throttle | Failures as `ADA@example.com` and as `ada@example.com` | Share one bucket |
| Throttle | 4 failures, a success, 4 more failures, the correct password | Signs in (a success clears the counter) |
| Throttle | Sign in and out 10 times in a row | All succeed (no route limiter) |
| Registration | Name of exactly 255 characters; password of exactly 8 | Accepted |
| Registration | `email` submitted as an array | Field error on `email`, no account, no 500 |
| Registration | ` Ada@Example.com ` with surrounding spaces | Stored as `ada@example.com` (`TrimStrings`) |
| Seeder | The demo user's password changed, then re-seeded | The documented password works again |
| Seeder | App environment `production` | Throws, and no user is created |

| AC | Proven by |
| --- | --- |
| 1–5, 9 | `SignInTest`, `FortifyRoutesTest`, `RegistrationTest`; E2E sign up, wrong credentials and demo |
| 6 | `LoginThrottleTest`, `Login.test.ts` |
| 7, 8, 10 | `RegistrationTest`, `Register.test.ts`, `SignOutTest`; E2E sign up |
| 11–13 | `FortifyRoutesTest` |
| 14, 15 | `SharedAuthUserPropTest`, `WelcomePageTest`, `Welcome.test.ts` |
| 16, 17 | `Login.test.ts`, `Register.test.ts`, the primitive suites, E2E demo |
| 18 | E2E AC18 rows |
| 19 | `DemoUserSeederTest`, plus the CI seed step on the PR's E2E run |

## Risks
- **PR size is well over budget.**
  - Estimate: ≈1,800–2,000 changed lines, excluding `composer.lock`. Tasks 1–8 are ≈1,000 lines (≈300 of PHP, config, CI and README; ≈700 of PHP tests). Tasks 9–15 are ≈900 (≈450 of Vue/TS; ≈450 of Vitest and Playwright).
  - The story's 550–650 left out the test volume; PE-002, estimated at 1,600–1,800, landed at ≈2,260.
  - The lockfile alone adds several hundred lines, because Fortify requires `laravel/passkeys` (and with it `web-auth/webauthn-lib`) and its two-factor libraries even though those features stay off.
  - It ships as one PR of 15 commits, as the user asked. If a cut is wanted, cut after task 8: PR A is the backend with stub pages, PR B the UI and E2E.
- **The story's email note is outdated for v1.43.** `lowercase_usernames` also lowercases at registration, but before validation, and `Str::lower` on an array throws a `TypeError`. That is why D-18 turns it off and lowercases in two places. PE-004's account command must lowercase the same way.
- **Fortify upgrades.** The explicit pipeline stops future default-pipeline changes from applying silently, and the route inventory test fails if Fortify adds a route. Both are intended, so read the diff on an upgrade.
- **A vacuous session test.** Without the session cookie, every test request gets a new session id, so AC5 would pass without any regeneration. The control row is mandatory. The CSRF assertion in AC10 compares tokens, not ids.
- **E2E lockout.** The wrong-credentials spec must never use the demo email. The desktop and mobile projects, plus CI retries, could reach 5 failures within a minute and lock out the parallel demo sign-in. A unique email shows the same message (AC3).
- **Account enumeration.** Sign-in hides whether an account exists (AC3), but registration necessarily reveals it (AC8). Timing differences are therefore moot, and rate-limiting registration stays on PE-015's hardening list.
- **Registration race.** Two simultaneous sign-ups with one email: the unique index still prevents a duplicate account, but the losing request gets a 500. This is accepted. The double-submit guard covers the same-tab case, and a friendly 500 page is PE-015's job.
- **Inertia 3 `useForm`.** It sets `processing` in `onStart`, so the page's own early-return guard is what makes AC16 hold. A JSON client (`postJson`) gets a 429 on lockout; only the Inertia path must redirect.
- **`novalidate` is load-bearing.** Without it the browser's own `type=email` and required checks would pre-empt the server messages that AC4 specifies.
- **Stub pages.** Inertia's `ensure_pages_exist` is on for tests, so the task-2 stubs must exist until tasks 11–13 replace them.
- **GET `/logout`** answers the framework's bare 405 page. This is accepted, since friendly error pages are out of scope (PE-015).
- **Local E2E** needs `make seed` once. Each run leaves `e2e-…@promptedit.test` users in the dev database, which is harmless.
