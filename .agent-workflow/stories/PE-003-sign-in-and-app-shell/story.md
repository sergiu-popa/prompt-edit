# Story: PE-003-sign-in-and-app-shell

## Title
Sign in, sign up and sign out (Fortify + Inertia) with the app shell

## Status
`qa-passed`

## Description
Users must be signed in before they can generate images or see their history (required "auth"). This story adds Laravel Fortify as the authentication backend, with minimal hand-built Inertia/Vue pages for sign-in and sign-up (D-02). It also adds:

- the first Flux-inspired UI primitives (no Flux code);
- the signed-in app layout that every later page plugs into;
- a signed-in home placeholder, which the Generate page replaces in PE-005.

It is the first real Playwright user flow. It serves grading criteria #3 (clear UX, including what went wrong) and #4 (code quality).

## Acceptance Criteria

**Sign in**

1. A guest who opens the signed-in home, or any page in the authenticated group, is redirected to `/login`. After signing in, they land on the page they originally asked for.
2. Signing in with a valid email and password lands on the signed-in home, which shows the app shell: the app name, the user's name and a **Sign out** button.
3. A wrong password, or an email with no account, keeps the user on `/login` as a guest and shows one message under Email: "These credentials do not match our records."
   - The message is the same in both cases, so it reveals nothing about which accounts exist.
   - The email is kept and the password is cleared.
4. Submitting with Email or Password empty shows a field message under each empty field. No authentication attempt is made, and it does not count towards the throttle.
5. A successful sign-in regenerates the session id (session fixation): the session id before and after sign-in differ.
6. **Throttling:**
   - After 5 failed attempts within a minute for the same email and IP, the 6th attempt is refused even with the correct password.
   - The refusal shows an inline message under Email, "Too many login attempts. Please try again in N seconds.", never a bare 429 error page.
   - Once the window has passed (time travel in tests), the correct credentials work.
   - A different email from the same IP is unaffected.
   - Successful sign-ins never count towards the limit: signing in and out 10 times in a row always succeeds.

**Sign up**

7. Registering with name, email, password and password confirmation creates the account, signs the user in and lands them on the signed-in home.
8. Each of these shows a field message, creates no account and keeps the non-password inputs:
   - missing name;
   - name over 255 characters;
   - invalid email;
   - an email that is already registered, including case-only differences (`Ada@Example.com` vs `ada@example.com`): "The email has already been taken.";
   - a password under 8 characters;
   - a confirmation that does not match.
9. Emails are stored lowercase. Registering `Ada@Example.COM` stores `ada@example.com`, and signing in as `ADA@example.com` works.

**Sign out, guards and unused Fortify routes**

10. **Sign out** (POST `/logout`) ends the session, regenerates the CSRF token and lands on the Welcome page. Opening the signed-in home afterwards redirects to `/login`. A GET to `/logout` does not sign the user out.
11. A signed-in user who opens `/login` or `/register` is redirected to the signed-in home.
12. Unused Fortify features have no routes: `/forgot-password`, `/reset-password/{token}`, `/email/verify`, `/two-factor-challenge`, `/user/profile-information`, `/user/password` and the passkey routes return 404.
13. No Fortify route answers 500 because a view is missing. Every route Fortify registers either serves this story's pages, redirects, or returns 404. That includes the password-confirmation routes (`/user/confirm-password`, `/user/confirmed-password-status`), which Fortify defines whatever features are enabled. Verified with `php artisan route:list` and a feature test per remaining route.

**Props and pages**

14. The shared Inertia prop `auth.user` is `null` for guests. For a signed-in user it is exactly `{id, name, email}`, asserted on keys: no password hash, remember token, timestamps or any other column. A matching TS type exists in `resources/js/types/`.
15. The Welcome page shows **Sign in** and **Create account** to guests, and a single **Open PromptEdit** link to the signed-in home for signed-in users.
16. While a sign-in or sign-up request is in flight:
    - its submit button shows a busy label ("Signing in…" / "Creating account…") and is disabled;
    - a second click sends no second request.
17. Accessibility:
    - every input has a visible label;
    - every error message is linked to its input (`aria-invalid` plus `aria-describedby`);
    - every action is reachable by keyboard.
18. Login, Register and the signed-in home have no horizontal scroll in the Playwright `mobile` project (Pixel 7), and **Sign out** stays reachable in the header.
19. A local seeder creates the demo user `demo@promptedit.test` with a documented local-only password. Running `make seed` twice neither fails nor duplicates the user. The seeder is not used in production; PE-004 provides a separate command.

## Scope

### In Scope
- `laravel/fortify` with login, registration and logout.
  - Fortify's login throttling stays on in failure-counting mode (see Technical Notes).
  - Password reset, email verification, profile and password updates, 2FA and passkeys are disabled.
  - Views are Inertia pages registered through Fortify's view callbacks.
- Lowercase email canonicalisation on registration and login.
- Pages `Auth/Login` and `Auth/Register`.
- Two layouts: an auth layout (centred card) and an app layout (app name, nav slot, signed-in user, Sign out).
- Only the `components/ui/` primitives these pages need, e.g. Button (busy/disabled), TextInput, Field (label, hint, error with aria wiring) and Card. Props in, events out, no Inertia imports.
- Shared `auth.user` prop, shaped explicitly, with its TS type.
- An authenticated route group with a signed-in home placeholder, guest/auth redirects and the Welcome page calls to action.
- The demo user seeder, and a seed step in the CI `e2e` job.
- PHP feature tests, Vitest unit and integration tests, and a Playwright E2E flow (see Technical Notes).

### Out of Scope
- Password reset, email verification, 2FA, passkeys, "remember me", profile/settings pages.
- Friendly pages for 419 (session expired) and 500, and rate limiting or bot protection on registration. These are candidates for the PE-015 hardening checklist.
- The production reviewer/demo account command and production deployment (PE-004).
- Navigation entries for Generate (PE-005) and History (PE-011).
- Operator role and gate (PE-016).
- Credit balance in the header (PE-017).

## Technical Notes
- **Decision:** D-02 (Fortify for auth; minimal hand-built UI with a Flux-inspired visual language). Never copy Flux source, which is licensed.
- **Dependency:** `laravel/fortify` is a new Composer dependency, approved by the stack decision (D-02); mention it in the PR. Fortify's published stubs (config, service provider, `app/Actions/Fortify/*`) count as generated only while unmodified, so trim them to the features in use.
- **Fortify throttling** (Laravel 13 Fortify docs; verify against the installed version). The two modes behave differently:
  - When `fortify.limiters.login` names a rate limiter (the published config's default is `'login'`), the limiter runs as route middleware. It counts **every** attempt, including successful ones, and answers 429.
  - When that option is unset, the pipeline's `EnsureLoginIsNotThrottled` step applies instead. It counts **failures only** (5 per minute per email + IP), clears on success, and reports a lockout as a validation error on the email field. Inertia shows that inline instead of a 429 page.

  AC6 requires the second mode. It also stops the parallel desktop and mobile E2E projects, which sign in as the demo user many times, from locking themselves out.
- **Password-confirmation routes:** the Fortify docs say Fortify always defines `/user/confirm-password`. Without a registered confirm-password view, its GET would fail. AC13 makes sure it doesn't 500; how is the Architect's call.
- **Email case:** Fortify's `lowercase_usernames` option (the CanonicalizeUsername step) only affects login. Registration must lowercase too, **before** the uniqueness check. Postgres string comparison is case-sensitive, so this decides whether `Ada@…` and `ada@…` become two accounts.
- **Routes:** `/` is already the `home`-named Welcome route (`routes/web.php`), so the signed-in home needs its own path and name. Point Fortify's `home` at it. PE-005 replaces the placeholder with the Generate page.
- **Conventions (CLAUDE.md):**
  - Props are explicit (`auth.user` is shaped, never a raw model), and each has a TS type.
  - Pages are thin.
  - `<script setup lang="ts">`, no `any`, and `vue-tsc` passes.
  - Where layouts live is the Architect's call.
- **E2E environment** (`playwright.config.ts`, `tests/e2e/fixtures.ts`):
  - The `desktop` and `mobile` projects run with `fullyParallel`.
  - The auto fixture fails a test on any browser console error or page error.
  - Registration in E2E needs an email unique per run and per project.
  - The CI `e2e` job runs `migrate --force` but does not seed. Add a seed step so the demo user exists (a small CI change inside this PR).
- **Required test layers:**
  - PHP feature (AC1–AC15, AC19):
    - sign-in success and failure, session regeneration;
    - throttle and its recovery with time travel;
    - registration validation and email lowercasing;
    - logout (POST only);
    - guest/auth redirects;
    - disabled routes return 404, and the remaining Fortify routes never 500;
    - exact `auth.user` keys via `assertInertia`;
    - the Welcome CTA prop;
    - seeder idempotency.
  - JS unit (Vitest), the primitives:
    - Button busy/disabled emits nothing when disabled;
    - Field renders the label and error with aria linkage;
    - TextInput supports v-model.
  - JS integration (Vitest + Vue Test Utils, Inertia mocked):
    - the Login page renders server errors and the busy state and doesn't double-submit;
    - the Register page renders field errors;
    - the Welcome page shows guest vs signed-in CTAs.
  - E2E (Playwright), both projects:
    - register a unique user → signed-in home shows their name → sign out;
    - a wrong password shows the credentials message;
    - sign in as the seeded demo user → signed-in home;
    - the AC18 no-horizontal-scroll check.
- **Depends on:** the bootstrap only (PRs #2–#6: Postgres, Inertia/Vue/TS, the Vitest/Playwright harness, queue worker and scheduler).
- **PR size:** estimated 550–650 changed lines (Fortify setup, two pages, two layouts, four primitives, tests), so likely over the ~400 budget. Use only the primitives these pages need; later stories add their own. If it is over, split it before planning into two stories:
  - (a) Fortify backend, email canonicalisation, route hygiene, seeder and PHP feature tests, with bare pages;
  - (b) the Vue primitives, layouts, styled pages, and the JS and E2E tests.

## Mockups / References

```text
Sign in: default (auth layout, centred card; same on mobile)
┌──────────────────────────────────────────────┐
│                  PromptEdit                  │
│   ┌──────────────────────────────────────┐   │
│   │  Sign in                             │   │
│   │                                      │   │
│   │  Email                               │   │
│   │  ┌────────────────────────────────┐  │   │
│   │  │ ada@example.com                │  │   │
│   │  └────────────────────────────────┘  │   │
│   │  Password                            │   │
│   │  ┌────────────────────────────────┐  │   │
│   │  │ ••••••••                       │  │   │
│   │  └────────────────────────────────┘  │   │
│   │  ┌────────────────────────────────┐  │   │
│   │  │            Sign in             │  │   │
│   │  └────────────────────────────────┘  │   │
│   │  No account yet? Create one          │   │
│   └──────────────────────────────────────┘   │
└──────────────────────────────────────────────┘

Sign in: wrong credentials (email kept, password cleared)
│  Email                               │
│  ┌────────────────────────────────┐  │  red border, aria-invalid
│  │ ada@example.com                │  │
│  └────────────────────────────────┘  │
│  ⚠ These credentials do not match our records.
│  Password                            │
│  ┌────────────────────────────────┐  │
│  │                                │  │

Sign in: throttled (inline under Email, no 429 page)
│  ⚠ Too many login attempts. Please try again in 42 seconds.

Sign in: submitting
│  ┌────────────────────────────────┐  │
│  │  ◌ Signing in…      (disabled) │  │
│  └────────────────────────────────┘  │

Create account: validation errors
│  Name             [ Ada Lovelace            ]
│  Email            [ ada@example.com         ]
│                   ⚠ The email has already been taken.
│  Password         [ ••••                    ]
│                   ⚠ The password field must be at least 8 characters.
│  Confirm password [ ••••                    ]
│  [            Create account            ]
│  Already have an account? Sign in

App shell: signed-in home placeholder (desktop)
┌──────────────────────────────────────────────────────────────────┐
│ PromptEdit   (nav slot)                  Ada Lovelace [Sign out] │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│   You're signed in.                                              │
│   Image generation is coming next.                               │
│                                                                  │
└──────────────────────────────────────────────────────────────────┘

App shell: mobile (Pixel 7, no horizontal scroll)
┌──────────────────────────────┐
│ PromptEdit         [Sign out]│
│ Ada Lovelace                 │
├──────────────────────────────┤
│ You're signed in. …          │
└──────────────────────────────┘

Welcome: calls to action
 guest:      [ Sign in ]  [ Create account ]
 signed in:  [ Open PromptEdit → ]
```

- Fortify (authentication pipeline, throttling, `lowercase_usernames`, view callbacks, password confirmation): https://laravel.com/docs/13.x/fortify
- Inertia shared data: https://inertiajs.com/shared-data
- Repo: `playwright.config.ts` (desktop + Pixel 7 projects), `tests/e2e/fixtures.ts` (console-error guard), `.github/workflows/ci.yml` (`e2e` job, no seed step)
- requirements.md §1; decision D-02; CLAUDE.md Vue layout and testing layers
