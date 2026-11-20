<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * History's `generations` prop as a signed-in user's visit resolves it.
 *
 * @phpstan-require-extends TestCase
 */
trait ReadsGenerationHistory
{
    /**
     * @return array{data: list<array<string, mixed>>, links: array<string, ?string>, meta: array<string, mixed>, server_time: string}
     */
    protected function historyFor(User $user, int $page = 1): array
    {
        $history = null;

        $this->actingAs($user)
            ->get(route('generations.index', $page === 1 ? [] : ['page' => $page]))
            ->assertOk()
            ->assertInertia(function (Assert $inertia) use (&$history): void {
                $history = $inertia->toArray()['props']['generations'];
            });

        return $history;
    }

    /**
     * The one item of a user's History.
     *
     * @return array<string, mixed>
     */
    protected function onlyHistoryItemOf(User $user): array
    {
        $data = $this->historyFor($user)['data'];

        $this->assertCount(1, $data);

        return $data[0];
    }
}
