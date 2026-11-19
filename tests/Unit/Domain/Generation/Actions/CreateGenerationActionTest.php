<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Generation\Actions\CreateGenerationAction;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class CreateGenerationActionTest extends TestCase
{
    use RefreshDatabase;

    private const string WORKED_EXAMPLE_PROMPT = 'A lighthouse on a cliff at dawn, in oils';

    private const array PROVIDER_COLUMNS = [
        'provider_id',
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
        'rates',
        'error_code',
        'error_message',
        'error_param',
        'local_failure_reason',
        'image_url',
        'image_url_expires_at',
        'provider_created_at',
        'started_at',
        'completed_at',
    ];

    public function test_it_writes_a_pending_row_with_the_server_side_estimate(): void
    {
        $user = User::factory()->create();

        $generation = $this->createGeneration($user, new NewGenerationData(
            self::WORKED_EXAMPLE_PROMPT,
            ImageSize::Landscape,
            ImageQuality::Medium,
            ['https://example.com/style.png'],
        ));

        $estimate = $this->app->make(EstimateGenerationCostAction::class)
            ->execute(self::WORKED_EXAMPLE_PROMPT, ImageQuality::Medium, ImageSize::Landscape, 1);
        $this->assertSame(40, mb_strlen(self::WORKED_EXAMPLE_PROMPT));
        $this->assertSame(
            [76_190_000, 87_450_000, 98_710_000],
            [$estimate->providerCost->min->amount, $estimate->providerCost->typical->amount, $estimate->providerCost->max->amount],
        );

        $row = (array) DB::table('generations')->where('id', $generation->getKey())->first();

        $this->assertSame(1, DB::table('generations')->count());
        $this->assertSame($user->getKey(), $row['user_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('1536x1024', $row['size']);
        $this->assertSame('medium', $row['quality']);
        $this->assertSame(95_237_500, $row['user_estimate_min_nanodollars']);
        $this->assertSame(109_312_500, $row['user_estimate_typical_nanodollars']);
        $this->assertSame(123_387_500, $row['user_estimate_max_nanodollars']);

        foreach (self::PROVIDER_COLUMNS as $column) {
            $this->assertNull($row[$column], sprintf('%s is set on a new generation.', $column));
        }

        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $generation->getKey());
    }

    public function test_a_failing_write_leaves_no_row(): void
    {
        $user = User::factory()->create();
        Generation::created(function (): void {
            throw new RuntimeException('A write inside the creation transaction failed.');
        });

        try {
            $this->createGeneration($user, new NewGenerationData(
                self::WORKED_EXAMPLE_PROMPT,
                ImageSize::Landscape,
                ImageQuality::Medium,
                [],
            ));
            $this->fail('The creation did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A write inside the creation transaction failed.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('generations')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function prompts(): array
    {
        return [
            'surrounding spaces' => ['  a cat  '],
            'emoji with a skin-tone modifier' => ['🎨 👍🏽'],
            'decomposed é' => ["cafe\u{0301}"],
            'Japanese' => ['東京の夜景'],
            'a newline' => ["a\nb"],
        ];
    }

    #[DataProvider('prompts')]
    public function test_the_prompt_reads_back_byte_identical(string $prompt): void
    {
        $generation = $this->createGeneration(User::factory()->create(), new NewGenerationData(
            $prompt,
            ImageSize::Square,
            ImageQuality::Low,
            [],
        ));

        $this->assertSame($prompt, DB::table('generations')->where('id', $generation->getKey())->value('prompt'));
        $this->assertSame($prompt, $this->reloaded($generation)->prompt);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function referenceImages(): array
    {
        return [
            'query strings, in the given order' => [['https://b.example/x.png?s=1&t=%20', 'https://a.example/y.png']],
            'none' => [[]],
        ];
    }

    /**
     * @param  list<string>  $referenceImages
     */
    #[DataProvider('referenceImages')]
    public function test_the_reference_images_read_back_in_order(array $referenceImages): void
    {
        $generation = $this->createGeneration(User::factory()->create(), new NewGenerationData(
            'a cat',
            ImageSize::Portrait,
            ImageQuality::High,
            $referenceImages,
        ));

        $reloaded = $this->reloaded($generation);

        $this->assertSame($referenceImages, $reloaded->reference_images);
        $this->assertSame(ImageSize::Portrait, $reloaded->size);
        $this->assertSame(ImageQuality::High, $reloaded->quality);
    }

    private function createGeneration(User $user, NewGenerationData $data): Generation
    {
        return $this->app->make(CreateGenerationAction::class)->execute($user, $data);
    }

    private function reloaded(Generation $generation): Generation
    {
        return Generation::query()->whereKey($generation->getKey())->firstOrFail();
    }
}
