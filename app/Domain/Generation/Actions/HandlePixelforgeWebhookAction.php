<?php

declare(strict_types=1);

namespace App\Domain\Generation\Actions;

use App\Domain\Billing\Exceptions\InvalidRateException;
use App\Domain\Billing\Exceptions\NanodollarOverflowException;
use App\Domain\Generation\Exceptions\ConcurrentGenerationTransitionException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Generation\Models\PixelforgeWebhookEvent;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\DataTransferObjects\WebhookDeliveryData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies one verified webhook delivery exactly once (D-07): its event row
 * is written in the same transaction as the state change, and only a new row
 * hands its snapshot to the single transition action (D-06), so the ledger
 * settles or releases once. Dedupe and routing use the signed body's id and
 * type; the unsigned headers only inform. The snapshot's status, not the
 * event type, decides the transition, as the docs say.
 */
final readonly class HandlePixelforgeWebhookAction
{
    public function __construct(private TransitionGenerationAction $transitionGeneration) {}

    /**
     * Anything thrown rolls the event row back with the state change, so a
     * redelivery is processed normally.
     *
     * @throws ConcurrentGenerationTransitionException
     * @throws InvalidRateException
     * @throws NanodollarOverflowException
     */
    public function execute(WebhookDeliveryData $delivery): void
    {
        $this->logHeaderMismatches($delivery);

        DB::transaction(function () use ($delivery): void {
            $snapshot = $delivery->snapshot;
            $generation = $snapshot === null ? null : $this->lockedGenerationFor($snapshot);

            if (! $this->record($delivery, $generation)) {
                return;
            }

            if ($delivery->knownType === null) {
                Log::warning('Recorded a Pixelforge webhook of an unknown type; nothing changed.', [
                    'event_id' => $delivery->eventId,
                    'type' => $delivery->type,
                ]);

                return;
            }

            if ($snapshot === null) {
                return;
            }

            if ($generation === null) {
                Log::warning('Recorded a Pixelforge webhook for an unknown generation; nothing changed.', [
                    'event_id' => $delivery->eventId,
                    'type' => $delivery->type,
                    'generation_id' => $snapshot->generationId,
                    'provider_id' => $snapshot->id,
                ]);

                return;
            }

            if ($delivery->knownType->generationStatus() !== $snapshot->status) {
                Log::warning('A Pixelforge webhook\'s type disagrees with its snapshot\'s status; the status decides.', [
                    'event_id' => $delivery->eventId,
                    'type' => $delivery->type,
                    'status' => $snapshot->status->value,
                    'generation_id' => $generation->id,
                ]);
            }

            $this->transitionGeneration->execute($generation, $snapshot);
        });
    }

    /**
     * Our ULID first, because the event can arrive before the create response
     * stored a provider id; then the stored provider id. The transition still
     * checks both ids against the row it is given.
     *
     * The row is locked before the event row is written: that row's foreign
     * key takes a key-share lock on the generation, so two different events
     * for one generation would otherwise deadlock on the transition's
     * `FOR UPDATE`. A value that is not a ULID is never queried, because
     * pdo_pgsql cuts a string at a NUL byte and `<ULID>\0x` would match. The
     * snapshot already refuses a provider id with a NUL byte.
     */
    private function lockedGenerationFor(GenerationSnapshot $snapshot): ?Generation
    {
        $generationId = $snapshot->generationId;

        $byUlid = $generationId !== null && Str::isUlid($generationId)
            ? Generation::query()->whereKey($generationId)->lockForUpdate()->first()
            : null;

        return $byUlid ?? Generation::query()->where('provider_id', $snapshot->id)->lockForUpdate()->first();
    }

    /**
     * `ON CONFLICT DO NOTHING`: a racing duplicate waits for the first
     * delivery's transaction, then inserts nothing if it committed, or goes
     * on as the first if it rolled back. A raw unique violation would abort
     * the whole Postgres transaction instead.
     *
     * @return bool whether the event is new
     */
    private function record(WebhookDeliveryData $delivery, ?Generation $generation): bool
    {
        return PixelforgeWebhookEvent::query()->fillAndInsertOrIgnore([[
            'event_id' => $delivery->eventId,
            'type' => $delivery->type,
            'delivery_attempt' => $delivery->deliveryAttempt,
            'generation_id' => $generation?->id,
        ]]) === 1;
    }

    /**
     * Every disagreeing delivery is logged, duplicates included, because a
     * replay with rewritten headers is what an operator wants to see. The
     * unsigned value is cut to the length a real one can have.
     */
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
                    'header' => mb_substr($header, 0, WebhookDeliveryData::MAX_FIELD_LENGTH),
                    'body' => $body,
                ]);
            }
        }
    }
}
