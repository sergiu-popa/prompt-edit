# QA — PE-010-fake-pixelforge-driver

**Verdict: QA PASSED**

Every AC maps to a passing test, or to a probe run in this pass. Review round 1 is APPROVE with no Critical or Warning items, so nothing auto-fails. Status moves to `qa-passed`.

Prerequisites checked: story status `qa-ready`, review verdict `APPROVE` (round 1). Branch `pe-010-fake-pixelforge-driver` in the isolated worktree, clean at the start, HEAD `c38982a`. Checks ran only in the `prompt-edit-chain` container. The main stack was not touched.

Two items are deferred to the orchestrator, not failed here: the E2E run (`make e2e`, AC 24) and the browser load of the placeholder PNG (AC 20). See *Visual verification*.

## Suite

| Check | Command | Result |
| --- | --- | --- |
| Full check | `chain-make.sh chain check` | Exit 0. Pint and Larastan clean. PHPUnit 1,082 passed (33,565 assertions), none failed. ESLint, Prettier and vue-tsc clean. Vitest 107 passed (9 files). |

Log: `/private/tmp/claude-501/-Users-sergiu-Code-prompt-edit/a37effb5-ef14-4542-a0cd-a0d99bb692ed/scratchpad/PE-010/check.log`.

## Acceptance criteria

Test names are in `tests/Feature/Pixelforge/Fake/*`, `tests/Feature/Pixelforge/*`, `tests/Feature/Production/*`, `tests/Feature/Console/GenerateLocalWebhookSecretCommandTest.php`, `tests/Unit/Support/Pixelforge/Fake/*` and `tests/Unit/LocalEnvTemplateTest.php`, abbreviated to the method name. Mapping is by test name and the passing check run; the test bodies were not re-read line by line in this pass.

| AC | Covered by | Result |
| --- | --- | --- |
| 1 `PIXELFORGE_DRIVER=fake` binds the fake | `FakePixelforgeDriverTest::test_the_fake_driver_binds_a_fresh_fake_client` | Pass |
| 2 production + fake refuses to boot, naming the setting | `FakePixelforgeDriverTest::test_the_fake_driver_is_refused_in_production`; `RefusesFakePixelforgeInProductionTest::test_production_with_the_fake_driver_refuses_to_boot` (subprocess); `test_any_other_combination_boots`. Probe below | Pass |
| 3 image route 404 when fake inactive | `FakePixelforgeImageTest::test_the_image_is_not_found_while_the_fake_is_inactive`; `BootsWithoutPixelforgeTest::test_the_fake_image_route_is_not_found` | Pass |
| 4 no secret: clear error, nothing signed with empty key | `FakePixelforgeCreateTest::test_without_a_webhook_secret_nothing_is_created`; `FakeWebhookSenderTest::test_without_a_secret_nothing_is_sent` | Pass |
| 5 create returns docs-shaped `queued` snapshot | `FakePixelforgeCreateTest::test_a_create_stores_the_docs_generation_object_queued`; `test_the_answer_is_the_queued_generation_never_replayed`; `test_no_references_are_echoed_as_an_empty_list`; `test_a_unicode_prompt_is_echoed_unchanged` | Pass |
| 6 same key + same params: same generation, `replayed` true, no new lifecycle or webhooks | `FakePixelforgeCreateTest::test_the_same_key_and_body_replays_the_stored_generation`; `FakePixelforgeLifecycleTest::test_a_replay_after_the_lifecycle_answers_the_generation_as_it_is_now` | Pass |
| 7 same key + other params: typed `idempotency_key_reused` | `FakePixelforgeCreateTest::test_the_same_key_with_other_params_is_refused` (data provider `changedParams`) | Pass |
| 8 state shared across app instances | `FakePixelforgeReplayAcrossInstancesTest::test_a_fresh_app_instance_replays_the_generation_another_one_created` | Pass |
| 9 `[blocked]` (any case): `content_policy_violation`, nothing created | `FakePixelforgeRejectionsTest::test_a_blocked_prompt_is_refused_and_creates_nothing` (data provider `blockedSpellings`); `FakeKeywordsTest::test_each_keyword_is_found_anywhere_in_any_case` | Pass |
| 10 `[overloaded]`: `overloaded`, Retry-After 2, nothing created | `FakePixelforgeRejectionsTest::test_an_overloaded_prompt_is_refused_with_retry_after_and_creates_nothing` | Pass |
| 11 `[fail]`: processing then `generation_failed`, unbilled | `FakePixelforgeLifecycleTest::test_a_fail_generation_starts_processing_then_fails_unbilled` | Pass |
| 12 `[succeed]` never fails at random under forced chaos | `FakePixelforgeChaosTest::test_succeed_never_fails_at_random` | Pass |
| 13 `[slow]` stays processing for the slow duration | `FakePixelforgeLifecycleTest::test_a_slow_generation_stays_processing_for_the_slow_duration`; `PixelforgeConfigTest::test_the_default_slow_duration_exceeds_normal_processing` | Pass |
| 14 `[duplicate]`: every webhook twice, same event id and body, fresh `t` each | `FakePixelforgeLifecycleTest::test_a_duplicate_generation_delivers_every_webhook_twice`; `FakeWebhookSenderTest::test_a_duplicate_generation_sends_one_event_twice_each_freshly_signed` | Pass |
| 15 precedence `[blocked]` > `[overloaded]` > `[fail]`; `[slow]`, `[duplicate]`, `[expire]` combine | `FakeKeywordsTest::test_blocked_comes_before_overloaded_before_fail`; `test_fail_then_succeed_decides_the_outcome`; `FakePixelforgeRejectionsTest::test_a_rejection_wins_over_any_later_keyword` and `combinedRejections`; `FakePixelforgeLifecycleTest::test_fail_combines_with_slow_and_duplicate`; `test_slow_duplicate_and_expire_combine_with_success` | Pass |
| 16 chaos off by default; each chaos outcome producible | Default: `FakePixelforgeChaosTest::test_with_default_settings_nothing_happens_at_random` (200 creates). Forced: `test_forced_overload_answers_503_and_stores_nothing`, `test_forced_internal_error_fails_unbilled_with_the_docs_message`, `test_forced_duplicates_send_every_webhook_twice`. Configured rate: `PixelforgeConfigTest::test_chaos_is_off_by_default`, `test_one_chaos_percent_feeds_every_chaos_outcome`; `FakeChaosTest` (0 and 100 short-circuit, 50 strikes sometimes) | Pass |
| 17 queue delay then `processing` with `started_at`; then terminal with `completed_at`; configurable | `FakePixelforgeLifecycleTest::test_a_create_holds_the_generation_queued_for_the_queue_delay`; `test_after_the_queue_delay_it_starts_processing_and_says_so`; `test_after_the_processing_delay_it_succeeds_and_says_so`; `PixelforgeConfigTest::test_set_fake_values_apply` | Pass |
| 18 usage bounds (Lighthouse and any prompt) | `FakeUsageGeneratorTest::test_a_lighthouse_always_uses_the_docs_counts_within_its_estimate`; `test_any_payload_follows_the_docs_rules_within_its_estimate`; `test_every_pinned_fixture_case_is_charged_within_its_expected_price`; `FakePixelforgeLifecycleTest::test_a_succeeded_lighthouse_reports_usage_within_its_estimate` | Pass |
| 19 usage reproducible per generation | `FakeUsageGeneratorTest::test_a_key_always_gives_the_same_exact_usage`; `test_the_same_payload_twice_gives_equal_usage`; `test_different_keys_give_different_usage` | Pass |
| 20 `image_url` is the placeholder PNG; expiry at `completed_at` + TTL; `[expire]` short TTL | `FakePixelforgeLifecycleTest::test_a_succeeded_image_url_is_root_relative_loads_and_lives_a_day`; `test_an_expire_generation_image_dies_within_seconds`; `FakePixelforgeImageTest::test_an_expired_url_is_refused`, `test_a_tampered_signature_is_refused`, `test_a_url_for_another_generation_is_refused`, `test_a_missing_signature_is_refused`; `PixelforgeConfigTest::test_a_zero_ttl_means_the_default` | Pass (feature level). Browser load deferred, see below |
| 21 webhook headers, `evt_` envelope, PE-008 signature over exact body | `FakeWebhookSenderTest::test_a_webhook_is_posted_in_network_with_the_docs_headers`; `test_the_body_is_the_docs_envelope_around_the_snapshot`; `test_the_signature_is_pe_008s_over_the_exact_body_at_now`; `test_a_custom_base_url_is_used_without_a_doubled_slash` | Pass |
| 22 round trip through the real endpoint, ledger hold/release/charge | `FakePixelforgeRoundTripTest::test_a_lighthouse_settles_through_the_real_webhook_endpoint`; `test_a_duplicate_lighthouse_is_charged_once`; `test_a_lighthouse_duplicated_by_chaos_is_charged_once_within_its_estimate` | Pass |
| 23 failed delivery logged, fake state correct, no redelivery | `FakeWebhookSenderTest::test_a_failed_delivery_is_logged_once_and_never_thrown`; `FakePixelforgeLifecycleTest::test_a_failed_delivery_never_stops_the_lifecycle_or_repeats_a_webhook` | Pass |
| 24 `.env.example` defaults to fake and in-network URL; setup writes a local secret; worker runs jobs; `make e2e` green | `.env.example` (verified): `PIXELFORGE_DRIVER=fake`, `PIXELFORGE_FAKE_WEBHOOK_URL=http://app:8080`, `PIXELFORGE_WEBHOOK_SECRET=` empty. `LocalEnvTemplateTest::test_it_holds_the_local_value`, `test_secrets_are_left_blank`. `GenerateLocalWebhookSecretCommandTest` (6 tests, including production refused and an existing secret kept). `Makefile` `setup` runs `webhook-secret` then `restart-worker`; `docker-compose.yml` has the `worker` service. **`make e2e` not run here** (deferred to the orchestrator) | Pass for the env and setup parts. `make e2e` deferred |

## Probes

Run in this pass, read-only, no rows written, nothing committed.

- **AC 2, production + fake.** `APP_ENV=production PIXELFORGE_DRIVER=fake php artisan about --only=environment` exits 1 at boot with `PixelforgeNotConfiguredException: PIXELFORGE_DRIVER=fake is not allowed in production.` (`app/Support/Pixelforge/Exceptions/PixelforgeNotConfiguredException.php:40`). The control, `APP_ENV=production PIXELFORGE_DRIVER=http`, boots and prints the environment block.
- **AC 2, the guard runs before any command.** The refusal is raised from `AppServiceProvider` in `boot()`, so it applies to artisan and HTTP alike. The subprocess test covers the HTTP side.
- **Stray requests.** `Http::preventStrayRequests()` is set in `tests/TestCase.php:21`. `PixelforgeConfigTest` and `phpunit.xml` force `PIXELFORGE_API_KEY` to empty and `PIXELFORGE_DRIVER` to `http` (`phpunit.xml:34-36`). Every `pixelforge.promptedit.dev` hit under `tests/` is a fixture string, not a request.
- **Lighthouse prompt.** `mb_strlen` gives 45 code points, so e = 12 and the text bounds are 10 to 14, as AC 18 states.

## Regressions

The full PHPUnit suite passes (1,082 tests), including the PE-006 to PE-009 ledger, transition, webhook and submit tests. Vitest, Larastan, Pint, ESLint, Prettier and vue-tsc all pass.

## Visual verification

**No UI in this story** (`story.md`: "No UI"; the placeholder PNG is test infrastructure). No screens, screenshots, horizontal-scroll or console checks apply, and none were attempted. The image route is covered at the HTTP feature level.

Deferred to the orchestrator, not run here:
- **E2E.** `make e2e` (AC 24, the bootstrap welcome smoke test) runs on the main stack. The orchestrator re-runs the E2E suite there.
- **Browser load of `image_url`** (AC 20, dev `http://prompt-edit.localhost` and E2E `http://127.0.0.1:8080`). No browser was available in this pass.

Environment note (non-blocking): the chain worktree's gitignored `.env` has only `PIXELFORGE_API_KEY`. It has no `PIXELFORGE_DRIVER` line, so the container resolves the driver to `http` (`php artisan tinker` reported `driver: http`). The tests are unaffected because `phpunit.xml` forces `http`. The main stack's `.env` needs `PIXELFORGE_DRIVER=fake` (and a generated `PIXELFORGE_WEBHOOK_SECRET`) before the E2E run, which `make setup` produces for a fresh checkout.

No fixture rows were written to any shared database. Every test ran in the isolated `prompt_edit_test_chain` database.

## Review suggestions still open

Optional, from review round 1. None is Critical, so none blocks QA.

- **S1** (process): `520ca16` alone failed `stan`, fixed by `5c7b3ca`. Resolved before the PR was opened: the orchestrator squashed `5c7b3ca` into `520ca16`, which is now `839ece9` and passes `stan` on its own. The SHAs in these notes have been updated to the rewritten history.
- **S2**: `FakePixelforgeClient` passes `firstOrCreate` an eager array, so the estimate and chaos rolls run on every replay. Behaviour is correct. Closure form would match the `FakeChaos` docblock.
- **S3**: `AdvanceFakeGenerationJob` dispatches the next step after `deliver()`. A throw from `deliver()` (only `WebhookSecretNotConfiguredException`, if the secret is cleared mid-flight) would leave the row in `processing`.
- **S4**: `FakeKeywords` docblock sentence does not parse.

## Notes (non-blocking)

- Keyword and chaos behaviour was verified by test name against the story, not by a scratch probe. The existing suite already covers each keyword, combination, and the 200-create default-chaos case.
- The AC 2 guard location (`AppServiceProvider::boot()` and the `fake` arm, lines 53 and 65) was checked in the source, not only in the review.
