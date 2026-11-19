<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::loginView(fn (): Response => Inertia::render('Auth/Login'));
        Fortify::registerView(fn (): Response => Inertia::render('Auth/Register'));

        /*
         * Fortify always registers GET /user/confirm-password, but no page asks for a
         * password confirmation, so it is not found rather than a missing view.
         */
        Fortify::confirmPasswordView(fn () => abort(404));
    }
}
