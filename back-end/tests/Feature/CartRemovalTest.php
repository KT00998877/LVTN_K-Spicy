<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\User;
use App\Services\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CartRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_returns_the_updated_cart_without_a_second_read(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::firstOrFail();
        $menu = MenuItem::where('slug', 'coca-cola')->firstOrFail();
        $item = app(CartService::class)->addItem($user, [
            'menu_item_id' => $menu->id, 'quantity' => 1, 'spicy_level' => 0,
            'note' => '', 'topping_ids' => [],
        ]);
        $version = (int) DB::table('carts')->where('user_id', $user->id)->value('version');

        $this->actingAs($user)->deleteJson("/api/cart/items/{$item->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.version', $version + 1);
    }
}
