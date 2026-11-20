<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reporting\Actions\GrantOperatorAccessAction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Grants operator access to an existing user by email, run by hand on production after
 * deploy (D-62). Running it again is harmless and succeeds. It never creates a user or sets
 * a password: an unknown email fails and writes nothing.
 */
#[Signature('app:grant-operator {email : The account email (trimmed and lowercased)}')]
#[Description('Grant operator access, which opens the operator report, to an existing user')]
final class GrantOperatorAccessCommand extends Command
{
    public function handle(GrantOperatorAccessAction $grantOperatorAccess): int
    {
        $argument = $this->argument('email');
        $email = Str::lower(trim(is_string($argument) ? $argument : ''));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No user has the email {$email}. This command never creates users: create the account first (README, \"Reviewer account\").");

            return self::FAILURE;
        }

        if (! $grantOperatorAccess->execute($user)) {
            $this->components->info("{$user->email} is already an operator.");

            return self::SUCCESS;
        }

        $this->components->info("Granted operator access to {$user->email}.");
        Log::info('Operator access granted by app:grant-operator', ['email' => $user->email, 'user_id' => $user->id]);

        return self::SUCCESS;
    }
}
