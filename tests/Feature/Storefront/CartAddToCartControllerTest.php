<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * `ShopCartController::addToCart()` (route `cart.add`) is the plain POST form
 * behind the storefront product-detail "Add to cart" button — a legacy,
 * pre-Livewire path distinct from `ProductCard`/`CartManager` (US-LW-004).
 * Previously had zero qty validation; now enforces `product_qty_decimal`
 * (ADR-016) with a friendly redirect instead of
 * an uncaught `CartItem::setQuantity()` exception.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 * @aidlc-adr ADR-016
 */
class CartAddToCartControllerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        // WHY: this class overrides setUp() directly, so ConfiguresQtyDecimal's
        // own setUp() (cache reset) is shadowed and must be called explicitly.
        $this->resetQtyDecimalCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_decimal_qty_rejected_when_qty_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Cart Add Product');

        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => 1.5,
        ]);

        $response->assertSessionHasErrors(['qty']);
        $cart = (new CartService())->instance('default');
        $this->assertSame(0, $cart->count());
    }

    public function test_integer_qty_accepted_when_qty_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Cart Add Product Int');

        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => 2,
        ]);

        $response->assertSessionHasNoErrors();
        $cart = (new CartService())->instance('default');
        $this->assertSame(2, $cart->count());
    }

    public function test_decimal_qty_accepted_when_qty_decimal_enabled(): void
    {
        $this->setQtyDecimalEnabled(true);
        $product = $this->makeProduct('ZZ Cart Add Product Decimal');

        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => 1.5,
        ]);

        $response->assertSessionHasNoErrors();
        $cart = (new CartService())->instance('default');
        $this->assertEqualsWithDelta(1.5, $cart->count(), 0.01);
    }
}
