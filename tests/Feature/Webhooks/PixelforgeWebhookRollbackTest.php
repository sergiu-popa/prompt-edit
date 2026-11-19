<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\DeliversPixelforgeWebhooks;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * When applying a snapshot throws, the event record, the status and the
 * ledger roll back together and the endpoint answers 500, so Pixelforge
 * retries and a later delivery is processed normally.
 */
final class PixelforgeWebhookRollbackTest extends TestCase
{
    use CapturesLogMessages;
    use DeliversPixelforgeWebhooks;
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_a_failing_ledger_write_rolls_the_event_back_and_a_redelivery_charges_once(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $body = json_encode(self::pixelforgeEvent('generation.succeeded', self::lighthouseSucceededObjectFor($lighthouse)), JSON_THROW_ON_ERROR);
        $before = $this->rawGenerationRow($lighthouse);
        $this->failTheNextChargeInsert();

        $this->deliverPixelforgeWebhook($body)->assertStatus(500);

        $this->assertSame([], $this->storedWebhookEvents());
        $this->assertSame($before, $this->rawGenerationRow($lighthouse));
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));

        $this->travel(10)->seconds();
        $this->deliverPixelforgeWebhook($body, ['Pixelforge-Delivery-Attempt' => '2'])->assertOk();

        $this->assertSame([[$lighthouse->id, 2]], array_map(
            fn (array $event): array => [$event['generation_id'], $event['delivery_attempt']],
            $this->storedWebhookEvents(),
        ));
        $this->assertSame(
            [['hold', -457_150_000], ['release', 457_150_000], ['charge', -381_887_500]],
            $this->ledgerEntriesFor($lighthouse),
        );
    }

    /**
     * PE-007 AC18: 5.0001 USD per 1M tokens is never rounded, so the
     * generation keeps only its hold and every delivery is retried.
     */
    public function test_a_rate_that_cannot_be_priced_exactly_rolls_the_event_back(): void
    {
        $lighthouse = $this->givenTheSubmittedLighthouseGeneration();
        $object = self::lighthouseSucceededObjectFor($lighthouse, [
            'rates' => ['input_text_tokens' => '5.0001', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        ]);
        $before = $this->rawGenerationRow($lighthouse);

        $this->deliverPixelforgeEvent(self::pixelforgeEvent('generation.succeeded', $object))->assertStatus(500);

        $this->assertSame([], $this->storedWebhookEvents());
        $this->assertSame($before, $this->rawGenerationRow($lighthouse));
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($lighthouse));

        $pricingErrors = array_values(array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $message): bool => $message->message === 'Refused a succeeded Pixelforge snapshot that cannot be priced exactly.',
        ));
        $this->assertCount(1, $pricingErrors);
        $this->assertSame($lighthouse->id, $pricingErrors[0]->context['generation_id']);
    }

    /**
     * Throws once, right after the charge insert has run, so the event row,
     * the status and the release were already written in the transaction.
     */
    private function failTheNextChargeInsert(): void
    {
        $failed = false;

        DB::listen(function (QueryExecuted $query) use (&$failed): void {
            if ($failed || ! str_starts_with($query->sql, 'insert into "credit_ledger_entries"') || ! in_array('charge', $query->bindings, true)) {
                return;
            }

            $failed = true;

            throw new RuntimeException('The charge insert failed.');
        });
    }
}
