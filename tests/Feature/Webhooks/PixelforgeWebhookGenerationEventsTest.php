<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Domain\Generation\DataTransferObjects\LocalGenerationFailureData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * Generation events hand their snapshot to the single transition action, once
 * per event id, so PE-007's ledger settles or releases exactly once.
 */
final class PixelforgeWebhookGenerationEventsTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    private const array LIGHTHOUSE_CHARGED = [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]];

    public function test_a_succeeded_event_charges_the_lighthouse_its_actual_cost_and_records_the_event(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $event = self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse));

        $this->deliverPixelforgeEvent($event)->assertOk();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame(305_510_000, $row->provider_cost_nanodollars);
        $this->assertSame(381_887_500, $row->user_charge_nanodollars);
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(-381_887_500, $this->ledgerNetFor($lighthouse));
        $this->assertSame([[
            'event_id' => $event['id'],
            'type' => 'generation.succeeded',
            'delivery_attempt' => 1,
            'generation_id' => $lighthouse->id,
            'received_at' => '2026-11-19 00:00:10+00',
        ]], $this->storedWebhookEvents());
        $this->assertSame([], $this->loggedMessages());
        Http::assertNothingSent();
    }

    public function test_the_same_event_again_later_or_as_a_dashboard_resend_is_a_no_op(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $body = json_encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)), JSON_THROW_ON_ERROR);
        $this->deliverPixelforgeWebhook($body)->assertOk();
        $after = $this->rawGenerationRow($lighthouse);

        $this->travel(10)->seconds();
        $this->deliverPixelforgeWebhook($body)->assertOk();
        $this->travel(1)->hours();
        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Delivery-Attempt' => '2'])->assertOk();

        $this->assertSame($after, $this->rawGenerationRow($lighthouse));
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
        $events = $this->storedWebhookEvents();
        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]['delivery_attempt']);
        $this->assertSame([], $this->loggedMessages());
    }

    public function test_two_event_ids_with_the_same_succeeded_snapshot_charge_once(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $object = self::lighthouseSucceededObjectFor($lighthouse);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $object))->assertOk();
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $object))->assertOk();

        $this->assertSame([$lighthouse->id, $lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
    }

    /**
     * The webhook can land before our `POST /v1/generations` returns: the row
     * is matched by our ULID and learns its provider id from the event.
     */
    public function test_a_succeeded_event_before_the_create_response_is_matched_by_our_ulid(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->assertNull($lighthouse->provider_id);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)))->assertOk();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Succeeded, $row->status);
        $this->assertSame('gen_' . strtoupper($lighthouse->id), $row->provider_id);
        $this->assertSame(381_887_500, $row->user_charge_nanodollars);
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));
    }

    #[DataProvider('providerFailures')]
    public function test_a_failed_event_is_free_and_releases_the_hold(string $code, string $message): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $object = self::generationObjectFor($lighthouse, GenerationStatus::Failed, ['error' => ['code' => $code, 'message' => $message]]);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.failed', $object))->assertOk();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame([$code, $message], [$row->error_code, $row->error_message]);
        $this->assertSame([0, 0], [$row->provider_cost_nanodollars, $row->user_charge_nanodollars]);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(0, $this->ledgerNetFor($lighthouse));
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function providerFailures(): array
    {
        return [
            'generation_failed' => ['generation_failed', 'The model could not produce an image for this prompt.'],
            'internal_error' => ['internal_error', 'An internal error occurred. Please retry.'],
        ];
    }

    public function test_a_processing_event_starts_a_queued_generation_without_a_ledger_entry(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.processing', self::generationObjectFor($lighthouse, GenerationStatus::Processing)))->assertOk();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Processing, $row->status);
        $this->assertSameInstant('2026-11-18T23:59:42Z', $row->started_at);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));
    }

    public function test_an_applied_event_stamps_the_last_provider_update(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $this->travel(2)->minutes();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.processing', self::generationObjectFor($lighthouse, GenerationStatus::Processing)))->assertOk();

        $this->assertTrue($this->reloadedGeneration($lighthouse)->last_provider_update_at?->equalTo(CarbonImmutable::now()->startOfSecond()));
    }

    /**
     * PE-007 AC18: a succeeded object that cannot be priced is refused with
     * nothing recorded, so a resend after a fix is processed.
     */
    public function test_a_succeeded_event_whose_snapshot_cannot_be_read_is_refused_and_persists_nothing(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $event = self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse, ['usage' => null]));
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();

        $this->deliverPixelforgeEvent($event)->assertStatus(400);

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([], $this->storedWebhookEvents());

        $refusals = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => $message->message === 'Refused a signed Pixelforge webhook whose body cannot be read.',
        ));
        $this->assertCount(1, $refusals);
        $this->assertSame('error', $refusals[0]->level);
        $this->assertSame(
            [
                'error' => 'The Pixelforge webhook\'s data.object cannot be read: A succeeded generation object must carry usage.',
                'event_id' => $event['id'],
                'generation_id' => $lighthouse->id,
            ],
            $refusals[0]->context,
        );
    }

    #[DataProvider('unreadableObjects')]
    public function test_a_generation_event_whose_object_is_not_a_generation_is_refused(string $type): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $event = self::pixelforgeEvent($type, ['id' => 'gen_x', 'metadata' => ['generation_id' => $lighthouse->id]]);

        $this->deliverPixelforgeEvent($event)->assertStatus(400);

        $this->assertSame([], $this->storedWebhookEvents());
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unreadableObjects(): array
    {
        return [
            'processing' => ['generation.processing'],
            'succeeded' => ['generation.succeeded'],
            'failed' => ['generation.failed'],
        ];
    }

    /**
     * Two generations, each with its own events, are never mixed up.
     */
    public function test_each_event_reaches_only_its_own_generation(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $other = $this->givenTheSubmittedLighthouseGeneration();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($other)->status);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($other));
    }

    /**
     * @param  Closure(Generation): array{string, array<string, mixed>}  $first  the type and object applied first
     * @param  Closure(Generation): array{string, array<string, mixed>}  $late  the out-of-order event
     */
    #[DataProvider('outOfOrderEvents')]
    public function test_an_out_of_order_event_is_recorded_and_changes_nothing(Closure $first, Closure $late): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $this->deliverPixelforgeEvent(self::pixelforgeEvent(...$first($lighthouse)))->assertOk();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();
        $event = self::pixelforgeEvent(...$late($lighthouse));

        $this->deliverPixelforgeEvent($event)->assertOk();

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $events = $this->storedWebhookEvents();
        $this->assertCount(2, $events);
        $this->assertSame([$event['id'], $lighthouse->id], [$events[1]['event_id'], $events[1]['generation_id']]);
    }

    /**
     * @return array<string, array{Closure(Generation): array{string, array<string, mixed>}, Closure(Generation): array{string, array<string, mixed>}}>
     */
    public static function outOfOrderEvents(): array
    {
        $succeeded = static fn (Generation $generation): array => ['generation.succeeded', self::lighthouseSucceededObjectFor($generation)];
        $processing = static fn (Generation $generation): array => ['generation.processing', self::generationObjectFor($generation, GenerationStatus::Processing)];
        $failed = static fn (Generation $generation): array => ['generation.failed', self::generationObjectFor($generation, GenerationStatus::Failed)];

        return [
            'processing after succeeded' => [$succeeded, $processing],
            'failed after succeeded' => [$succeeded, $failed],
            'succeeded after a provider failure' => [$failed, $succeeded],
        ];
    }

    public function test_an_event_whose_id_contradicts_the_stored_provider_id_is_recorded_but_refused(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse, ['id' => 'gen_OTHER'])))->assertOk();

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('error', $logged[0]->level);
        $this->assertSame('Refused a Pixelforge snapshot whose id differs from the stored provider id.', $logged[0]->message);
        $this->assertSame(
            [$lighthouse->id, 'gen_' . strtoupper($lighthouse->id), 'gen_OTHER'],
            [$logged[0]->context['generation_id'], $logged[0]->context['provider_id'], $logged[0]->context['incoming_provider_id']],
        );
    }

    /**
     * D-09 (PE-007 AC20): Pixelforge bills a generation we failed locally, so
     * its cost is kept for the operator report, but the user pays nothing.
     */
    public function test_a_succeeded_event_for_a_locally_failed_lighthouse_records_its_cost_and_charges_nothing(): void
    {
        $lighthouse = $this->givenTheLighthouseGeneration();
        $this->transitionGeneration($lighthouse, new LocalGenerationFailureData(LocalFailureReason::ContentPolicyViolation, null, null, null));

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)))->assertOk();

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame(305_510_000, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));
    }

    /**
     * D-27: an event that lost our metadata is matched by the provider id the
     * create response stored, and the fallback is logged.
     */
    public function test_an_event_without_our_generation_id_is_matched_by_the_stored_provider_id(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse, ['metadata' => (object) []])))->assertOk();

        $this->assertSame(GenerationStatus::Succeeded, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame(self::LIGHTHOUSE_CHARGED, $this->ledgerEntriesFor($lighthouse));
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame('Identified a Pixelforge snapshot without our generation id by its stored provider id.', $logged[0]->message);
        $this->assertSame(['generation_id' => $lighthouse->id, 'provider_id' => 'gen_' . strtoupper($lighthouse->id)], $logged[0]->context);
    }

    /**
     * The provider id finds the row, but the event names another generation:
     * the data contradicts itself, so it is refused, not merely incomplete.
     */
    public function test_an_event_with_a_foreign_generation_id_but_our_provider_id_is_recorded_but_refused(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse, ['metadata' => ['generation_id' => 'not-a-ulid']])))->assertOk();

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([$lighthouse->id], array_column($this->storedWebhookEvents(), 'generation_id'));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('error', $logged[0]->level);
        $this->assertSame('Refused a Pixelforge snapshot of another generation.', $logged[0]->message);
    }

    /**
     * Our row always exists before submission (D-05), so an unknown
     * generation is foreign data: a retry could never help, so it is answered
     * 2xx, recorded and logged.
     *
     * @param  array<string, mixed>  $metadata
     */
    #[DataProvider('unknownGenerations')]
    public function test_an_event_for_an_unknown_generation_is_recorded_without_one_and_changes_nothing(array $metadata): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();
        $object = self::lighthouseSucceededObjectFor($lighthouse, ['id' => 'gen_UNKNOWN', 'metadata' => (object) $metadata]);
        $event = self::pixelforgeEvent('generation.succeeded', $object);

        $this->deliverPixelforgeEvent($event)->assertOk();

        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $events = $this->storedWebhookEvents();
        $this->assertCount(1, $events);
        $this->assertSame([$event['id'], null], [$events[0]['event_id'], $events[0]['generation_id']]);

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame('Recorded a Pixelforge webhook for an unknown generation; nothing changed.', $logged[0]->message);
        $this->assertSame(
            [
                'event_id' => $event['id'],
                'type' => 'generation.succeeded',
                'generation_id' => $metadata['generation_id'] ?? null,
                'provider_id' => 'gen_UNKNOWN',
            ],
            $logged[0]->context,
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unknownGenerations(): array
    {
        return [
            'a ULID with no row' => [['generation_id' => '01jc2z8q4n6v0m3h7x5t9r2b1k']],
            'not a ULID' => [['generation_id' => 'not-a-ulid']],
            '500 characters' => [['generation_id' => str_repeat('a', 500)]],
            'a ULID followed by a NUL byte' => [['generation_id' => "01jc2z8q4n6v0m3h7x5t9r2b1k\0x"]],
            'absent' => [['order_id' => '1042']],
        ];
    }

    /**
     * pdo_pgsql would cut `<ULID>\0x` at the NUL byte and match the real row,
     * so a value that is not a ULID is never queried.
     */
    public function test_our_ulid_followed_by_a_nul_byte_never_matches_our_row(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $object = self::lighthouseSucceededObjectFor($lighthouse, ['id' => 'gen_UNKNOWN', 'metadata' => ['generation_id' => $lighthouse->id . "\0x"]]);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $object))->assertOk();

        $this->assertSame(GenerationStatus::Queued, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame([null], array_column($this->storedWebhookEvents(), 'generation_id'));
    }

    /**
     * The docs say to go by the generation's status, so the snapshot decides
     * the transition, and the event type that disagrees is logged.
     *
     * @param  list<array{string, int}>  $ledger
     */
    #[DataProvider('typesThatDisagreeWithTheirSnapshot')]
    public function test_the_snapshot_status_decides_when_the_event_type_disagrees(string $type, GenerationStatus $snapshotStatus, array $ledger): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $object = $snapshotStatus === GenerationStatus::Succeeded
            ? self::lighthouseSucceededObjectFor($lighthouse)
            : self::generationObjectFor($lighthouse, $snapshotStatus);
        $event = self::pixelforgeEvent($type, $object);

        $this->deliverPixelforgeEvent($event)->assertOk();

        $this->assertSame($snapshotStatus, $this->reloadedGeneration($lighthouse)->status);
        $this->assertSame($ledger, $this->ledgerEntriesFor($lighthouse));

        $logged = $this->loggedMessages();
        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]->level);
        $this->assertSame('A Pixelforge webhook\'s type disagrees with its snapshot\'s status; the status decides.', $logged[0]->message);
        $this->assertSame(
            ['event_id' => $event['id'], 'type' => $type, 'status' => $snapshotStatus->value, 'generation_id' => $lighthouse->id],
            $logged[0]->context,
        );
    }

    /**
     * @return array<string, array{string, GenerationStatus, list<array{string, int}>}>
     */
    public static function typesThatDisagreeWithTheirSnapshot(): array
    {
        return [
            'succeeded, still processing' => ['generation.succeeded', GenerationStatus::Processing, [['hold', -457_150_000]]],
            'processing, already succeeded' => ['generation.processing', GenerationStatus::Succeeded, self::LIGHTHOUSE_CHARGED],
        ];
    }
}
