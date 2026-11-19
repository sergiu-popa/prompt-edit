<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Enums\LocalFailureReason;
use App\Support\Pixelforge\Enums\PixelforgeErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CapturesLogMessages;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithPixelforgeApi;
use Tests\TestCase;

/**
 * Pixelforge's answers that are not a generation. Only a definitive rejection,
 * where Pixelforge created nothing, fails the generation here, at no charge.
 */
final class SubmitGenerationRejectionsTest extends TestCase
{
    use CapturesLogMessages;
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use InteractsWithPixelforgeApi;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $error
     */
    #[DataProvider('definitiveRejections')]
    public function test_a_definitive_rejection_fails_the_generation_at_no_charge(
        PixelforgeErrorCode $code,
        array $error,
        LocalFailureReason $reason,
        ?string $param,
        int $loggedErrors,
    ): void {
        $lighthouse = $this->givenTheLighthouseGeneration();
        Http::fake(['*' => self::pixelforgeErrorFor($code, $error)]);

        $this->runSubmitJob($lighthouse);

        $row = $this->reloadedGeneration($lighthouse);
        $this->assertSame(GenerationStatus::Failed, $row->status);
        $this->assertSame($reason, $row->local_failure_reason);
        $this->assertSame($code->value, $row->error_code);
        $this->assertSame(sprintf('Pixelforge says %s.', str_replace('_', ' ', $code->value)), $row->error_message);
        $this->assertSame($param, $row->error_param);
        $this->assertNull($row->provider_id);
        $this->assertSame(0, $row->provider_cost_nanodollars);
        $this->assertSame(0, $row->user_charge_nanodollars);
        $this->assertSame([['hold', -457_150_000], ['release', 457_150_000]], $this->ledgerEntriesFor($lighthouse));
        $this->assertSame(0, $this->ledgerNetFor($lighthouse));
        $this->assertCount($loggedErrors, array_filter(
            $this->loggedMessages(),
            fn (MessageLogged $logged): bool => $logged->level === 'error',
        ));
    }

    /**
     * @return array<string, array{PixelforgeErrorCode, array<string, mixed>, LocalFailureReason, ?string, int}>
     */
    public static function definitiveRejections(): array
    {
        return [
            '400 content policy violation' => [PixelforgeErrorCode::ContentPolicyViolation, [], LocalFailureReason::ContentPolicyViolation, null, 0],
            '422 validation failed' => [
                PixelforgeErrorCode::ValidationFailed,
                ['param' => 'size', 'errors' => ['size' => ['The selected size is invalid.']]],
                LocalFailureReason::ValidationFailed,
                'size',
                1,
            ],
            '409 idempotency key reused' => [PixelforgeErrorCode::IdempotencyKeyReused, [], LocalFailureReason::IdempotencyKeyReused, null, 1],
        ];
    }
}
