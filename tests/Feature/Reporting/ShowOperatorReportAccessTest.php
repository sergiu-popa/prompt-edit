<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * AC 1: a guest is sent to Sign in, a signed-in non-operator gets the stock 403 page with
 * the gate's message (D-63), and an operator gets the report.
 */
final class ShowOperatorReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_sign_in(): void
    {
        $this->get('/report')->assertRedirect(route('login'));
    }

    public function test_a_non_operator_gets_403_with_the_message(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/report')
            ->assertForbidden()
            ->assertSee("You don't have access to this page.", escape: true);
    }

    public function test_a_non_operator_gets_the_same_403_on_an_inertia_visit(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/report', [
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Inertia-Version' => (string) $this->app->make(HandleInertiaRequests::class)->version(Request::create('/')),
            ])
            ->assertForbidden()
            ->assertHeaderMissing('X-Inertia')
            ->assertSee("You don't have access to this page.", escape: true);
    }

    public function test_an_operator_gets_the_report(): void
    {
        $this->actingAs(User::factory()->operator()->create())
            ->get('/report')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Reporting/Show'));
    }
}
