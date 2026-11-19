<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Domain\Generation\Enums\GenerationStatus;
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
}
