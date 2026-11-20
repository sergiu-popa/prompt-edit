<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Actions\CheckGenerationMoneyAction;
use App\Domain\Billing\ValueObjects\Nanodollars;
use App\Domain\Generation\Models\Generation;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-checks the money of every generation created since an instant (PE-015 AC 22),
 * oldest first, read-only. It prints each row's test keywords but never its prompt,
 * then the totals, and fails when any row has a problem or none matched.
 */
#[Signature('app:check-generation-money {--since= : Check generations created at or after this instant (default: 24 hours ago)}')]
#[Description('Check the money of recent generations against their ledger and estimate')]
final class CheckGenerationMoneyCommand extends Command
{
    private const string KEYWORD_PATTERN = '/\[[a-z-]+\]/';

    public function handle(CheckGenerationMoneyAction $checkGenerationMoney): int
    {
        $since = $this->since();

        if ($since === null) {
            $this->components->error('--since must be a date and time, such as 2026-11-20T15:04:14Z.');

            return self::FAILURE;
        }

        $generations = Generation::query()
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($generations->isEmpty()) {
            $this->components->error(sprintf('No generation was created since %s.', $since->toIso8601ZuluString()));

            return self::FAILURE;
        }

        $rows = [];
        $hasProblems = false;
        $providerTotal = Nanodollars::zero();
        $chargeTotal = Nanodollars::zero();

        foreach ($generations as $generation) {
            $problems = $checkGenerationMoney->execute($generation);
            $hasProblems = $hasProblems || $problems !== [];
            $providerTotal = $providerTotal->plus(new Nanodollars($generation->provider_cost_nanodollars ?? 0));
            $chargeTotal = $chargeTotal->plus(new Nanodollars($generation->user_charge_nanodollars ?? 0));

            $rows[] = [
                $generation->id,
                self::keywords($generation->prompt),
                sprintf('%s · %s · %d refs', $generation->size->value, $generation->quality->value, count($generation->reference_images)),
                $generation->status->value,
                $generation->local_failure_reason->value ?? $generation->error_code ?? '',
                self::amount($generation->provider_cost_nanodollars),
                self::amount($generation->user_charge_nanodollars),
                $problems === [] ? 'OK' : implode('; ', $problems),
            ];
        }

        $this->table(['Generation', 'Keywords', 'Request', 'Status', 'Failure', 'Provider n$', 'Charge n$', 'Check'], $rows);
        $this->line(sprintf(
            'Total of %d: provider %d n$ ($%s), charge %d n$ ($%s).',
            $generations->count(),
            $providerTotal->amount,
            $providerTotal->toUsdString(),
            $chargeTotal->amount,
            $chargeTotal->toUsdString(),
        ));

        return $hasProblems ? self::FAILURE : self::SUCCESS;
    }

    private function since(): ?CarbonImmutable
    {
        $since = $this->option('since');

        if ($since === null) {
            return CarbonImmutable::now()->subDay();
        }

        if (! is_string($since) || trim($since) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($since)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Only the `[keyword]` tokens, so no prompt text reaches a terminal or a PR comment.
     */
    private static function keywords(string $prompt): string
    {
        preg_match_all(self::KEYWORD_PATTERN, $prompt, $matches);

        return implode(' ', $matches[0]);
    }

    private static function amount(?int $nanodollars): string
    {
        return $nanodollars === null ? '' : (string) $nanodollars;
    }
}
