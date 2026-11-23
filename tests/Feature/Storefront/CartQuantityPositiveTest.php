<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\CartManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartItem;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * A storefront cart line and the order built from it only ever carry a positive
 * quantity (security review 2026-10-28, CRITICAL — RISK-SEC-cart-negative-quantity).
 *
 * Before the fix the public Livewire action CartManager::addToCart() accepted a
 * negative qty: CartItem::setQuantity() only refused 0 and fractions, the line was
 * merged into the cart, the checkout totals dropped to zero or below, and
 * ShopProduct::updateStock() ADDED stock back when the order was placed.
 *
 * "Poisoned" fixtures below write a negative line straight into the session/DB
 * cart, the way a cart created before the fix would look.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-cart-qty-positive
 * @aidlc-adr storefront_cart-quantity-positive-invariant
 */
class CartQuantityPositiveTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        // WHY: this class overrides setUp(), shadowing ConfiguresQtyDecimal::setUp().
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Build a cart line with an arbitrary quantity, bypassing setQuantity() — the
     * shape a line written before the fix can still have in a session or DB cart.
     *
     * @param ShopProduct $product
     * @param int|float $qty
     * @return CartItem
     */
    private function rawLine(ShopProduct $product, $qty): CartItem
    {
        $item = CartItem::fromArray([
            'id' => (string) $product->id,
            'name' => 'ZZ Qty Line',
            'storeId' => $this->storeId(),
        ]);
        $item->qty = $qty;

        return $item;
    }

    /**
     * Replace the default session cart with the given lines.
     *
     * @param CartItem ...$lines
     * @return void
     */
    private function putSessionCart(CartItem ...$lines): void
    {
        $content = new Collection();
        foreach ($lines as $line) {
            $content->put($line->rowId, $line);
        }
        session(['cart.default' => $content]);
    }

    /**
     * Stage the confirm-step session the way the checkout flow leaves it, so that
     * POST order-add reaches the order-building code.
     *
     * @param array<int, CartItem> $lines
     * @param string $email
     * @return void
     */
    private function stageConfirmedCheckout(array $lines, string $email): void
    {
        session([
            'dataCheckout' => $lines,
            'storeCheckout' => $this->storeId(),
            'shippingAddress' => [
                'email' => $email,
                'first_name' => 'ZZ Qty',
                'phone' => '0123456789',
                'address1' => 'Addr 1',
                'country' => 'US',
            ],
            'paymentMethod' => '',
            'shippingMethod' => '',
            'address_process' => '',
            'checkoutToken' => gp247_token(32),
        ]);
        session(['dataTotal' => ShopOrderTotal::processDataTotal(ShopOrderTotal::getObjectOrderTotal())]);
    }

    // -------------------------------------------------------------------------
    // Adding to the cart
    // -------------------------------------------------------------------------

    public function test_the_livewire_add_to_cart_action_refuses_a_negative_quantity(): void
    {
        $product = $this->makeProduct('ZZ Qty Negative Add');

        Livewire::test(CartManager::class)
            ->call('addToCart', (string) $product->id, -5)
            ->assertDispatched('cart-error')
            ->assertSet('errorMessage', gp247_language_render('cart.have_error'));

        $this->assertSame(0, (new CartService())->instance('default')->content()->count());
    }

    public function test_a_negative_quantity_cannot_shrink_a_line_already_in_the_cart(): void
    {
        $product = $this->makeProduct('ZZ Qty Negative Merge');
        $cart = (new CartService())->instance('default');
        $line = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Qty Negative Merge', 'qty' => 3, 'storeId' => $this->storeId()]);

        Livewire::test(CartManager::class)
            ->call('addToCart', (string) $product->id, -1000)
            ->assertDispatched('cart-error');

        $this->assertEquals(3, $cart->get($line->rowId)->qty, 'the existing line must keep its quantity');
    }

    public function test_the_livewire_add_to_cart_action_refuses_a_zero_quantity_with_a_friendly_message(): void
    {
        $product = $this->makeProduct('ZZ Qty Zero Add');

        Livewire::test(CartManager::class)
            ->call('addToCart', (string) $product->id, 0)
            ->assertDispatched('cart-error')
            ->assertSet('errorMessage', gp247_language_render('cart.have_error'));
    }

    public function test_the_cart_service_refuses_to_add_a_negative_quantity(): void
    {
        $product = $this->makeProduct('ZZ Qty Service Add');

        $this->expectException(\InvalidArgumentException::class);
        (new CartService())->instance('default')->add([
            'id' => (string) $product->id, 'name' => 'ZZ Qty Service Add', 'qty' => -2, 'storeId' => $this->storeId(),
        ]);
    }

    public function test_updating_a_line_to_a_negative_quantity_still_removes_it(): void
    {
        $product = $this->makeProduct('ZZ Qty Update Remove');
        $cart = (new CartService())->instance('default');
        $line = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Qty Update Remove', 'qty' => 2, 'storeId' => $this->storeId()]);

        $cart->update($line->rowId, -4);

        $this->assertNull($cart->get($line->rowId));
    }

    // -------------------------------------------------------------------------
    // Checkout gate
    // -------------------------------------------------------------------------

    public function test_opening_checkout_with_a_poisoned_cart_line_returns_to_the_cart_without_staging_checkout_data(): void
    {
        $good = $this->makeProduct('ZZ Qty Checkout Good');
        $bad = $this->makeProduct('ZZ Qty Checkout Bad');
        $this->putSessionCart($this->rawLine($good, 1), $this->rawLine($bad, -5));
        session(['storeCheckout' => $this->storeId()]);

        $response = $this->get(route('checkout'));

        $response->assertRedirect(route('cart'));
        $response->assertSessionHas('error', gp247_language_render('cart.have_error'));
        $this->assertNull(session('dataCheckout'), 'checkout data must not be staged from a poisoned cart');
    }

    public function test_placing_an_order_from_poisoned_checkout_data_creates_no_order_and_leaves_stock_alone(): void
    {
        $good = $this->makeProduct('ZZ Qty Order Good', ['price' => 50, 'stock' => 100]);
        $bad = $this->makeProduct('ZZ Qty Order Bad', ['price' => 50, 'stock' => 100]);
        $email = 'zz-qty-' . uniqid() . '@example.com';
        $this->stageConfirmedCheckout([$this->rawLine($good, 1), $this->rawLine($bad, -5)], $email);

        $response = $this->post(route('order.add'), ['place_order' => '1']);

        $response->assertRedirect(route('cart'));
        $response->assertSessionHas('error', gp247_language_render('cart.have_error'));
        $this->assertFalse(ShopOrder::where('email', $email)->exists(), 'no order may be created from a non-positive line');
        $this->assertEquals(100, (float) ShopProduct::find($bad->id)->stock, 'stock must not move');
        $this->assertEquals(100, (float) ShopProduct::find($good->id)->stock, 'stock must not move');
    }

    // -------------------------------------------------------------------------
    // Cart restored at login
    // -------------------------------------------------------------------------

    public function test_logging_in_with_a_stored_cart_holding_an_invalid_line_keeps_the_valid_lines(): void
    {
        $good = $this->makeProduct('ZZ Qty Sync Good');
        $bad = $this->makeProduct('ZZ Qty Sync Bad');
        $identifier = 'zz-qty-sync-' . uniqid();
        $cart = (new CartService())->instance('default');
        // WHY: dropping a line is reported through gp247_report(), which mirrors to
        // Slack when a webhook is configured — a test must not post outside.
        config(['logging.channels.slack.url' => null]);

        // Store a cart that already holds a negative line, then start a fresh session.
        $goodLine = $this->rawLine($good, 2);
        $badLine = $this->rawLine($bad, -5);
        $this->putSessionCart($goodLine, $badLine);
        $cart->saveDatabase($identifier);
        session()->forget('cart.default');

        $cart->syncCartAfterLogin($identifier);

        $this->assertEquals(2, $cart->get($goodLine->rowId)->qty, 'valid stored line is restored');
        $this->assertNull($cart->get($badLine->rowId), 'invalid stored line is dropped');
    }
}
