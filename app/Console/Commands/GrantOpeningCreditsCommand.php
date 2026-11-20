<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Actions\GrantOpeningCreditsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives every account without a grant its opening grant (D-70). Deploys run it after
 * `migrate` and before the new release goes live (deploy.php), so no balance is ever
 * served without a grant. Safe to re-run: it grants nobody twice.
 */
#[Signature('app:grant-opening-credits')]
#[Description('Grant the opening credit to every user who has no grant yet')]
final class GrantOpeningCreditsCommand extends Command
{
    public function handle(GrantOpeningCreditsAction $grantOpeningCredits): int
    {
        $granted = $grantOpeningCredits->execute();

        $this->components->info(sprintf('Granted opening credits to %d %s.', $granted, Str::plural('user', $granted)));
        Log::info('Opening credits granted by app:grant-opening-credits', ['users_granted' => $granted]);

        return self::SUCCESS;
    }
}
