<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Generation\Actions\ReconcileGenerationsAction;
use Tests\TestCase;

/**
 * One run of the scheduled reconciler, as the command runs it.
 *
 * @phpstan-require-extends TestCase
 */
trait RunsTheReconciler
{
    protected function runReconciler(): void
    {
        $this->app->make(ReconcileGenerationsAction::class)->execute();
    }
}
