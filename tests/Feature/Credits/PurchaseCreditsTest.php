<?php

declare(strict_types=1);

namespace Tests\Feature\Credits;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithCreditLedger;
use Tests\TestCase;

/**
 * AC 15, 16: the fake Buy credits pack. The users start without a grant, so every
 * top-up listed is the purchase under test.
 */
final class PurchaseCreditsTest extends TestCase
{
    use InteractsWithCreditLedger;
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_sign_in_and_nothing_is_recorded(): void
    {
        $this->post(route('credits.purchases.store'), ['pack_nanodollars' => 1_000_000_000, 'purchase_token' => (string) Str::uuid()])
            ->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('credit_ledger_entries')->count());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function packs(): array
    {
        return [
            '$1.00' => [1_000_000_000],
            '$5.00' => [5_000_000_000],
            '$10.00' => [10_000_000_000],
        ];
    }

    #[DataProvider('packs')]
    public function test_each_pack_adds_one_purchase_of_its_amount_and_flashes_it(int $pack): void
    {
        $user = User::factory()->withoutCredits()->create();

        $this->purchase($user, $pack, (string) Str::uuid())
            ->assertRedirect('/credits')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('purchased_nanodollars', $pack);

        $this->assertSame([['purchase', $pack]], $this->topUpsFor($user));
    }

    /**
     * A double click or a resent request: the first wins, even naming another pack.
     */
    public function test_the_same_token_twice_records_one_purchase_and_the_replay_flashes_the_first(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $token = (string) Str::uuid();
        $this->purchase($user, 1_000_000_000, $token);

        $this->purchase($user, 10_000_000_000, $token)
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('purchased_nanodollars', 1_000_000_000);

        $this->assertSame([['purchase', 1_000_000_000]], $this->topUpsFor($user));
    }

    public function test_a_new_token_records_a_second_purchase(): void
    {
        $user = User::factory()->withoutCredits()->create();
        $this->purchase($user, 1_000_000_000, (string) Str::uuid());

        $this->purchase($user, 5_000_000_000, (string) Str::uuid());

        $this->assertSame([['purchase', 1_000_000_000], ['purchase', 5_000_000_000]], $this->topUpsFor($user));
    }

    public function test_another_users_token_never_blocks_a_purchase(): void
    {
        $token = (string) Str::uuid();
        $first = User::factory()->withoutCredits()->create();
        $second = User::factory()->withoutCredits()->create();
        $this->purchase($first, 1_000_000_000, $token);

        $this->purchase($second, 5_000_000_000, $token);

        $this->assertSame([['purchase', 5_000_000_000]], $this->topUpsFor($second));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidPurchases(): array
    {
        return [
            'an unknown pack' => [['pack_nanodollars' => 2_000_000_000], 'pack_nanodollars', 'Choose one of the packs.'],
            'a fraction of a pack' => [['pack_nanodollars' => '1000000000.5'], 'pack_nanodollars', 'Choose one of the packs.'],
            'no pack' => [['pack_nanodollars' => null], 'pack_nanodollars', 'Choose one of the packs.'],
            'a token that is not a UUID' => [['purchase_token' => 'not-a-token'], 'purchase_token', 'Reload the page and try again.'],
            'no token' => [['purchase_token' => null], 'purchase_token', 'Reload the page and try again.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPurchases')]
    public function test_an_invalid_purchase_is_a_field_error_and_records_nothing(array $overrides, string $field, string $message): void
    {
        $user = User::factory()->withoutCredits()->create();
        $fields = array_filter(
            [...['pack_nanodollars' => 1_000_000_000, 'purchase_token' => (string) Str::uuid()], ...$overrides],
            fn (mixed $value): bool => $value !== null,
        );

        $this->actingAs($user)
            ->from('/credits')
            ->post(route('credits.purchases.store'), $fields)
            ->assertRedirect('/credits')
            ->assertSessionHasErrors([$field => $message]);

        $this->assertSame(0, DB::table('credit_ledger_entries')->count());
    }

    /**
     * AC 16's header example: $0.0063 becomes $1.0063.
     */
    public function test_a_purchase_tops_up_the_shared_balance(): void
    {
        $user = User::factory()->withCredits(6_312_500)->create();

        $this->purchase($user, 1_000_000_000, (string) Str::uuid());

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('credits.available_nanodollars', 1_006_312_500));
    }

    private function purchase(User $user, int $pack, string $token): TestResponse
    {
        return $this->actingAs($user)
            ->from('/credits')
            ->post(route('credits.purchases.store'), ['pack_nanodollars' => $pack, 'purchase_token' => $token]);
    }
}
