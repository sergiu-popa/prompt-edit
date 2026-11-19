<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Pixelforge\DataTransferObjects;

use App\Domain\Billing\Exceptions\InvalidTokenUsageException;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Support\Pixelforge\DataTransferObjects\GenerationSnapshot;
use App\Support\Pixelforge\Exceptions\InvalidGenerationSnapshotException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Concerns\AssertsInstants;
use Tests\Concerns\InteractsWithGenerationSnapshots;

final class GenerationSnapshotTest extends TestCase
{
    use AssertsInstants;
    use InteractsWithGenerationSnapshots;

    private string $phpTimezone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phpTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);

        parent::tearDown();
    }

    public function test_the_docs_create_response_parses(): void
    {
        $snapshot = GenerationSnapshot::from(self::pixelforgeFixture('generation-queued'));

        $this->assertSame('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', $snapshot->id);
        $this->assertSame(GenerationStatus::Queued, $snapshot->status);
        $this->assertSame(ImageSize::Landscape, $snapshot->size);
        $this->assertSame(ImageQuality::High, $snapshot->quality);
        $this->assertNull($snapshot->generationId);
        $this->assertNull($snapshot->errorCode);
        $this->assertNull($snapshot->errorMessage);
        $this->assertNull($snapshot->usage);
        $this->assertSame(self::docsRates(), $snapshot->rates);
        $this->assertNull($snapshot->imageUrl);
        $this->assertNull($snapshot->imageUrlExpiresAt);
        $this->assertSameInstant('2026-11-04T14:03:11Z', $snapshot->createdAt);
        $this->assertNull($snapshot->startedAt);
        $this->assertNull($snapshot->completedAt);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function succeededExamples(): array
    {
        $envelope = self::pixelforgeFixture('event-generation-succeeded');
        $data = $envelope['data'];
        assert(is_array($data) && is_array($data['object']));

        /** @var array<string, mixed> $object */
        $object = $data['object'];

        return [
            'the generation object' => [self::pixelforgeFixture('generation-succeeded')],
            'a webhook envelope\'s data.object' => [$object],
        ];
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    #[DataProvider('succeededExamples')]
    public function test_the_docs_succeeded_example_parses(array $generation): void
    {
        $snapshot = GenerationSnapshot::from($generation);

        $this->assertSame(GenerationStatus::Succeeded, $snapshot->status);
        $this->assertNotNull($snapshot->usage);
        $this->assertSame(12, $snapshot->usage->inputTextTokens);
        $this->assertSame(765, $snapshot->usage->inputImageTokens);
        $this->assertSame(6_450, $snapshot->usage->outputTokens);
        $this->assertSame(3_184, $snapshot->usage->reasoningTokens);
        $this->assertSame(self::docsRates(), $snapshot->rates);
        $this->assertSame(
            'https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png?expires=1762351409&signature=…',
            $snapshot->imageUrl,
        );
        $this->assertSameInstant('2026-11-05T14:03:29Z', $snapshot->imageUrlExpiresAt);
        $this->assertSameInstant('2026-11-04T14:03:11Z', $snapshot->createdAt);
        $this->assertSameInstant('2026-11-04T14:03:13Z', $snapshot->startedAt);
        $this->assertSameInstant('2026-11-04T14:03:29Z', $snapshot->completedAt);
    }

    public function test_metadata_generation_id_is_read_and_an_empty_metadata_gives_none(): void
    {
        $this->assertSame(
            '01jc2z8q4n6v0m3h7x5t9r2b1k',
            GenerationSnapshot::from(self::queuedWith(['metadata' => ['order_id' => '1042', 'generation_id' => '01jc2z8q4n6v0m3h7x5t9r2b1k']]))->generationId,
        );
        $this->assertNull(GenerationSnapshot::from(self::queuedWith(['metadata' => []]))->generationId);
    }

    public function test_unknown_top_level_fields_are_ignored(): void
    {
        $snapshot = GenerationSnapshot::from(self::queuedWith(['livemode' => true]));

        $this->assertSame(GenerationStatus::Queued, $snapshot->status);
    }

    public function test_a_failed_snapshot_carries_its_error(): void
    {
        $snapshot = GenerationSnapshot::from(self::queuedWith([
            'status' => 'failed',
            'error' => ['code' => 'internal_error', 'message' => 'You were not charged; please retry.'],
            'completed_at' => '2026-11-04T14:03:29Z',
        ]));

        $this->assertSame(GenerationStatus::Failed, $snapshot->status);
        $this->assertSame('internal_error', $snapshot->errorCode);
        $this->assertSame('You were not charged; please retry.', $snapshot->errorMessage);
    }

    public function test_an_offset_instant_is_converted_to_utc_whatever_the_php_timezone(): void
    {
        date_default_timezone_set('Europe/Bucharest');

        $snapshot = GenerationSnapshot::from(self::queuedWith([
            'status' => 'processing',
            'started_at' => '2026-11-19T01:59:59+02:00',
        ]));

        $this->assertNotNull($snapshot->startedAt);
        $this->assertSame('UTC', $snapshot->startedAt->getTimezone()->getName());
        $this->assertSame('2026-11-18T23:59:59+00:00', $snapshot->startedAt->toIso8601String());
        $this->assertSame('2026-11-18', $snapshot->startedAt->toDateString());
        $this->assertSame('UTC', $snapshot->createdAt->getTimezone()->getName());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedShapes(): array
    {
        $envelope = self::pixelforgeFixture('event-generation-succeeded');

        return [
            'a whole webhook envelope' => [$envelope, 'object'],
            'id missing' => [self::queuedWithout('id'), 'id'],
            'id empty' => [self::queuedWith(['id' => '']), 'id'],
            'id not a string' => [self::queuedWith(['id' => 42]), 'id'],
            'object missing' => [self::queuedWithout('object'), 'object'],
            'status missing' => [self::queuedWithout('status'), 'status'],
            'status pending, which is ours' => [self::queuedWith(['status' => 'pending']), 'status'],
            'status in capitals' => [self::queuedWith(['status' => 'QUEUED']), 'status'],
            'status cancelled' => [self::queuedWith(['status' => 'cancelled']), 'status'],
            'size missing' => [self::queuedWithout('size'), 'size'],
            'size unsupported' => [self::queuedWith(['size' => '512x512']), 'size'],
            'quality missing' => [self::queuedWithout('quality'), 'quality'],
            'quality unknown' => [self::queuedWith(['quality' => 'ultra']), 'quality'],
            'metadata missing' => [self::queuedWithout('metadata'), 'metadata'],
            'metadata a string' => [self::queuedWith(['metadata' => 'x']), 'metadata'],
            'metadata.generation_id a number' => [self::queuedWith(['metadata' => ['generation_id' => 42]]), 'metadata.generation_id'],
            'created_at missing' => [self::queuedWithout('created_at'), 'created_at'],
            'created_at with a space' => [self::queuedWith(['created_at' => '2026-11-04 14:03:11']), 'created_at'],
            'created_at with milliseconds' => [self::queuedWith(['created_at' => '2026-11-04T14:03:11.250Z']), 'created_at'],
            'created_at on Feb 30' => [self::queuedWith(['created_at' => '2026-02-30T00:00:00Z']), 'created_at'],
            'created_at as a unix time' => [self::queuedWith(['created_at' => 1762265000]), 'created_at'],
            'started_at without a zone' => [self::queuedWith(['started_at' => '2026-11-04T14:03:13']), 'started_at'],
            'started_at with offset minutes past 59' => [self::queuedWith(['started_at' => '2026-11-04T14:03:13+14:60']), 'started_at'],
            'started_at with an offset past 14 hours' => [self::queuedWith(['started_at' => '2026-11-04T14:03:13+99:00']), 'started_at'],
            'error without a message' => [self::queuedWith(['error' => ['code' => 'internal_error']]), 'error.message'],
            'error a string' => [self::queuedWith(['error' => 'internal_error']), 'error'],
            'image_url empty' => [self::queuedWith(['image_url' => '']), 'image_url'],
            'id with a NUL byte' => [self::queuedWith(['id' => "gen_01JC2Z8Q\0X"]), 'id'],
            'error.code with a NUL byte' => [self::queuedWith(['error' => ['code' => "internal\0error", 'message' => 'Please retry.']]), 'error.code'],
            'error.message with a NUL byte' => [self::queuedWith(['error' => ['code' => 'internal_error', 'message' => "Please\0retry."]]), 'error.message'],
            'image_url with a NUL byte' => [self::queuedWith(['image_url' => "https://pixelforge.promptedit.dev/images/x.png\0?sig=1"]), 'image_url'],
            'usage a number' => [self::queuedWith(['usage' => 12]), 'usage'],
            'rates a string' => [self::queuedWith(['rates' => '5.00']), 'rates'],
        ];
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    #[DataProvider('malformedShapes')]
    public function test_a_malformed_object_is_rejected_naming_the_field(array $generation, string $path): void
    {
        $this->expectException(InvalidGenerationSnapshotException::class);
        $this->expectExceptionMessageMatches(sprintf('/(?<![\w.])%s(?!\w)/', preg_quote($path, '/')));

        GenerationSnapshot::from($generation);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function incompleteSuccesses(): array
    {
        return [
            'usage null' => [self::succeededWith(['usage' => null]), 'usage'],
            'rates absent' => [self::succeededWithout('rates'), 'rates'],
            'image_url null' => [self::succeededWith(['image_url' => null]), 'image_url'],
            'completed_at null' => [self::succeededWith(['completed_at' => null]), 'completed_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $generation
     */
    #[DataProvider('incompleteSuccesses')]
    public function test_a_succeeded_snapshot_without_its_result_is_rejected(array $generation, string $path): void
    {
        $this->expectException(InvalidGenerationSnapshotException::class);
        $this->expectExceptionMessageMatches(sprintf('/succeeded.*(?<![\w.])%s(?!\w)/', preg_quote($path, '/')));

        GenerationSnapshot::from($generation);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unpricedUsages(): array
    {
        $usage = self::succeededUsage();
        $withoutReasoning = $usage;
        unset($withoutReasoning['reasoning_tokens']);

        return [
            'an extra token type' => [[...$usage, 'cached_tokens' => 3], 'cached_tokens'],
            'reasoning_tokens missing' => [$withoutReasoning, 'reasoning_tokens'],
            'a total that does not add up' => [[...$usage, 'total_tokens' => 10_412], 'total_tokens'],
            'a count as a string' => [[...$usage, 'input_text_tokens' => '12'], 'input_text_tokens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    #[DataProvider('unpricedUsages')]
    public function test_a_usage_that_drops_or_misstates_a_token_type_is_rejected(array $usage, string $key): void
    {
        try {
            GenerationSnapshot::from(self::succeededWith(['usage' => $usage]));
            $this->fail('The usage was accepted.');
        } catch (InvalidGenerationSnapshotException $exception) {
            $this->assertStringContainsString('usage', $exception->getMessage());
            $this->assertStringContainsString($key, $exception->getMessage());
            $this->assertInstanceOf(InvalidTokenUsageException::class, $exception->getPrevious());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unpricedRates(): array
    {
        $rates = self::docsRates();
        $withoutOutput = $rates;
        unset($withoutOutput['output_tokens']);

        return [
            'an extra token type' => [[...$rates, 'cached_tokens' => '1.00'], 'rates.cached_tokens'],
            'output_tokens missing' => [$withoutOutput, 'rates.output_tokens'],
            'a rate as a number' => [[...$rates, 'input_text_tokens' => 5.00], 'rates.input_text_tokens'],
        ];
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    #[DataProvider('unpricedRates')]
    public function test_rates_that_drop_or_add_a_token_type_are_rejected(array $rates, string $path): void
    {
        $this->expectException(InvalidGenerationSnapshotException::class);
        $this->expectExceptionMessageMatches(sprintf('/(?<![\w.])%s(?!\w)/', preg_quote($path, '/')));

        GenerationSnapshot::from(self::succeededWith(['rates' => $rates]));
    }

    /**
     * Strings PE-001's TokenRates would refuse at settlement are refused here,
     * so a succeeded webhook is never stored in a state that can never settle.
     *
     * @return array<string, array{string}>
     */
    public static function malformedRates(): array
    {
        return [
            'letters' => ['abc'],
            'an empty string' => [''],
            'a trailing newline' => ["5.00\n"],
            'a negative rate' => ['-5.00'],
            'a bare decimal point' => ['5.'],
        ];
    }

    #[DataProvider('malformedRates')]
    public function test_a_rate_that_is_not_a_decimal_string_is_rejected(string $rate): void
    {
        $this->expectException(InvalidGenerationSnapshotException::class);
        $this->expectExceptionMessageMatches('/(?<![\w.])rates\.output_tokens(?!\w)/');

        GenerationSnapshot::from(self::succeededWith(['rates' => [...self::docsRates(), 'output_tokens' => $rate]]));
    }

    public function test_rate_strings_are_kept_verbatim_and_priced_only_at_settlement(): void
    {
        $rates = [...self::docsRates(), 'input_text_tokens' => '5.0001'];

        $this->assertSame($rates, GenerationSnapshot::from(self::succeededWith(['rates' => $rates]))->rates);
    }

    public function test_an_app_relative_image_url_is_accepted(): void
    {
        $snapshot = GenerationSnapshot::from(self::succeededWith(['image_url' => '/fake-pixelforge/images/gen_x.png']));

        $this->assertSame('/fake-pixelforge/images/gen_x.png', $snapshot->imageUrl);
    }

    public function test_a_failed_snapshot_without_an_error_parses_as_a_generic_failure(): void
    {
        $snapshot = GenerationSnapshot::from(self::queuedWith([
            'status' => 'failed',
            'error' => null,
            'completed_at' => '2026-11-04T14:03:29Z',
        ]));

        $this->assertSame(GenerationStatus::Failed, $snapshot->status);
        $this->assertNull($snapshot->errorCode);
        $this->assertNull($snapshot->errorMessage);
    }

    /**
     * @return array<string, mixed>
     */
    private static function succeededUsage(): array
    {
        $usage = self::pixelforgeFixture('generation-succeeded')['usage'];
        assert(is_array($usage));

        /** @var array<string, mixed> $usage */
        return $usage;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function succeededWith(array $overrides): array
    {
        return array_replace(self::pixelforgeFixture('generation-succeeded'), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function succeededWithout(string $field): array
    {
        $generation = self::pixelforgeFixture('generation-succeeded');
        unset($generation[$field]);

        return $generation;
    }

    /**
     * @return array<string, string>
     */
    private static function docsRates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function queuedWith(array $overrides): array
    {
        return array_replace(self::pixelforgeFixture('generation-queued'), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function queuedWithout(string $field): array
    {
        $generation = self::pixelforgeFixture('generation-queued');
        unset($generation[$field]);

        return $generation;
    }
}
