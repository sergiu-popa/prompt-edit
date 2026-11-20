<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Generation\DataTransferObjects\NewGenerationData;
use App\Domain\Generation\Enums\GenerationStatus;
use App\Domain\Generation\Models\Generation;
use App\Domain\Shared\Enums\ImageQuality;
use App\Domain\Shared\Enums\ImageSize;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithGenerations;
use Tests\Concerns\InteractsWithGenerationSnapshots;
use Tests\TestCase;

/**
 * `app:check-generation-money` prints one checked row per recent generation, oldest
 * first, with its keywords but never its prompt, then the totals; it fails on any
 * problem or when nothing matched.
 */
final class CheckGenerationMoneyCommandTest extends TestCase
{
    use InteractsWithGenerations;
    use InteractsWithGenerationSnapshots;
    use RefreshDatabase;

    private const array RED_FOX_USAGE = [
        'input_text_tokens' => 5,
        'input_image_tokens' => 0,
        'output_tokens' => 280,
        'reasoning_tokens' => 312,
        'total_tokens' => 597,
    ];

    private const string FAILING_PROMPT = '[fail] a broken pocket watch';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20T15:00:00Z'));
    }

    public function test_good_rows_pass_with_their_keywords_and_the_totals(): void
    {
        $this->givenTheSucceededRedFox();
        $this->travel(1)->minutes();
        $this->givenAFailedGeneration();

        [$exitCode, $output] = $this->check();

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, substr_count($output, ' OK '));
        $this->assertStringContainsString('[succeed]', $output);
        $this->assertStringContainsString('[fail]', $output);
        $this->assertStringContainsString('generation_failed', $output);
        $this->assertStringContainsString('1024x1024 · low · 0 refs', $output);
        $this->assertStringContainsString('Total of 2: provider 15125000 n$ ($0.015125000), charge 18906250 n$ ($0.018906250).', $output);
        $this->assertLessThan(strpos($output, '[fail]'), strpos($output, '[succeed]'), 'Oldest first.');
    }

    public function test_it_never_prints_the_prompt_text(): void
    {
        $this->givenTheSucceededRedFox();
        $this->givenAFailedGeneration();

        [, $output] = $this->check();

        $this->assertStringNotContainsString('red fox', $output);
        $this->assertStringNotContainsString('pocket watch', $output);
    }

    public function test_a_tampered_row_fails_with_its_problem(): void
    {
        $redFox = $this->givenTheSucceededRedFox();
        DB::table('generations')->where('id', $redFox->id)->update(['user_charge_nanodollars' => 18_906_251]);

        [$exitCode, $output] = $this->check();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('the charge is 18906251 n$, expected 18906250 n$', $output);
    }

    public function test_since_leaves_out_older_generations(): void
    {
        $this->givenTheSucceededRedFox();
        $this->travel(2)->hours();
        $this->givenAFailedGeneration();

        [$exitCode, $output] = $this->check(['--since' => '2026-11-20T16:00:00Z']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('[succeed]', $output);
        $this->assertStringContainsString('Total of 1:', $output);
    }

    public function test_by_default_it_checks_the_last_24_hours(): void
    {
        $this->givenTheSucceededRedFox();
        $this->travel(25)->hours();

        [$exitCode, $output] = $this->check();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No generation was created since 2026-11-20T16:00:00Z.', $output);
    }

    public function test_none_matched_fails(): void
    {
        [$exitCode, $output] = $this->check(['--since' => '2026-11-20T00:00:00Z']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No generation was created since 2026-11-20T00:00:00Z.', $output);
    }

    public function test_an_unreadable_since_fails(): void
    {
        [$exitCode, $output] = $this->check(['--since' => 'not a date']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--since must be a date and time', $output);
    }

    private function givenTheSucceededRedFox(): Generation
    {
        $redFox = $this->givenTheRedFoxGeneration();
        $this->transitionGeneration($redFox, self::snapshotFor($redFox, GenerationStatus::Succeeded, ['usage' => self::RED_FOX_USAGE]));

        return $redFox;
    }

    private function givenAFailedGeneration(): Generation
    {
        $generation = $this->createGenerationFor(User::factory()->create(), new NewGenerationData(self::FAILING_PROMPT, ImageSize::Square, ImageQuality::Low, []));
        $this->transitionGeneration($generation, self::snapshotFor($generation, GenerationStatus::Failed));

        return $generation;
    }

    /**
     * @param  array<string, string>  $options
     * @return array{int, string}
     */
    private function check(array $options = []): array
    {
        $exitCode = Artisan::call('app:check-generation-money', $options);

        return [$exitCode, Artisan::output()];
    }
}
