<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AC 2: the Report link is shared only with operators (D-63), so a non-operator's page
 * never even contains the report's URL.
 */
final class OperatorNavigationPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_gets_no_operator_navigation(): void
    {
        $response = $this->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('operator_navigation', []));

        $this->assertNeverMentionsTheReport($response);
    }

    public function test_a_non_operator_gets_no_operator_navigation(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('operator_navigation', []));

        $this->assertNeverMentionsTheReport($response);
    }

    public function test_an_operator_gets_exactly_the_report_link(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('operator_navigation', 1)
                ->has('operator_navigation.0', fn (Assert $link) => $link
                    ->where('label', 'Report')
                    ->where('href', '/report')));
    }

    private function assertNeverMentionsTheReport(TestResponse $response): void
    {
        $this->assertStringNotContainsString('/report', (string) json_encode($response->viewData('page')));
    }
}
