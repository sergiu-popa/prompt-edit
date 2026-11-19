# Story: PE-011-submit-generation-and-history

## Title
Submit a generation and see it in history

## Status
`draft`

## Description
This story closes the submit step of the core journey. A signed-in user has written a prompt and seen its upfront estimate on the Generate page (PE-005). Now they press Generate.

The server validates the request and recomputes the estimate itself; it never trusts numbers from the client. It then creates the local generation with its hold (PE-007) and dispatches submission (PE-009). The user lands on a new History page that lists their own generations, each with its status and the estimate they saw.

This story serves:
- the required “generate page with upfront estimate” and “history” features
- criterion #1: the stored estimate and hold are authoritative, and a double click never creates two generations
- criterion #3: a clear and safe submit flow

It is the first end-to-end flow against the fake Pixelforge (PE-010). Live status, images, actual cost and failure copy follow in PE-012.

## Acceptance Criteria
**Lighthouse** is the prompt `A lighthouse on a cliff at dawn, oil painting` (45 code points, so e = 12 and the text bounds are 10–14), `high`, `1536x1024`, with the reference `https://example.com/style.png`. Its server estimate is:
- min **347,750,000 n$ ($0.347750000)**
- typical **402,450,000 n$ ($0.402450000)**
- max **457,150,000 n$ ($0.457150000)**

Its hold is −457,150,000 n$.

**Validation (server side, the authority)**
1. `prompt` is required, and a whitespace-only prompt is rejected. Length counts Unicode code points:
   - 3,998 × `a` + 2 × 🎨 is 4,000 code points, 4,002 UTF-16 units and 4,006 bytes. It is accepted.
   - 3,999 × `a` + 2 × 🎨 is 4,001 code points. It is rejected with an error on the prompt field.
2. `size` is required and must be one of `1024x1024`, `1536x1024` or `1024x1536`, so `512x512` is rejected. `quality` is required and must be one of `low`, `medium` or `high`, so `ultra` is rejected.
3. `reference_images` is optional, with at most 4 entries.
   - Each entry must be an absolute `http` or `https` URL of at most 2,048 characters. That bound is ours; Pixelforge documents none.
   - Each of these is rejected with an error on that entry: a fifth entry, an empty entry, `ftp://example.com/a.png`, `javascript:alert(1)` and `example.com/a.png`.
   - Duplicate URLs are allowed; each is billed 765 tokens.
   - URLs are checked for shape only and never fetched (requirements §6).
   - The Generate page drops blank and whitespace-only rows before it submits, because PE-005 AC 9 leaves them out of the estimate. The submitted references are therefore exactly the ones the estimate counted. For example, a Lighthouse form with one URL row and one blank row submits 1 reference and holds −457,150,000 n$, not a 2-reference amount. An empty entry sent directly to the endpoint is still rejected.
4. The client cannot set money, ownership or metadata. Extra fields such as `estimate`, `cost`, `user_id` and `metadata` are ignored. The stored estimate and hold are the server's, the owner is the signed-in user, and nothing extra reaches Pixelforge (PE-009 AC 3).
5. When validation fails, no generation, hold or job is created. The Generate page shows each server error inline under its field and keeps the user's input.

**Create**
6. A valid Lighthouse submission:
   - creates one generation for the signed-in user, with the server-computed estimate above
   - writes one hold of −457,150,000 n$
   - dispatches one submit job
   - redirects to History with that generation highlighted
7. The stored estimate equals what the Generate page displayed, including for Unicode and whitespace:
   - Unicode: `A lighthouse on a cliff at dawn, oil painting 🎨🎨🎨🎨` is 50 code points, 54 UTF-16 units and 62 bytes, so e = 13 and the bounds are 11–15. It stores max **457,156,250 n$**, the same max the page showed. Counting UTF-16 units would give 457,168,750 (e = 14), and counting bytes would give 457,181,250 (e = 16).
   - Whitespace: take the Lighthouse prompt with 4 leading spaces. Whichever whitespace rule the Architect picks, four things use the same string: the page, the stored estimate, the hold and the prompt later sent to Pixelforge. That gives a max of 457,150,000 n$ if the spaces are trimmed everywhere (45 code points), or 457,156,250 n$ if they are kept everywhere (49 code points).
8. Double submit:
   - Two identical POSTs from the same form instance (a double click or a resent request) create exactly one generation, one hold and one job. Both requests end on History without an error.
   - Going back to Generate after landing on History and submitting the same prompt again creates a second generation.
9. The Generate button is disabled while the form is invalid on the client: an empty prompt, more than 4,000 code points, more than 4 references, or a bad URL shape. It is also disabled while a submission is in flight, when it shows a busy label.
10. Throttle: the per-user limit defaults to 10 per minute and is configurable. The 11th submission within a minute creates nothing. The user stays on Generate and sees an inline message giving the seconds until they can retry, not an error page or a modal.
11. A guest who posts to the endpoint or opens History is redirected to sign in, and nothing is created.

**History**
12. History lists only the signed-in user's generations, newest first, 20 per page.
    - A user with no generations sees the empty state, even when other users have some.
    - No other user's prompt ever appears in the page props.
13. Each item shows:
    - the prompt, clamped to two lines, with the full prompt available on hover or expand
    - quality and size (`High · 1536×1024`)
    - the reference count (`1 reference`, `2 references` or `No references`)
    - a status badge: Queued (for both `pending` and `queued`), Processing, Succeeded or Failed
    - the estimated range formatted by `money.ts` (`Est. $0.3478 – $0.4572` for Lighthouse)
    - the created time as a relative time, with the exact timestamp on hover
14. The just-submitted generation is highlighted once, after the redirect. It is not highlighted again after a reload.
15. With no generations, History shows an empty state that links to Generate. The app shell nav has a History link with an active state.
16. Page props come from an API Resource with an explicit field list.
    - Money is sent as integer n$.
    - There is no provider cost or margin, and Eloquent models are never passed raw.
    - There is a matching TS type and a reusable typed pagination shape.
    - The History query count is the same whatever the page size (no N+1).

**E2E (fake Pixelforge)**
17. A freshly registered user enters Lighthouse, sees `$0.3478 – $0.4572` on Generate and submits. They land on History, where the generation is highlighted with `Est. $0.3478 – $0.4572` and a Queued, Processing or Succeeded badge.
18. `A [blocked] castle` (`medium`, `1024x1024`, no refs) is 18 code points, so e = 5 and the bounds are 4–6. Its estimate is 58,150,000–86,287,500 n$ (`Est. $0.0582 – $0.0863`). It shows Failed on History after a reload; live updates arrive in PE-012.
19. Double-clicking Generate produces exactly one History item.

## Scope

### In Scope
- A Form Request with the rules above.
- A thin controller that calls PE-006's create action, then redirects to History with the new id flashed.
- Submit wiring on the PE-005 Generate page: button states, inline server errors, the throttle message, and dropping blank reference rows before submit.
- A server-side double-submit guard (the Architect chooses the mechanism) and a per-user throttle on the POST.
- The History page, with its list and item components, empty state, pagination and nav link.
- The generation API Resource, its TS type and a reusable typed pagination shape.

### Out of Scope
- Live polling, images, actual cost and failure copy: PE-012-live-status-results-and-failure-copy.
- Copy for waiting for a slot, daily capacity and service unavailable: PE-013-provider-limits-retries-and-throttle.
- Balance display and blocking unaffordable generations: PE-017-credit-balances-buy-credits.
- Re-dispatching a generation whose job never ran: PE-014-reconciliation-poller.
- The CI worker container: already done in bootstrap PR #6 (see Technical Notes).
- A detail page, filters, search, delete and re-run.

## Technical Notes
- **Depends on:**
  - PE-005-generate-page-live-estimate: the page, the TS estimate and `money.ts`.
  - PE-009-pixelforge-client-and-submit-job: the job that the create action dispatches.
  - PE-010-fake-pixelforge-driver: needed for E2E.
  - PE-006 and PE-007, through PE-009.
  - Prerequisite, done in bootstrap PR #6: a queue worker in the CI `e2e` job (see E2E below).
- **Decisions:** D-05 (local row first), D-11 (live status comes later) and D-14 (the hold is the max estimate). Requirements §2.1 (create params), §3 (estimate) and §6 (reference URLs are checked for shape only).
- **Thin controller** (project CLAUDE.md): Form Request → one Action → redirect. The Action recomputes the estimate with PE-002's PHP estimator. The client's figure is for display only.
- **Counting code points:**
  - Laravel's string `max` rule counts with `mb_strlen`, which is code points and matches Pixelforge.
  - HTML `maxlength` counts UTF-16 units, so it must not be what enforces the limit.
  - **Deferred from the PE-002 audit:** a prompt with invalid UTF-8 (PHP) or lone UTF-16 surrogates (TS) is counted differently by the two estimators. Server validation must reject such a prompt with a field error, never a 500 or a silently mismatched estimate.
  - The TS side counts with `[...prompt].length` (PE-002).
- **Trimming:** Laravel's `TrimStrings` middleware changes the prompt before validation, so the server may count a different string from the client. Either trim on both sides or exempt the prompt; the Architect picks (AC 7).
- **Reference rows:** the page's estimate and its submitted list must come from the same filtered rows (AC 3), so the displayed max is the hold. Whitespace around a URL doesn't change the price, which counts references, not URLs.
- **Display rounding:** 347,750,000, 457,150,000 and 58,150,000 n$ are exact ties at the fifth decimal ($0.34775, $0.45715 and $0.05815).
  - The strings above assume PE-005's `money.ts` rounds to the nearest $0.0001 with ties rounded up (half away from zero, PE-005 AC 12), consistent with requirements §3's `$0.3739`.
  - Truncation would show $0.3477, $0.4571 and $0.0581.
  - These values make good regression cases.
- **Double submit:** without a guard, a double click creates two generations and two holds. The guard must still allow a deliberate repeat (AC 8).
- **Throttle response:** Inertia shows a bare non-Inertia response, such as a plain 429, as an error modal. The throttle must therefore come back to the page as a validation-style message.
- **Dispatch failure:** if pushing the job fails after commit, the generation stays `pending` with its hold and shows Queued until PE-014 re-dispatches it.
- **E2E:**
  - The CI `e2e` job (`.github/workflows/ci.yml`) runs `worker` and `scheduler` containers next to `app`, with the same image, env and network (bootstrap PR #6). The submit job and the fake's lifecycle jobs therefore run in CI, and the fake's webhooks reach the app at `http://app:8080`. Locally, the `worker` service runs them.
  - Status is checked by reloading until the expected badge appears, because live updates arrive in PE-012.
  - Playwright runs fully parallel across the desktop and mobile projects, so each test registers its own user.
  - The fixtures fail a test on any browser console error.
- **Required test layers:**
  - PHP feature:
    - the validation matrix, including the emoji boundary (AC 1–5)
    - the happy path: row, estimate, hold and dispatched job (`Queue::fake`)
    - Unicode and whitespace estimate parity (AC 7)
    - double submit, throttle and guest redirects
    - owner scoping, pagination and the highlight flash (`assertInertia`)
    - a constant query count
  - JS unit: any new `lib/` helper, such as relative time.
  - JS integration (Vue Test Utils, Inertia mocked):
    - Generate button states, inline server errors and the throttle message
    - blank reference rows are left out of the submitted payload (AC 3)
    - the History list: empty, populated with each badge, highlighted and paginated
  - E2E (Playwright, fake driver): AC 17–19.
- **PR size:** about 700 changed lines with tests, which is over the ~400 budget. Split into two stories before planning, one PR each:
  - PE-011a: the History page, resource, TS types and nav, built on factory data (AC 12, 13, 15 and 16).
  - PE-011b: the submit endpoint, validation, guard, throttle, button wiring, blank-row filtering, highlight and E2E (AC 1–11, 14 and 17–19).

## Mockups / References
```text
GENERATE  (PE-005 page; this story wires the submit area)
┌────────────────────────────────────────────────────────────┐
│ Prompt                                                     │
│ ┌────────────────────────────────────────────────────────┐ │
│ │ A lighthouse on a cliff at dawn, oil painting          │ │
│ └────────────────────────────────────────────────────────┘ │
│ 45 / 4,000 characters                                      │
│ Size [1536×1024 ▾]   Quality [High ▾]   References (1/4)   │
│ Estimated cost  $0.3478 – $0.4572   (PE-005 breakdown)     │
│                                                            │
│                                         [   Generate   ]   │
└────────────────────────────────────────────────────────────┘

 In flight:     [ ⟳ Generating… ]   (disabled)
 Invalid:       [   Generate   ]    (disabled)
 Server error:  Prompt ┌──────────────────────────────┐
                       └──────────────────────────────┘
                ⚠ The prompt must be 4,000 characters or fewer.
                Reference 2 ┌─────────────────────────┐
                            │ ftp://example.com/a.png │
                            └─────────────────────────┘
                ⚠ Each reference must be an http(s) URL.
 Throttled:     ⚠ You're submitting too quickly. Try again in 42 s.

HISTORY                                       [ + New generation ]
┌────────────────────────────────────────────────────────────┐
│▌[Queued]     A lighthouse on a cliff at dawn, oil …        │ ← highlighted
│▌             High · 1536×1024 · 1 reference                │   (just submitted)
│▌             Est. $0.3478 – $0.4572           just now     │
├────────────────────────────────────────────────────────────┤
│ [Failed]     A [blocked] castle                            │
│              Medium · 1024×1024 · No references            │
│              Est. $0.0582 – $0.0863           3 min ago    │
├────────────────────────────────────────────────────────────┤
│ [Succeeded]  …                                             │
└────────────────────────────────────────────────────────────┘
               ‹ Previous    Page 1 of 3    Next ›

EMPTY
┌────────────────────────────────────────────────────────────┐
│                   No generations yet.                      │
│       Write a prompt and see the price before you pay.     │
│               [ Create your first image → ]                │
└────────────────────────────────────────────────────────────┘

MOBILE (stacked)
┌──────────────────────────┐
│ [Queued]        just now │
│ A lighthouse on a cliff… │
│ High · 1536×1024 · 1 ref │
│ Est. $0.3478 – $0.4572   │
└──────────────────────────┘
```
`money.ts` (PE-005) formats the amounts above. They only illustrate the format; the exact values are in the ACs.
- https://pixelforge.promptedit.dev/docs#create-generation: params, limits and the 4,000-character prompt
- https://pixelforge.promptedit.dev/docs#pricing: “Unicode characters, not bytes”
- https://pixelforge.promptedit.dev/docs#testing: `[blocked]`
- requirements.md §2.1, §3, §4, §6
