# Story: PE-012-live-status-results-and-failure-copy

## Title
Live status, image and actual cost in history; failures explained

## Status
`planned`

## Description
After submitting, the user watches a generation move from Queued to Processing to Succeeded or Failed without reloading.

- **On success**, they see the image and the amount actually charged, next to the estimate they saw.
- **On failure**, they see in plain words what went wrong and that nothing was charged.
- **When the image has expired**, they see a clear placeholder instead of a broken image.

History updates by polling only our own server (D-11), so the UI never touches Pixelforge's 60 rpm account limit.

This completes the required feature “history with live status, estimated and actual cost and the image”. It is the heart of grading criterion #3: it is clear what things cost, what is happening and what went wrong. It also makes criteria #1 and #2 visible to the user: there is one charge, and a failed generation is free.

## Acceptance Criteria
**Lighthouse**, succeeded with the docs usage, is the main example:
- its estimate is 347,750,000–457,150,000 n$, shown as `Est. $0.3478 – $0.4572`;
- its actual user charge is 381,887,500 n$ ($0.381887500), shown as `Charged $0.3819`.

Keywords count toward the prompt's characters, so E2E prompts have their own estimates. For example, `[succeed] A lighthouse on a cliff at dawn, oil painting` is 55 code points (e = 14, bounds 11–17). Its estimate is 347,756,250–457,168,750 n$, which still displays as `Est. $0.3478 – $0.4572`.

**Live updates**
1. While any generation on the current History page is non-terminal (`pending`, `queued` or `processing`), the page re-requests only the generations prop from our server every 3 s (configurable).
   - It keeps the current page, scroll position and highlight.
   - Cards whose data did not change are not re-mounted.
2. Polling stops as soon as every visible generation is terminal, or when the user leaves the page: no request is made after unmount. A page whose generations are all terminal never polls.
3. Polls never call Pixelforge. The prop is served from our DB alone, and a feature test proves this with stray HTTP requests prevented.
4. If a poll fails (a network error or a 5xx):
   - the last known state stays on screen;
   - a subtle “Live updates paused — retrying…” note appears;
   - retries back off to 6 s, 12 s and 24 s, then every 30 s;
   - after the next successful poll, the note disappears and the interval returns to 3 s.

**Per-status presentation**
5. A `pending` or `queued` generation shows a Queued badge.
6. A processing generation shows `Processing · 14 s`: the time elapsed since `started_at`, ticking every second. The time is measured against the server's clock, so a browser clock 2 minutes fast still shows 14 s.
7. A succeeded generation shows the following, with every amount formatted by `money.ts`:
   - a thumbnail that opens the full-size image in a new tab, with the prompt as alt text;
   - `Charged $0.3819`, with the exact `$0.381887500` on hover and on keyboard focus;
   - `Est. $0.3478 – $0.4572` next to the charge;
   - the completed time.
8. A failed generation shows a Failed badge, “Not charged”, its estimate and one message. A single tested mapping picks the message from the failure code, which is either a local reason from PE-009 or the provider's `error.code`:

| Failure code | Message |
|---|---|
| `content_policy_violation` | “Prompt rejected by Pixelforge's content policy. Edit the prompt and try again.” |
| `validation_failed` | “Pixelforge rejected the request: {param}: {message}” |
| `idempotency_key_reused` | “Something went wrong submitting this generation.” |
| `generation_failed` | “Pixelforge couldn't produce an image for this prompt. Try rephrasing it.” |
| `internal_error` | “Something went wrong on Pixelforge's side. Please try again.” |
| any other or missing code | “This generation failed.” |

9. An image counts as expired when the server's time is at or after `image_url_expires_at`; one second before, it does not.
   - An expired card shows an “Image expired” placeholder with “Images are kept for 24 h after completion”, and never requests the URL.
   - The server computes the expired flag.
   - If expiry passes while the page is open, a client timer flips the card without a reload. The timer is measured against the server's clock.
10. If an image that has not expired fails to load, the card shows “Image unavailable” instead of a broken-image icon.
11. A generation that failed locally but later succeeded at the provider (D-09) shows Failed and “Not charged”, with no image and no charge.
12. The resource never exposes provider cost or margin. The actual charge is present only for succeeded generations, and null otherwise.

**E2E (fake Pixelforge, no reloads)**
13. `[succeed] A lighthouse on a cliff at dawn, oil painting` goes from Queued or Processing to Succeeded live. The image is visible, and the displayed charge lies within the displayed estimate.
14. `[fail] A lighthouse on a cliff at dawn, oil painting` goes to Failed live, with the `generation_failed` copy and “Not charged”.
15. `A [blocked] castle` goes to Failed live, with the content-policy copy and “Not charged”.
16. `[duplicate] A lighthouse on a cliff at dawn, oil painting` succeeds as exactly one History item, with one charge that lies within its estimate.
17. A `[slow] …` prompt stays in Processing while polling continues, with the elapsed time higher at a second observation than at the first. It then reaches Succeeded.
18. An `[expire] …` prompt shows “Image expired” once its short TTL passes, with no reload and no browser console error.

## Scope

### In Scope
- Polling of the generations prop, using Inertia's built-in poll helper or a small composable. It starts, stops, cleans up on unmount and backs off after errors.
- New resource fields, each with a matching TS type:
  - the image URL;
  - the expiry timestamp and the server-computed expired flag;
  - the actual charge (n$);
  - `started_at` and `completed_at`;
  - the failure code and message data;
  - the server's current time, for client timers.
- Result card states, with consistent status badges: queued, processing (with elapsed time), succeeded, failed, expired and unavailable.
- One failure-copy mapping kept in one place (server or TS, as the Architect chooses), so PE-013 and PE-014 only have to append codes.
- The Playwright flows in AC 13–18.

### Out of Scope
- Copy for retrying, waiting for a free slot, daily capacity, overload and service unavailable: PE-013-provider-limits-retries-and-throttle. Copy for stuck and lost generations: PE-014-reconciliation-poller. Both append to the mapping.
- Balance and held-amount display: PE-017-credit-balances-buy-credits.
- A detail page, retry or regenerate buttons, and downloading or re-hosting images past 24 h.
- Websockets or server push, because D-11 chose polling.
- Pausing polling in background tabs, and giving up on generations stuck non-terminal. PE-013 and PE-014 resolve stuck generations.

## Technical Notes
- **Depends on:**
  - PE-011-submit-generation-and-history: the History page, the generation resource and the E2E setup, including the CI worker prerequisite.
  - PE-010-fake-pixelforge-driver: the keywords, `[expire]` and short timings.
- **Decisions:**
  - D-11: poll our own server.
  - D-09: AC 11.
- **Requirements:**
  - §2.4: failed `error.code` values.
  - §2.6: 24 h image expiry.
  - §4: the “User sees” column.
  - §6: the code that `[fail]` produces is unconfirmed, so handle both `generation_failed` and `internal_error`.
- **Partial reloads** must not reset pagination, scroll position or the post-submit highlight. Timers must be cleaned up on unmount.
- **Never request the dead URL:** the E2E fixtures fail on any browser console error, and Chromium logs a failed image load as one. That is why AC 10 is proven in JS integration only.
- **One copy mapping:** every failure string lives in the single mapping. It is keyed by the failure code spelled exactly as in PE-009's failure-reason enum and Pixelforge's `error.code`. Later stories only append:
  - PE-013 adds the local reasons `daily_limit`, `provider_overloaded`, `provider_busy` and `service_unavailable`. It also adds the non-terminal “Pixelforge is busy — retrying automatically” and “Waiting for a free slot” notes.
  - PE-014 adds the local reason `lost` and the “Taking longer than usual — we're checking with Pixelforge” note.
- **Money:** amounts cross the wire as integer n$, and only `money.ts` formats them. If the actual charge exceeds the estimate because rates changed (§6), the card just shows the actual; no special copy is needed.
- **Images:**
  - In production, the browser loads them straight from the signed URL; in dev and E2E it loads the fake's placeholder.
  - Loading an image is not an authenticated API call, so it does not touch the 60 rpm budget.
- **What E2E proves about money:** E2E proves one History item and a charge within the estimate. The UI shows the generation's own charge, not ledger sums. Exactly-once ledger writes under `[duplicate]` are therefore proven in PHP (PE-008 AC 9–11, PE-010 AC 22), not here.
- **E2E timing:** use Playwright's polling assertions, with timeouts derived from the fake's dev defaults, and never fixed sleeps.
- **Required test layers:**
  - PHP feature:
    - resource shape for each status;
    - the expired flag one second before and exactly at `image_url_expires_at`;
    - failure data for each code;
    - no provider cost in the props;
    - the partial-reload response (`assertInertia` on a partial reload of the generations prop);
    - no outbound HTTP.
  - JS unit (Vitest, fake timers):
    - the polling logic: starts when anything is non-terminal, stops when everything is terminal and on unmount, follows the AC 4 backoff and recovers;
    - the failure-copy mapping, if it lives in TS;
    - the expiry timer and the elapsed time, both measured against server time with a skewed browser clock;
    - elapsed-time formatting.
  - JS integration (Vue Test Utils):
    - the result card in every state: queued, processing, succeeded (with the exact value on hover and focus), each failure code, expired, image error and D-09;
    - the paused-updates note;
    - unchanged cards not re-mounting on a poll.
  - E2E (Playwright, fake driver): AC 13–18 on the desktop and mobile projects.
- **PR size:** about 700 changed lines with tests, which is over the ~400 budget. Split into two stories before planning, one PR each:
  - PE-012a: polling (AC 1–4); the queued, processing and succeeded cards (AC 5–7, 12); E2E AC 13 and 17.
  - PE-012b: the failure-copy mapping and D-09 (AC 8, 11); the expired and unavailable states (AC 9–10); E2E AC 14–16 and 18.

## Mockups / References
```text
HISTORY — live (polls our server every 3 s while anything is in progress)
┌──────────────────────────────────────────────────────────────────┐
│ [Queued]                    A lighthouse on a cliff at dawn, …   │
│                             High · 1536×1024 · 1 reference       │
│                             Est. $0.3478 – $0.4572    just now   │
├──────────────────────────────────────────────────────────────────┤
│ [Processing · 14 s]         A lighthouse on a cliff at dawn, …   │
│                             Est. $0.3478 – $0.4572               │
├──────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ [Succeeded]  A lighthouse on a cliff at dawn, …   │
│ │   image    │ Charged $0.3819     ← hover/focus: $0.381887500   │
│ │  (opens ↗) │ Est. $0.3478 – $0.4572                            │
│ └────────────┘ Completed 14:03                                   │
├──────────────────────────────────────────────────────────────────┤
│ [Failed] Not charged        A [blocked] castle                   │
│   Prompt rejected by Pixelforge's content policy.                │
│   Edit the prompt and try again.                                 │
│                             Est. $0.0582 – $0.0863               │
├──────────────────────────────────────────────────────────────────┤
│ [Failed] Not charged        [fail] A lighthouse on a cliff …     │
│   Pixelforge couldn't produce an image for this prompt.          │
│   Try rephrasing it.                                             │
│                             Est. $0.3478 – $0.4572               │
├──────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ [Succeeded]  …                                    │
│ │   Image    │ Charged $0.3819                                   │
│ │  expired   │ Images are kept for 24 h after completion.        │
│ └────────────┘                                                   │
├──────────────────────────────────────────────────────────────────┤
│ ┌────────────┐ [Succeeded]  …                                    │
│ │   Image    │ Charged …                                         │
│ │ unavailable│                                                   │
│ └────────────┘                                                   │
└──────────────────────────────────────────────────────────────────┘

POLL ERROR (last known state kept on screen)
  ⟳ Live updates paused — retrying…

MOBILE (stacked card)
┌──────────────────────────┐
│ ┌──────────────────────┐ │
│ │        image         │ │
│ └──────────────────────┘ │
│ [Succeeded]       14:03  │
│ Charged $0.3819          │
│ Est. $0.3478 – $0.4572   │
└──────────────────────────┘
```
The amounts in the wireframe are formatted by `money.ts` (PE-005) and only illustrate the format. The exact values are in the ACs.
- https://pixelforge.promptedit.dev/docs#generation-object: the lifecycle and the failed `error.code` values
- https://pixelforge.promptedit.dev/docs#images: signed URLs expire 24 h after completion, and refetching does not extend them
- https://pixelforge.promptedit.dev/docs#rate-limits: why the UI polls our server, never Pixelforge
- https://pixelforge.promptedit.dev/docs#testing: `[succeed]`, `[fail]`, `[blocked]`, `[slow]`, `[duplicate]`
- requirements.md §2.4, §2.6, §4, §5 (D-09, D-11), §6
