<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Generation\Actions;

use App\Domain\Billing\Actions\EstimateGenerationCostAction;
use App\Domain\Billing\Models\CreditLedgerEntry;
use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Exceptions\InvalidGenerationRequestException;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\InteractsWithGenerations;
use Tests\TestCase;

final class CreateGenerationActionTest extends TestCase
{
    use InteractsWithCreditLedger;
    use InteractsWithGenerations;
    use RefreshDatabase;

    private const string WORKED_EXAMPLE_PROMPT = 'A lighthouse on a cliff at dawn, in oils';

    private const array PROVIDER_COLUMNS = [
        'provider_id',
        'input_text_tokens',
        'input_image_tokens',
        'output_tokens',
        'reasoning_tokens',
        'rates',
        'provider_cost_nanodollars',
        'user_charge_nanodollars',
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

        $generation = $this->createGenerationFor($user, new NewGenerationData(
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
            $this->createGenerationFor($user, new NewGenerationData(
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

    public function test_the_lighthouse_generation_holds_its_stored_max_user_estimate(): void
    {
        $this->assertSame(45, mb_strlen(self::LIGHTHOUSE_PROMPT));

        $generation = $this->givenTheLighthouseGeneration();

        $this->assertSame(457_150_000, $this->reloadedGeneration($generation)->user_estimate_max_nanodollars);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
        $this->assertSame(
            [$generation->user_id],
            DB::table('credit_ledger_entries')->where('generation_id', $generation->id)->pluck('user_id')->all(),
        );
    }

    /**
     * The docs' maximum case: a max provider cost of 373,875,000 n$.
     */
    public function test_the_docs_maximum_case_holds_its_max_user_estimate(): void
    {
        $prompt = str_repeat('a', 400);
        $this->assertSame(400, mb_strlen($prompt));

        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData(
            $prompt,
            ImageSize::Landscape,
            ImageQuality::High,
            ['https://example.com/one.png', 'https://example.com/two.png'],
        ));

        $this->assertSame([['hold', -467_343_750]], $this->ledgerEntriesFor($generation));
    }

    public function test_a_failing_hold_write_leaves_no_row_and_no_entry(): void
    {
        CreditLedgerEntry::created(function (): void {
            throw new RuntimeException('The hold write failed.');
        });

        try {
            $this->givenTheLighthouseGeneration();
            $this->fail('The creation did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The hold write failed.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('generations')->count());
        $this->assertSame(0, DB::table('credit_ledger_entries')->count());
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
        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData(
            $prompt,
            ImageSize::Square,
            ImageQuality::Low,
            [],
        ));

        $this->assertSame($prompt, DB::table('generations')->where('id', $generation->getKey())->value('prompt'));
        $this->assertSame($prompt, $this->reloadedGeneration($generation)->prompt);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function promptsWithANulByte(): array
    {
        return [
            'at the start' => ["\0ab"],
            'in the middle' => ["a\0b"],
            'at the end' => ["ab\0"],
        ];
    }

    /**
     * Postgres text cannot hold U+0000 and pdo_pgsql would store the prompt
     * cut at it, so the stored prompt would disagree with its estimate.
     */
    #[DataProvider('promptsWithANulByte')]
    public function test_a_prompt_with_a_nul_byte_is_refused_before_anything_is_written(string $prompt): void
    {
        $user = User::factory()->create();

        try {
            $this->createGenerationFor($user, new NewGenerationData($prompt, ImageSize::Square, ImageQuality::Low, []));
            $this->fail('The prompt with a NUL byte was accepted.');
        } catch (InvalidGenerationRequestException $exception) {
            $this->assertSame('The prompt contains a NUL byte, which cannot be stored.', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('generations')->count());
    }

    /**
     * @return array<string, array{list<string>, int}>
     */
    public static function referenceImagesWithANulByte(): array
    {
        return [
            'at the start' => [["\0https://a.example/x.png"], 1],
            'in the middle' => [["https://a.example/x\0.png"], 1],
            'at the end' => [["https://a.example/x.png\0"], 1],
            'in the second of two' => [['https://a.example/x.png', "https://b.example/y\0.png"], 2],
        ];
    }

    /**
     * The json column would keep it as \u0000, but no URL holds a NUL byte,
     * so it is refused like the prompt rather than sent to Pixelforge.
     *
     * @param  list<string>  $referenceImages
     */
    #[DataProvider('referenceImagesWithANulByte')]
    public function test_a_reference_image_with_a_nul_byte_is_refused_before_anything_is_written(array $referenceImages, int $position): void
    {
        $user = User::factory()->create();

        try {
            $this->createGenerationFor($user, new NewGenerationData('a cat', ImageSize::Square, ImageQuality::Low, $referenceImages));
            $this->fail('The reference image with a NUL byte was accepted.');
        } catch (InvalidGenerationRequestException $exception) {
            $this->assertSame(
                sprintf('Reference image %d contains a NUL byte, which cannot be stored.', $position),
                $exception->getMessage(),
            );
        }

        $this->assertSame(0, DB::table('generations')->count());
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
        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData(
            'a cat',
            ImageSize::Portrait,
            ImageQuality::High,
            $referenceImages,
        ));

        $reloaded = $this->reloadedGeneration($generation);

        $this->assertSame($referenceImages, $reloaded->reference_images);
        $this->assertSame(ImageSize::Portrait, $reloaded->size);
        $this->assertSame(ImageQuality::High, $reloaded->quality);
    }

    public function test_one_form_submission_sent_twice_creates_one_generation_one_hold_and_one_job(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();

        $first = $this->createGenerationFor($user, self::lighthouseSubmittedWith($token));
        $replay = $this->createGenerationFor($user, self::lighthouseSubmittedWith($token));

        $this->assertSame($first->id, $replay->id);
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($replay->wasRecentlyCreated);
        $this->assertSame(1, DB::table('generations')->count());
        $this->assertSame($token, $this->reloadedGeneration($first)->submission_token);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($first));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generationId === $first->id);
    }

    /**
     * The token names one user's form submission, so two users can never collide.
     */
    public function test_another_users_submission_with_the_same_token_creates_its_own_generation(): void
    {
        $token = (string) Str::uuid();

        $mine = $this->createGenerationFor(User::factory()->create(), self::lighthouseSubmittedWith($token));
        $theirs = $this->createGenerationFor(User::factory()->create(), self::lighthouseSubmittedWith($token));

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame(2, DB::table('generations')->count());
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($theirs));
        Queue::assertPushed(SubmitGenerationJob::class, 2);
    }

    /**
     * Rows not created from the form (factories, scripts) have no token, and any
     * number of them may exist side by side.
     */
    public function test_requests_without_a_token_each_create_a_generation(): void
    {
        $user = User::factory()->create();

        $first = $this->createGenerationFor($user, self::lighthouseRequest());
        $second = $this->createGenerationFor($user, self::lighthouseRequest());

        $this->assertNotSame($first->id, $second->id);
        $this->assertNull($this->reloadedGeneration($first)->submission_token);
        $this->assertSame(2, DB::table('generations')->count());
        Queue::assertPushed(SubmitGenerationJob::class, 2);
    }

    /**
     * The first request of a form submission wins: a replay carrying other fields
     * gets the stored generation back, unchanged, with no second hold.
     */
    public function test_a_replay_with_other_fields_returns_the_first_generation_unchanged(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();
        $first = $this->createGenerationFor($user, self::lighthouseSubmittedWith($token));
        $storedRow = $this->rawGenerationRow($first);

        $replay = $this->createGenerationFor($user, new NewGenerationData(
            'A castle',
            ImageSize::Square,
            ImageQuality::Low,
            [],
            $token,
        ));

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(self::LIGHTHOUSE_PROMPT, $replay->prompt);
        $this->assertSame($storedRow, $this->rawGenerationRow($first));
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($first));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    }

    private static function lighthouseSubmittedWith(string $token): NewGenerationData
    {
        $request = self::lighthouseRequest();

        return new NewGenerationData($request->prompt, $request->size, $request->quality, $request->referenceImages, $token);
    }
}
