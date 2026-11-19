<?php

declare(strict_types=1);

namespace Tests\Feature\Generation;

use App\Domain\Generation\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SubmitsGenerations;
use Tests\TestCase;

/**
 * Submissions are throttled per user (D-39): over the limit, the user stays on
 * Generate with an inline message, never a bare 429, and nothing is created.
 */
final class CreateGenerationThrottleTest extends TestCase
{
    use RefreshDatabase;
    use SubmitsGenerations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    public function test_the_eleventh_submission_in_a_minute_is_refused_inline_and_creates_nothing(): void
    {
        $user = User::factory()->create();
        $this->givenSubmissions(10, $user);

        $this->travel(18)->seconds();

        $this->submitGeneration(as: $user)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['submission' => "You're submitting too quickly. Try again in 42 s."]);

        $this->assertSame(10, Generation::query()->count());
    }

    public function test_submissions_work_again_once_the_minute_has_passed(): void
    {
        $user = User::factory()->create();
        $this->givenSubmissions(10, $user);

        $this->travel(61)->seconds();

        $this->submitGeneration(as: $user)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('generations.index'));

        $this->assertSame(11, Generation::query()->count());
    }

    public function test_the_limit_is_configurable(): void
    {
        config(['generations.submissions_per_minute' => 2]);
        $user = User::factory()->create();
        $this->givenSubmissions(2, $user);

        $this->submitGeneration(as: $user)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['submission' => "You're submitting too quickly. Try again in 60 s."]);

        $this->assertSame(2, Generation::query()->count());
    }

    public function test_the_limit_defaults_to_ten_a_minute(): void
    {
        $this->assertSame(10, config('generations.submissions_per_minute'));
    }

    public function test_each_user_has_their_own_limit(): void
    {
        $this->givenSubmissions(10, User::factory()->create());

        $this->submitGeneration(as: User::factory()->create())->assertSessionHasNoErrors();

        $this->assertSame(11, Generation::query()->count());
    }

    private function givenSubmissions(int $count, User $user): void
    {
        for ($submission = 1; $submission <= $count; $submission++) {
            $this->submitGeneration(as: $user)->assertSessionHasNoErrors();
        }
    }
}
