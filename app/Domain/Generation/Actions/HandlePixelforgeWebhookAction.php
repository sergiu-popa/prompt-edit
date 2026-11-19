<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Support\Pixelforge\DataTransferObjects\WebhookDeliveryData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies one verified webhook delivery exactly once (D-07): its event row
 * is written in the same transaction as the state change, so the two commit
 * or roll back together. Dedupe and routing use the signed body's id and
 * type; the unsigned headers only inform.
 */
final class HandlePixelforgeWebhookAction
{
    public function execute(WebhookDeliveryData $delivery): void
    {
        $this->logHeaderMismatches($delivery);

        DB::transaction(function () use ($delivery): void {
            if (! $this->record($delivery)) {
                return;
            }

            if ($delivery->knownType === null) {
                Log::warning('Recorded a Pixelforge webhook of an unknown type; nothing changed.', [
                    'event_id' => $delivery->eventId,
                    'type' => $delivery->type,
                ]);
            }
        });
    }

    /**
     * `ON CONFLICT DO NOTHING`: a racing duplicate waits for the first
     * delivery's transaction, then inserts nothing if it committed, or goes
     * on as the first if it rolled back. A raw unique violation would abort
     * the whole Postgres transaction instead.
     *
     * @return bool whether the event is new
     */
    private function record(WebhookDeliveryData $delivery): bool
    {
        return PixelforgeWebhookEvent::query()->fillAndInsertOrIgnore([[
            'event_id' => $delivery->eventId,
            'type' => $delivery->type,
            'delivery_attempt' => $delivery->deliveryAttempt,
            'generation_id' => null,
        ]]) === 1;
    }

    private function logHeaderMismatches(WebhookDeliveryData $delivery): void
    {
        $pairs = [
            'id' => [$delivery->headerEventId, $delivery->eventId],
            'type' => [$delivery->headerEventType, $delivery->type],
        ];

        foreach ($pairs as $field => [$header, $body]) {
            if ($header !== null && $header !== $body) {
                Log::warning('A Pixelforge webhook header disagrees with its signed body; the body wins.', [
                    'field' => $field,
                    'header' => $header,
                    'body' => $body,
                ]);
            }
        }
    }
}
