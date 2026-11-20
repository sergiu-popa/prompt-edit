<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Jobs\SubmitGenerationJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\Concerns\SubmitsGenerations;
use Tests\TestCase;

final class CreateGenerationTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;
    use SubmitsGenerations;

    private const string LIGHTHOUSE_PROMPT = 'A lighthouse on a cliff at dawn, oil painting';

    public function test_lighthouse_creates_one_pending_generation_with_the_server_estimate_its_hold_and_its_job(): void
    {
        $user = User::factory()->create();

        $response = $this->submitGeneration(as: $user);

        $generation = Generation::query()->sole();
        $response->assertRedirect(route('generations.index'));
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(self::LIGHTHOUSE_PROMPT, $generation->prompt);
        $this->assertSame(['https://example.com/style.png'], $generation->reference_images);
        $this->assertSame(
            [347_750_000, 402_450_000, 457_150_000],
            [$generation->user_estimate_min_nanodollars, $generation->user_estimate_typical_nanodollars, $generation->user_estimate_max_nanodollars],
        );
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
        Queue::assertPushed(SubmitGenerationJob::class, fn (SubmitGenerationJob $job): bool => $job->generationId === $generation->id);
    }

    public function test_history_highlights_the_generation_just_created(): void
    {
        $this->followingRedirects()
            ->submitGeneration()
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations/Index')
                ->hasFlash('highlighted_generation_id', Generation::query()->sole()->id));
    }

    /**
     * 50 code points, 54 UTF-16 units and 62 bytes: e = 13, as the Generate page counts it.
     * UTF-16 units would give 457,168,750 and bytes 457,181,250.
     */
    public function test_a_unicode_prompt_stores_the_max_the_page_showed(): void
    {
        $prompt = self::LIGHTHOUSE_PROMPT . ' 🎨🎨🎨🎨';
        $this->assertSame([50, 62], [mb_strlen($prompt), strlen($prompt)]);

        $this->submitGeneration(['prompt' => $prompt]);

        $generation = Generation::query()->sole();
        $this->assertSame($prompt, $generation->prompt);
        $this->assertSame(457_156_250, $generation->user_estimate_max_nanodollars);
        $this->assertSame([['hold', -457_156_250]], $this->ledgerEntriesFor($generation));
    }

    /**
     * The prompt is kept exactly as typed (D-37): 49 code points, as the page counts it,
     * so the stored row, the hold and the Pixelforge request all use the same string.
     */
    public function test_leading_spaces_are_kept_in_the_stored_prompt_the_estimate_and_the_hold(): void
    {
        $prompt = '    ' . self::LIGHTHOUSE_PROMPT;

        $this->submitGeneration(['prompt' => $prompt]);

        $generation = Generation::query()->sole();
        $this->assertSame($prompt, DB::table('generations')->value('prompt'));
        $this->assertSame(457_156_250, $generation->user_estimate_max_nanodollars);
        $this->assertSame([['hold', -457_156_250]], $this->ledgerEntriesFor($generation));
    }

    /**
     * Reference URLs are still trimmed: whitespace around a URL never changes the price.
     */
    public function test_reference_urls_are_trimmed(): void
    {
        $this->submitGeneration(['reference_images' => ['  https://example.com/style.png  ']])->assertSessionHasNoErrors();

        $this->assertSame(['https://example.com/style.png'], Generation::query()->sole()->reference_images);
    }

    /**
     * The client cannot set money, ownership or metadata (AC 4).
     */
    public function test_fields_beyond_the_form_are_ignored(): void
    {
        $user = User::factory()->create();
        $someoneElse = User::factory()->create();

        $this->submitGeneration([
            'estimate' => ['min' => 1, 'typical' => 1, 'max' => 1],
            'user_estimate_max_nanodollars' => 1,
            'cost' => 1,
            'user_id' => $someoneElse->id,
            'metadata' => ['generation_id' => 'forged'],
            'status' => 'succeeded',
            'provider_id' => 'gen_forged',
        ], $user)->assertSessionHasNoErrors();

        $generation = Generation::query()->sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertNull($generation->provider_id);
        $this->assertSame(457_150_000, $generation->user_estimate_max_nanodollars);
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
    }

    /**
     * A double click or a resent request: both land on History, with one generation.
     */
    public function test_the_same_form_submission_twice_creates_one_generation_one_hold_and_one_job(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();

        $this->submitGeneration(['submission_token' => $token], $user)->assertRedirect(route('generations.index'));
        $this->submitGeneration(['submission_token' => $token], $user)->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        $this->assertSame([['hold', -457_150_000]], $this->ledgerEntriesFor($generation));
        Queue::assertPushed(SubmitGenerationJob::class, 1);
    }

    public function test_a_replay_highlights_the_first_generation(): void
    {
        $user = User::factory()->create();
        $token = (string) Str::uuid();
        $this->submitGeneration(['submission_token' => $token], $user);

        $this->followingRedirects()
            ->submitGeneration(['submission_token' => $token], $user)
            ->assertInertia(fn (Assert $page) => $page->hasFlash('highlighted_generation_id', Generation::query()->sole()->id));
    }

    /**
     * Going back to Generate mounts a new form, so the same prompt again is a new generation.
     */
    public function test_a_new_form_submission_of_the_same_prompt_creates_a_second_generation(): void
    {
        $user = User::factory()->create();

        $this->submitGeneration(as: $user);
        $this->submitGeneration(as: $user);

        $this->assertSame(2, Generation::query()->where('user_id', $user->id)->count());
        Queue::assertPushed(SubmitGenerationJob::class, 2);
    }

    public function test_a_guest_is_sent_to_sign_in_and_nothing_is_created(): void
    {
        $this->post(route('generations.store'), $this->lighthouseFields())->assertRedirect('/login');

        $this->assertNothingWasCreated();
    }

    /**
     * A generation accepted now could never be submitted, so nothing is written (D-40).
     */
    public function test_a_submission_is_refused_while_pixelforge_is_not_configured(): void
    {
        config(['pixelforge.api_key' => null]);

        $this->submitGeneration()
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['submission' => "Image generation isn't available yet."]);

        $this->assertNothingWasCreated();
    }
}
