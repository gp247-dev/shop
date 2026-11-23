<?php

namespace GP247\Shop\Tests\Unit\Storefront;

use GP247\Shop\Services\Cart\CartStore;
use GP247\Shop\Services\Cart\SessionCartStore;
use GP247\Shop\Services\CartService;
use GP247\Shop\Tests\TestCase;

/**
 * CartService keeps the cart through a CartStore: the default store is the web session
 * under the same `cart.<instance>` keys as before, and another store (the API, later)
 * can be given without the session being touched.
 */
class CartStoreSeamTest extends TestCase
{
    public function test_by_default_the_cart_lives_in_the_session_under_the_same_keys(): void
    {
        $this->assertInstanceOf(SessionCartStore::class, app(CartStore::class));
        $cart = (new CartService())->instance('default');

        $item = $cart->add(['id' => 'ZZ-P1', 'name' => 'ZZ', 'qty' => 2, 'storeId' => '1']);

        $this->assertTrue(session()->has('cart.default'));
        $this->assertSame(2, (int) session('cart.default')->get($item->rowId)->qty);
        $cart->destroy();
        $this->assertFalse(session()->has('cart.default'));
    }

    public function test_a_given_store_keeps_the_cart_away_from_the_session(): void
    {
        $store = new ZzArrayCartStore();
        $cart = (new CartService($store))->instance('wishlist');

        $item = $cart->add(['id' => 'ZZ-P2', 'name' => 'ZZ', 'qty' => 1, 'storeId' => '1']);
        $cart->update($item->rowId, 3);

        $this->assertFalse(session()->has('cart.wishlist'));
        $this->assertSame(3, (int) $store->get('cart.wishlist')->get($item->rowId)->qty);
        $this->assertSame(3, (int) $cart->count(), 'count() is the total quantity, read from the given store');
        $cart->remove($item->rowId);
        $this->assertSame(0, $cart->count());
    }
}

/**
 * In-memory CartStore double.
 */
class ZzArrayCartStore implements CartStore
{
    private array $data = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function put(string $key, $content): void
    {
        $this->data[$key] = $content;
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }
}
