# Review — PE-012-live-status-results-and-failure-copy

## Round 1

**Verdict: REQUEST CHANGES**

Scope reviewed: the 18 `[PE-012]` commits `5b562e0..9953830` on `pe-012-live-status-results-and-failure-copy` (`origin/main..HEAD`), against `story.md` and `plan.md`. That is the plan, 15 task commits, the decisions and the bookkeeping. All 16 plan tasks are checked.

Checks run on HEAD (`9953830`):
- `make check` exits 0. Pint (231 files) and Larastan are clean. PHPUnit runs 1,229 tests (34,564 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 484 tests in 31 files, all passing.
- `make e2e` exits 0, with 36 passed and 2 skipped. The skips are the existing touch-only and mouse-only rows of `generate.spec.ts`, one per project, as designed.

There is no Critical finding. One Warning must change, and three optional Suggestions follow.

### Summary

The implementation follows the plan class for class, and its deviations from the story (one PR, server-side copy) are recorded in the plan's Story clarifications and in D-41 to D-43.

- **Resource (AC 7, 9, 11, 12):**
  - `GenerationResource` adds the seven fields in the planned order. The charge, the image fields and the expiry flag are gated on `Succeeded`, so D-09's failed row and a factory-failed row's stored 0 never reach the page. The tests pin both.
  - `image_expired` is `now() >= image_url_expires_at`. The 1 s-before and exactly-at rows pin AC 9's server half. The URL is withheld once expired, and `loadableUrl` rejects `javascript:` and `//host` URLs.
  - `utcIso()` has a conditional return type, so Larastan keeps `created_at` non-null. The resource still reads no relation, and the existing query-count row still holds.
  - No provider cost, rates, tokens or margin are sent. `305510000` is asserted absent from the whole JSON, including the D-09 row.
- **Failure copy (AC 8, D-43):** `GenerationFailureCode` is the single mapping. The local reason wins over `error_code`, unknown and missing codes read the generic message, and `test_every_local_failure_reason_has_its_copy` makes PE-013 and PE-014 add their copy when they add a reason. `validation_failed`'s three shapes are pinned. The message is rendered with text interpolation, and the `<b>x</b>` row proves it is never HTML.
- **Polling (AC 1–4, D-41):** `useLiveUpdates` chains `setTimeout` from each poll's `onFinish`, so polls never overlap. `onHttpException` and `onNetworkError` return `false`, so no error dialog appears. A cancel leaves `isPaused` alone, and the backoff table follows AC 4 exactly. Dispose clears the timer and cancels the in-flight token, and `isDisposed` stops a late `onFinish` from rescheduling. The unit tests drive every branch with fake timers.
- **Partial reload (AC 1, 3):** `server_time` sits inside the `generations` prop, so `only: ['generations']` re-measures the clock. `GenerationHistoryLiveUpdatesTest` reloads page 2 with no `Http::fake()`, so `preventStrayRequests` would throw on any outbound call, and it also asserts nothing was queued.
- **Highlight:** reading the flash once in setup is the right fix for `setPage` replacing it on a partial reload. `Index.test.ts` clears the flash before `setProps` to prove it.
- **Cards (AC 5–7, 9, 10):** the elapsed badge and expiry flip run on the server clock. ResultImage's box is sized before it loads. An expired image is never rendered as an `img`, and an `error` event latches "Image unavailable". Unchanged cards keep their root element across a poll (`toBe`).
- **E2E (AC 13–18):** the specs register their own users, use web-first assertions and `expect.poll` only, and mark the document to prove there was no reload. The timeouts are derived from the fake's named defaults. `[expire]` relies on the fixture's console-error check for the "no dead request" proof, as the story asks.

Security: History stays owner-scoped. The only provider-controlled strings that reach the page are the image URL, filtered by `loadableUrl`, and `validation_failed`'s param and message, rendered as text. The new link uses `rel="noopener noreferrer"`.

The constant-field check: no new field is constant under `ownedBy`. `server_time` is page-level, not per item.

### Commit hygiene

- Each task commit carries its code with its tests. The plan (`5b562e0`), the decisions (`0c39cb0`) and the bookkeeping (`9953830`) have their own commits.
- Task 6 loosens `submit.spec.ts`'s status regex in the same commit that adds the elapsed suffix, so no commit leaves E2E red.
- All subjects start with `[PE-012]`, are imperative and are at most 72 characters. Every message ends with the trailer. `7e4390a` has a body explaining the read-once flash, which is the one non-obvious change.
- The cut point after task 10 (`b27fc83`) is clean: no later commit is needed to make tasks 1–10 work.

### Warnings

- [x] **W1. Going Back to History restores a stale `server_time`, so the server clock runs behind for as long as the page stays open.**
  - `resources/js/composables/useServerClock.ts:23-30` re-measures the offset from whatever `server_time` the page mounts with. On a browser Back or Forward, Inertia 3.11 does not ask the server. `handlePopstateEvent` (`node_modules/@inertiajs/core/dist/index.js:1959-1997`) remounts the page from the props saved in `history.state` with `setQuietly(data, { preserveState: false })`. `server_time` is then as old as the user's time away, and `offsetMs` puts "now" that far in the past.
  - Example: a user lands on History, then opens Generate, waits 10 minutes and presses Back. Every item reads its created time 10 minutes too young, because `HistoryItem.vue:76` now uses `new Date(props.now)` where PE-011 used the browser clock. That is a regression of PE-011's AC 13 time. If the page has an in-progress item, the first poll after 3 s corrects the clock. If every item is terminal, nothing polls (AC 2), so the clock stays wrong until the page is left.
  - The same stale clock defeats D-42's guarantee that "a page never requests an expired URL". If an image URL dies while the user is away (24 h in production, 5 s for `[expire]`), the restored props still say `image_expired: false` with the URL. `isImageExpired(…, now)` at `HistoryItem.vue:83-85` reads the stale clock, so the card renders the `img`. The browser then requests the dead URL, which fails with a 403 and a console error, and the card shows "Image unavailable" instead of AC 9's "Image expired".
  - Why it matters: AC 6 and AC 9 say these times are measured against the server's clock, and D-42 says a skewed clock "can't keep a dead image up". A restored snapshot is a clock that is off by the time away, and nothing re-measures it.
  - Fix (the engineer may choose the mechanism):
    - One option: the offset belongs to the browser and server pair, not to a page, so keep it at module level in `useServerClock.ts`. Re-measure it only from a `server_time` newer than the newest one seen so far. A restored, older snapshot then keeps the offset measured from the last fresh response, which needs no extra request and keeps AC 2's "never polls" literally true.
    - Document the remaining gap in the composable's docblock and in D-42: a restore into a freshly loaded document has no fresh offset yet.
    - Alternatively, refresh `generations` once on a history restore, and render no image until that answer arrives.
  - Tests:
    - a `useServerClock.test.ts` row: after one fresh `server_time`, mounting again with an older one, as a restore does, keeps the server-aligned "now";
    - an `Index.test.ts` row: a remount with restored props, whose image expired while the user was away, shows "Image expired" and renders no `img`;
    - an E2E row in `live-status.spec.ts`, if it stays cheap: `[expire]` reaches Succeeded, the user goes to Generate, waits past `FAKE_EXPIRE_TTL_MS` with `expect.poll` rather than a sleep, then goes Back. The card reads "Image expired", and the console-error fixture shows no dead request.
  - Commit it on its own, for example `[PE-012] Keep the server clock when History is restored from history`.

### Suggestions

Optional; none blocks QA.

- **S1.** `app/Http/Resources/GenerationResource.php:93`: `/\host/a.png` passes the root-relative check, but browsers parse `\` as `/` in http(s) URLs, so it loads from `host`, the same as `//host`. It is not a `javascript:` vector, and `https://` to any host is already allowed, so the risk is low. Rejecting a second character of `\` (`str_starts_with($url, '/\\')`) would make the check match its docblock. A data-provider row would pin it.
- **S2.** `resources/js/components/history/HistoryItem.vue:15-23`: the docblock was extended in place and now wraps unevenly. Line 18 breaks after "and the" at about 60 characters, while its neighbours run to about 90. Rewrap it, or leave it.
- **S3.** `resources/js/components/history/ResultImage.vue`: an `error` latches `unavailable` for good, as the plan intends. If PE-013 or a later story ever re-signs a URL, the card will need a remount, or a reset when `src` changes, to show the new image. It is recorded here so that story doesn't trip on it. No change is needed now.

### Resolution (engineer, round 1)

- **W1:** fixed in `415796d`. The offset is kept at module level in `useServerClock.ts` and re-measured only from a `server_time` newer than any seen, so a restored snapshot keeps the last fresh offset and nothing extra is requested. Covered by a `useServerClock.test.ts` row, an `Index.test.ts` remount row and a `live-status.spec.ts` Back row; all three fail on the previous clock. The E2E row reads the card once as soon as the restored page shows, because a stale clock only flips the card a few seconds later. The remaining gap (a restore into a freshly loaded document) is documented in the composable's docblock and in D-42 (`bbe1f8e`).
- **S1:** done in `1e8de89`, with a data-provider row.
- **S2:** done in `5b2167d`.
- **S3:** no change, as noted.

## Round 2

**Verdict: APPROVE**

Scope reviewed: the whole story diff, `origin/main...HEAD` (the 23 `[PE-012]` commits `5b562e0..2ddb37a`), with a close read of round 1's fix commits `415796d`, `bbe1f8e`, `1e8de89`, `5b2167d` and the bookkeeping commit `2ddb37a`.

Checks run on HEAD (`2ddb37a`):
- `make check` exits 0. Pint (231 files) and Larastan are clean. PHPUnit runs 1,230 tests (34,575 assertions), all passing. ESLint, Prettier and vue-tsc are clean. Vitest runs 487 tests in 31 files, all passing.
- `make e2e` exits 0, with 38 passed and 2 skipped. The skips are the same touch-only and mouse-only rows of `generate.spec.ts` as in round 1. The new Back row passes on both the desktop and the mobile project.

There is no Critical finding and no open Warning.

### Round 1 findings, verified

- **W1 (fixed in `415796d`, documented in `bbe1f8e`).**
  - `resources/js/composables/useServerClock.ts:11-12` keeps `offsetMs` and `newestServerMs` at module level. Lines 39-42 re-measure the offset only from a `server_time` newer than any seen. A Back restore carries the same or an older `server_time`, so it keeps the last fresh offset and makes no extra request. AC 2's "never polls" therefore still holds for an all-terminal page.
  - Module-level state is safe here because the app has no SSR entry, so no server process shares the module across requests. An unparsable time gives `NaN`, which fails the `>` check and leaves the offset alone.
  - The three tests each fail on the old per-instance clock:
    - `useServerClock.test.ts`: the restored row, plus a row proving a newer time still re-measures.
    - `Index.test.ts:307-326`: a remount with stale props renders no `img` and reads "Image expired".
    - `live-status.spec.ts:128-149`: it reads the card once, as soon as the restored page shows, so a clock that flips late can't pass by accident.
  - `forgetServerClock()` is a test-only reset, and its docblock says so. Every suite that mounts the clock calls it in `beforeEach`, so no state leaks between cases.
  - D-42 and the composable's docblock record the remaining gap: a restore into a freshly loaded document has no fresh offset until its first poll.
- **S1 (done in `1e8de89`).** `GenerationResource.php:94` also rejects `/\…`. The docblock matches the code, and a data-provider row (`GenerationHistoryStatusTest.php:140`) pins the new case.
- **S2 (done in `5b2167d`).** The docblock at `HistoryItem.vue:14-25` now wraps evenly. The commit changes only the comment.
- **S3.** No change is needed, as noted in round 1.

### Whole-diff pass

This pass re-read the production files against the ACs: `GenerationResource`, `GenerationFailureCode`, `ShowGenerationHistoryController`, `useLiveUpdates`, `useServerClock`, `generationTiming`, `HistoryItem`, `ResultImage`, `LiveUpdatesNote` and `Index`. It found nothing new.
- The charge, the image fields and the expiry flag are still gated on `Succeeded`, and `failure_message` on `Failed`.
- Copy is rendered by interpolation only.
- Polls never overlap, and dispose cancels a poll in flight.
- The expired `src` is nulled before ResultImage sees it.

### Commit hygiene

- Round 1's fixes are four focused commits, each with its tests where there is code:
  - the W1 code and its three test layers (`415796d`);
  - the decision's wording (`bbe1f8e`), kept apart like task 16's decisions commit;
  - the S1 code and its row (`1e8de89`);
  - the S2 comment-only rewrap (`5b2167d`).
- The bookkeeping is in its own commit (`2ddb37a`).
- All subjects start with `[PE-012]`, are imperative and are at most 72 characters. Every commit ends with the trailer. `415796d` and `1e8de89` have short bodies explaining the non-obvious why.
- No earlier commit was rewritten.

### Suggestions

Optional; none blocks QA.

- **S4.** `tests/e2e/live-status.spec.ts:140`: the `expect.poll` on `Date.now()` is a time wait, not a wait for state. While the user is on Generate, nothing on the page changes, so this is the honest form. It is bounded by `EXPIRY_TIMEOUT_MS`, which is derived from the fake's TTL. If PE-013 or PE-014 adds more away-and-Back rows, a named helper in `support/liveStatus.ts`, such as `waitPastExpiry(succeededAt)`, would keep that intent in one place. No change is needed now.
