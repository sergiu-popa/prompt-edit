<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * Every verified event is recorded once by its signed id, whatever it
 * concerns. A signed body that cannot be read is refused and persists
 * nothing, so a resend after a fix is processed.
 */
final class PixelforgeWebhookEventsTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    /**
     * Sent with no `Accept` header, so a validation redirect would show as a 302.
     */
    #[DataProvider('unreadableBodies')]
    public function test_a_signed_body_that_cannot_be_read_is_refused_with_400_and_persists_nothing(string $body, ?string $eventId): void
    {
        $response = $this->deliverPixelforgeWebhook($body);

        $response->assertStatus(400);
        $this->assertSame([], $this->storedWebhookEvents());

        $errors = $this->messagesAt('error');
        $this->assertCount(1, $errors);
        $this->assertSame('Refused a signed Pixelforge webhook whose body cannot be read.', $errors[0]->message);
        $this->assertSame($eventId, $errors[0]->context['event_id']);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function unreadableBodies(): array
    {
        $object = '{"id":"gen_1","object":"generation"}';

        return [
            'not JSON' => ['{"id":"evt_1",', null],
            'a JSON string' => ['"evt_1"', null],
            'a JSON list' => ['[{"id":"evt_1","type":"ping"}]', null],
            'no id' => ['{"object":"event","type":"ping","data":{}}', null],
            'no type' => ['{"id":"evt_1","object":"event","data":{}}', 'evt_1'],
            'a numeric id' => ['{"id":1,"object":"event","type":"ping","data":{}}', null],
            'an empty id' => ['{"id":"","object":"event","type":"ping","data":{}}', null],
            'an id with a NUL byte' => ['{"id":"evt_1\u0000x","object":"event","type":"ping","data":{}}', null],
            'a 256-character id' => [sprintf('{"id":"%s","object":"event","type":"ping","data":{}}', str_repeat('e', 256)), null],
            'a numeric type' => ['{"id":"evt_1","object":"event","type":7,"data":{}}', 'evt_1'],
            'a generation event without data' => ['{"id":"evt_1","object":"event","type":"generation.succeeded"}', 'evt_1'],
            'a generation event with empty data' => ['{"id":"evt_1","object":"event","type":"generation.succeeded","data":{}}', 'evt_1'],
            'a generation event whose object is a string' => ['{"id":"evt_1","object":"event","type":"generation.failed","data":{"object":"gen_1"}}', 'evt_1'],
            'a generation event whose data is a list' => [sprintf('{"id":"evt_1","object":"event","type":"generation.processing","data":[%s]}', $object), 'evt_1'],
        ];
    }

    public function test_a_255_character_id_is_recorded(): void
    {
        $eventId = str_repeat('e', 255);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null, $eventId))->assertOk();

        $this->assertSame([$eventId], array_column($this->storedWebhookEvents(), 'event_id'));
    }

    #[DataProvider('pingData')]
    public function test_a_ping_is_recorded_with_or_without_an_object_and_touches_no_generation(mixed $data): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();
        $event = [...self::pixelforgeEvent('ping', null), 'data' => $data];

        $this->deliverPixelforgeEvent($event)->assertOk();

        $this->assertSame([[
            'event_id' => $event['id'],
            'type' => 'ping',
            'delivery_attempt' => 1,
            'generation_id' => null,
            'received_at' => '2026-11-19 00:01:10+00',
        ]], $this->storedWebhookEvents());
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);
        $this->assertSame([], $this->messagesAt('warning', 'error'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function pingData(): array
    {
        return [
            'empty data' => [(object) []],
            'no data at all' => [null],
            'a data.object' => [['object' => ['id' => 'gen_test', 'object' => 'generation']]],
        ];
    }

    public function test_the_same_ping_again_is_a_silent_no_op_that_keeps_the_first_attempt(): void
    {
        $body = json_encode(self::pixelforgeEvent('ping', null), JSON_THROW_ON_ERROR);
        $this->deliverPixelforgeWebhook($body)->assertOk();
        $this->travel(2)->minutes();

        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Delivery-Attempt' => '2'])->assertOk();

        $events = $this->storedWebhookEvents();
        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]['delivery_attempt']);
        $this->assertSame('2026-11-19 00:00:10+00', $events[0]['received_at']);
        $this->assertSame([], $this->messagesAt('warning', 'error'));
    }

    public function test_an_unknown_event_type_is_recorded_with_a_warning_and_changes_nothing(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $before = [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)];
        $this->travel(1)->minutes();
        $event = self::pixelforgeEvent('generation.completed', self::lighthouseSucceededObjectFor($lighthouse));

        $this->deliverPixelforgeEvent($event)->assertOk();

        $events = $this->storedWebhookEvents();
        $this->assertCount(1, $events);
        $this->assertSame([$event['id'], 'generation.completed', null], [$events[0]['event_id'], $events[0]['type'], $events[0]['generation_id']]);
        $this->assertSame($before, [$this->rawGenerationRow($lighthouse), $this->ledgerEntriesFor($lighthouse)]);

        $warnings = $this->messagesAt('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('Recorded a Pixelforge webhook of an unknown type; nothing changed.', $warnings[0]->message);
        $this->assertSame(['event_id' => $event['id'], 'type' => 'generation.completed'], $warnings[0]->context);
    }

    public function test_dedupe_uses_the_signed_body_id_not_the_event_id_header(): void
    {
        $body = json_encode(self::pixelforgeEvent('ping', null, 'evt_body'), JSON_THROW_ON_ERROR);

        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Event-Id' => 'evt_header_one'])->assertOk();
        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Event-Id' => 'evt_header_two'])->assertOk();

        $this->assertSame(['evt_body'], array_column($this->storedWebhookEvents(), 'event_id'));
        $this->assertSame(
            [
                ['field' => 'id', 'header' => 'evt_header_one', 'body' => 'evt_body'],
                ['field' => 'id', 'header' => 'evt_header_two', 'body' => 'evt_body'],
            ],
            array_map(fn (MessageLogged $message): array => $message->context, $this->messagesAt('warning')),
        );
    }

    public function test_routing_uses_the_signed_body_type_not_the_event_type_header(): void
    {
        $event = self::pixelforgeEvent('ping', null);

        $this->deliverPixelforgeEvent($event, ['Pixelforge-Event-Type' => 'generation.succeeded'])->assertOk();

        $this->assertSame(['ping'], array_column($this->storedWebhookEvents(), 'type'));

        $warnings = $this->messagesAt('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame('A Pixelforge webhook header disagrees with its signed body; the body wins.', $warnings[0]->message);
        $this->assertSame(['field' => 'type', 'header' => 'generation.succeeded', 'body' => 'ping'], $warnings[0]->context);
    }

    public function test_a_disagreeing_header_is_logged_with_at_most_255_characters(): void
    {
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null, 'evt_body'), ['Pixelforge-Event-Id' => str_repeat('h', 4000)])->assertOk();

        $this->assertSame(
            [['field' => 'id', 'header' => str_repeat('h', 255), 'body' => 'evt_body']],
            array_map(fn (MessageLogged $message): array => $message->context, $this->messagesAt('warning')),
        );
    }

    #[DataProvider('unusableAttempts')]
    public function test_an_unusable_delivery_attempt_header_is_stored_as_null(?string $attempt): void
    {
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null), ['Pixelforge-Delivery-Attempt' => $attempt])->assertOk();

        $this->assertSame([null], array_column($this->storedWebhookEvents(), 'delivery_attempt'));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function unusableAttempts(): array
    {
        return [
            'absent' => [null],
            'not a number' => ['two'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'fractional' => ['1.5'],
            'above the integer column' => ['2147483648'],
        ];
    }

    public function test_the_largest_storable_delivery_attempt_is_kept(): void
    {
        $this->deliverPixelforgeEvent(self::pixelforgeEvent('ping', null), ['Pixelforge-Delivery-Attempt' => '2147483647'])->assertOk();

        $this->assertSame([2_147_483_647], array_column($this->storedWebhookEvents(), 'delivery_attempt'));
    }

    /**
     * @return list<MessageLogged>
     */
    private function messagesAt(string ...$levels): array
    {
        return array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => in_array($message->level, $levels, true),
        ));
    }
}
