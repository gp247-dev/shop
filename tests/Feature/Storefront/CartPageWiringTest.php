<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * HTTP-level regression guard for wiring CartManager (Livewire) into the real
 * cart page (US-LW-004, ADR-015 Amend #1).
 *
 * The critical risk this guards against: ShopCartController::prepareCheckout()
 * reads qty-{rowId} from the checkout form submission to finalise quantities
 * before creating an order — a field missing from that form silently zeroes
 * out (and removes) every cart line on checkout. Livewire::test() cannot
 * catch this because it never submits a real plain-HTML form; only an actual
 * HTTP round-trip through the rendered page does.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 * @aidlc-adr ADR-015
 */
class CartPageWiringTest extends TestCase
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

    public function test_cart_page_renders_via_livewire_cart_manager(): void
    {
        $product = $this->makeProduct('ZZ Cart Page Product');
        $this->addToDefaultCart($product->id, 3);

        $response = $this->get(route('cart'));

        $response->assertOk();
        $response->assertSee('ZZ Cart Page Product');
        $response->assertSee('wire:click="updateQty', escape: false);
    }

    public function test_cart_page_includes_hidden_qty_input_matching_cart_state(): void
    {
        $product = $this->makeProduct('ZZ Cart Page Qty Input');
        $rowId = $this->addToDefaultCart($product->id, 4);

        $response = $this->get(route('cart'));

        $response->assertSee("name=\"qty-{$rowId}\" value=\"4\"", escape: false);
    }

    /**
     * The critical guard: submitting the checkout form exactly as the
     * rendered page produces it (store_id + hidden qty-{rowId} fields) must
     * NOT zero out the cart quantity. Before ADR-015 Amend #1's hidden input,
     * this would have silently dropped every line to qty 0.
     */
    public function test_submitting_checkout_form_preserves_cart_quantity(): void
    {
        $product = $this->makeProduct('ZZ Cart Page Checkout Preserve');
        $rowId = $this->addToDefaultCart($product->id, 5);
        $storeId = (string) $this->storeId();

        $response = $this->post(route('checkout.prepare'), [
            'store_id' => $storeId,
            "qty-{$rowId}" => 5,
        ]);

        $response->assertRedirect(route('checkout'));

        $item = (new CartService())->instance('default')->get($rowId);
        $this->assertNotNull($item, 'Cart item must survive checkout.prepare submission');
        $this->assertEquals(5, $item->qty);
    }

    /**
     * Add a product to the default cart instance and return the resulting rowId.
     *
     * @param string $productId
     * @param int $qty
     * @return string
     */
    private function addToDefaultCart(string $productId, int $qty): string
    {
        $cart = (new CartService())->instance('default');
        $item = $cart->add([
            'id' => $productId,
            'name' => 'ZZ Cart Page Product',
            'qty' => $qty,
            'storeId' => $this->storeId(),
        ]);

        return $item->rowId;
    }
}
