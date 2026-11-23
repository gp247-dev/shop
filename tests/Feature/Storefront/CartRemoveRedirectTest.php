<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Services\CartService;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Removing an item (or clearing) the wishlist / compare list from its page.
 *
 * The cart.remove / cart.clear links send the shopper back to the list's own
 * page. They used to redirect to gp247_route_front($instance), but the pages are
 * named cart.wishlist / cart.compare (only the cart page is named after its
 * instance), so the helper fell back to the current URL — the remove link itself
 * — and the browser looped until "too many redirects".
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 */
class CartRemoveRedirectTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Put a product into a cart instance and return its rowId.
     *
     * @param string $instance
     * @return string
     */
    private function rowIn(string $instance): string
    {
        $product = $this->makeProduct('ZZ Cart Remove '.$instance);
        $cart = (new CartService())->instance($instance);
        $cart->add(['id' => $product->id, 'name' => 'ZZ', 'qty' => 1, 'storeId' => GP247_STORE_ID_ROOT]);

        return (string) $cart->search(fn ($item) => (string) $item->id === (string) $product->id)->keys()->first();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function listPages(): array
    {
        return [
            'wishlist' => ['wishlist', 'cart.wishlist'],
            'compare' => ['compare', 'cart.compare'],
            'cart' => ['cart', 'cart'],
        ];
    }

    #[DataProvider('listPages')]
    public function test_removing_an_item_returns_to_the_list_page(string $instance, string $page): void
    {
        $rowId = $this->rowIn($instance === 'cart' ? 'default' : $instance);

        $this->get(gp247_route_front('cart.remove', ['instance' => $instance, 'id' => $rowId]))
            ->assertRedirect(gp247_route_front($page));

        $this->assertSame(0, (new CartService())->instance($instance === 'cart' ? 'default' : $instance)->count());
    }

    #[DataProvider('listPages')]
    public function test_clearing_a_list_returns_to_the_list_page(string $instance, string $page): void
    {
        $this->rowIn($instance === 'cart' ? 'default' : $instance);

        $this->get(gp247_route_front('cart.clear', ['instance' => $instance]))
            ->assertRedirect(gp247_route_front($page));
    }

    public function test_an_unknown_list_falls_back_to_the_cart_page(): void
    {
        $this->get(gp247_route_front('cart.remove', ['instance' => 'nope', 'id' => 'missing']))
            ->assertRedirect(gp247_route_front('cart'));
    }
}
