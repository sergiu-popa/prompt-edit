<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Enums;

use App\Domain\Generation\Enums\SubmitAttemptOutcome;
use App\Support\Pixelforge\Contracts\ReportsPixelforgeContext;
use App\Support\Pixelforge\Exceptions\AmbiguousPixelforgeOutcomeException;
use App\Support\Pixelforge\Exceptions\ConcurrencyLimitExceededException;
use App\Support\Pixelforge\Exceptions\ContentPolicyViolationException;
use App\Support\Pixelforge\Exceptions\DailyLimitExceededException;
use App\Support\Pixelforge\Exceptions\IdempotencyKeyReusedException;
use App\Support\Pixelforge\Exceptions\InvalidApiKeyException;
use App\Support\Pixelforge\Exceptions\NotFoundException;
use App\Support\Pixelforge\Exceptions\OverloadedException;
use App\Support\Pixelforge\Exceptions\PixelforgeNotConfiguredException;
use App\Support\Pixelforge\Exceptions\RateBudgetExhaustedException;
use App\Support\Pixelforge\Exceptions\RateLimitExceededException;
use App\Support\Pixelforge\Exceptions\ValidationFailedException;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SubmitAttemptOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{ReportsPixelforgeContext, SubmitAttemptOutcome}>
     */
    public static function failures(): iterable
    {
        yield 'the spent budget' => [RateBudgetExhaustedException::freesUpIn(30), SubmitAttemptOutcome::BudgetExhausted];
        yield 'no key' => [PixelforgeNotConfiguredException::missingApiKey(), SubmitAttemptOutcome::NotConfigured];
        yield '429 rate limit' => [new RateLimitExceededException('rate_limit_error', 'Slow down.'), SubmitAttemptOutcome::RateLimited];
        yield 'an undocumented 429' => [RateLimitExceededException::undocumented(9), SubmitAttemptOutcome::RateLimited];
        yield '429 concurrency limit' => [new ConcurrencyLimitExceededException('rate_limit_error', 'Busy.'), SubmitAttemptOutcome::ConcurrencyLimited];
        yield '429 daily limit' => [new DailyLimitExceededException('rate_limit_error', 'Tomorrow.'), SubmitAttemptOutcome::DailyLimited];
        yield '503 overloaded' => [new OverloadedException('api_error', 'Overloaded.'), SubmitAttemptOutcome::Overloaded];
        yield '401 invalid api key' => [new InvalidApiKeyException('authentication_error', 'Invalid key.'), SubmitAttemptOutcome::InvalidApiKey];
        yield '400 content policy' => [new ContentPolicyViolationException('invalid_request_error', 'Rejected.'), SubmitAttemptOutcome::Rejected];
        yield '422 validation failed' => [new ValidationFailedException('invalid_request_error', 'Invalid.'), SubmitAttemptOutcome::Rejected];
        yield '409 idempotency key reused' => [new IdempotencyKeyReusedException('idempotency_error', 'Reused.'), SubmitAttemptOutcome::Rejected];
        yield 'a 404 at create, undocumented' => [new NotFoundException('invalid_request_error', 'Not found.'), SubmitAttemptOutcome::Ambiguous];
        yield 'a timeout' => [AmbiguousPixelforgeOutcomeException::transportFailed(new ConnectionException('cURL error 28')), SubmitAttemptOutcome::Ambiguous];
        yield 'a 502 page' => [AmbiguousPixelforgeOutcomeException::undocumentedResponse(502), SubmitAttemptOutcome::Ambiguous];
    }

    #[DataProvider('failures')]
    public function test_each_failure_has_its_outcome(ReportsPixelforgeContext $failure, SubmitAttemptOutcome $outcome): void
    {
        $this->assertSame($outcome, SubmitAttemptOutcome::fromFailure($failure));
    }
}
