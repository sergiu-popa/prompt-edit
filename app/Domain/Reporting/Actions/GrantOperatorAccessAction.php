<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Actions;

use App\Models\User;

/**
 * The one writer of `users.is_operator` (D-62): `app:grant-operator` on production, and the
 * local operator seeder. It never creates a user or touches a password.
 */
final class GrantOperatorAccessAction
{
    /**
     * @return bool true when the user became an operator, false when they already were one
     */
    public function execute(User $user): bool
    {
        if ($user->is_operator) {
            return false;
        }

        $user->is_operator = true;
        $user->save();

        return true;
    }
}
