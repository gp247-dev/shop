<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Models\ShopCart;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartItem;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Hardening fixes for CartService/CartItem found in the 2026-07-07 technical
 * review of vendor/gp247/shop/src/Services:
 *
 *  1. CartService::update() bypassed CartItem::setQuantity(), letting
 *     ADR-016's whole-number qty rule be skipped via cart.update/checkout.prepare
 *     (RISK-TECH-016 materialized).
 *  2. CartService::associate() had no null-check for an unknown rowId.
 *  3. CartItem::$associatedModel was private, silently dropped by
 *     session.serialization = json (RISK-TECH-019).
 *  4. content()/getListCart() ran one product query per cart item (N+1,
 *     RISK-TECH-020).
 *  5. _updateDatabase() deleted then inserted non-atomically (RISK-TECH-021).
 *
 * Plus: checkout.prepare 500'd on a malformed
 * qty-* field and never summed the minimum across lines of one product
 * (RISK-TECH-checkout-prepare-raw-qty).
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 * @aidlc-adr ADR-016
 */
class CartServiceHardeningTest extends TestCase
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

    // -------------------------------------------------------------------------
    // 1. update() enforces setQuantity() (ADR-016) at both live call sites
    // -------------------------------------------------------------------------

    public function test_cart_service_update_throws_for_fractional_qty_when_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Update Throws');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Update Throws', 'qty' => 1, 'storeId' => $this->storeId()]);

        $this->expectException(\InvalidArgumentException::class);
        $cart->update($item->rowId, 1.5);
    }

    public function test_cart_service_update_still_removes_item_when_qty_is_zero(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Update Remove');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Update Remove', 'qty' => 1, 'storeId' => $this->storeId()]);

        $cart->update($item->rowId, 0);

        $this->assertNull($cart->get($item->rowId));
    }

    public function test_checkout_prepare_rejects_decimal_qty_when_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Checkout Prepare Reject');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Checkout Prepare Reject', 'qty' => 1, 'storeId' => $this->storeId()]);

        $response = $this->post(route('checkout.prepare'), [
            'store_id' => (string) $this->storeId(),
            "qty-{$item->rowId}" => 1.5,
        ]);

        $response->assertRedirect(route('cart'));
        $response->assertSessionHas('error', gp247_language_render('cart.qty_must_be_whole_number'));
        $this->assertEquals(1, $cart->get($item->rowId)->qty);
    }

    // -------------------------------------------------------------------------
    // 1b. checkout.prepare survives a malformed qty-* field
    // (RISK-TECH-checkout-prepare-raw-qty): production hit
    //     "Unsupported operand types: int + string" from tampered/bot requests.
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedQtyProvider(): array
    {
        return [
            'empty string' => [''],
            'non-numeric string' => ['abc'],
            'array' => [['1']],
        ];
    }

    #[DataProvider('malformedQtyProvider')]
    public function test_checkout_prepare_rejects_a_malformed_qty_and_leaves_the_cart_untouched(mixed $qty): void
    {
        $product = $this->makeProduct('ZZ Checkout Prepare Malformed');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Checkout Prepare Malformed', 'qty' => 2, 'storeId' => $this->storeId()]);

        $response = $this->post(route('checkout.prepare'), [
            'store_id' => (string) $this->storeId(),
            "qty-{$item->rowId}" => $qty,
        ]);

        $response->assertRedirect(route('cart'));
        $response->assertSessionHas('error', gp247_language_render('cart.have_error'));
        $this->assertNotNull($cart->get($item->rowId), 'A malformed qty must not be read as "remove this line"');
        $this->assertEquals(2, $cart->get($item->rowId)->qty);
        $this->assertNull(session('storeCheckout'));
    }

    public function test_checkout_prepare_sums_the_minimum_across_cart_lines_of_the_same_product(): void
    {
        $product = $this->makeProduct('ZZ Checkout Prepare Minimum Sum', ['minimum' => 3]);
        $cart = (new CartService())->instance('default');
        $base = ['id' => (string) $product->id, 'name' => 'ZZ Checkout Prepare Minimum Sum', 'qty' => 2, 'storeId' => $this->storeId()];
        $red = $cart->add($base + ['options' => ['1' => 'Red']]);
        $blue = $cart->add($base + ['options' => ['1' => 'Blue']]);
        $this->assertNotSame($red->rowId, $blue->rowId);

        $response = $this->post(route('checkout.prepare'), [
            'store_id' => (string) $this->storeId(),
            "qty-{$red->rowId}" => '2',
            "qty-{$blue->rowId}" => '2',
        ]);

        // 2 + 2 = 4 >= minimum 3: the customer must reach checkout.
        $response->assertRedirect(route('checkout'));
        $response->assertSessionMissing('arrErrorQty');
    }

    public function test_update_to_cart_rejects_decimal_qty_when_decimal_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Update To Cart Reject');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Update To Cart Reject', 'qty' => 1, 'storeId' => $this->storeId()]);

        $response = $this->post(route('cart.update'), [
            'id' => (string) $product->id,
            'rowId' => $item->rowId,
            'new_qty' => 1.5,
            'storeId' => $this->storeId(),
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();
        $response->assertJson([
            'error' => 1,
            'msg' => gp247_language_render('cart.qty_must_be_whole_number'),
        ]);
        $this->assertEquals(1, $cart->get($item->rowId)->qty);
    }

    // -------------------------------------------------------------------------
    // 2. associate() null-check
    // -------------------------------------------------------------------------

    public function test_associate_with_unknown_row_id_is_silent_noop(): void
    {
        $cart = (new CartService())->instance('default');

        $cart->associate('no-such-row-id', ShopProduct::class);

        $this->assertNull($cart->get('no-such-row-id'));
    }

    // -------------------------------------------------------------------------
    // 3. CartItem::$associatedModel survives session.serialization = json
    // -------------------------------------------------------------------------

    public function test_hydrate_cart_item_restores_associated_model_after_json_round_trip(): void
    {
        $product = $this->makeProduct('ZZ Associated Model');
        $item = new CartItem((string) $product->id, 'ZZ Associated Model', [], $this->storeId());
        $item->setQuantity(1);
        $item->associate(ShopProduct::class);

        // Simulate session.serialization = json round-trip: json_encode() only
        // exports public properties, json_decode(..., true) returns a plain array.
        $decoded = json_decode(json_encode($item), true);

        // The rehydration lives on CartItem, not CartService: getContent() maps every
        // session entry through CartItem::hydrate(). This asserted a
        // CartService::hydrateCartItem() that was never where the fix landed, so the
        // case errored instead of guarding the round-trip.
        $rehydrated = CartItem::hydrate($decoded);

        $this->assertSame(ShopProduct::class, $rehydrated->associatedModel);
        $this->assertInstanceOf(ShopProduct::class, $rehydrated->model);
    }

    // -------------------------------------------------------------------------
    // 4. N+1 query fixes
    // -------------------------------------------------------------------------

    public function test_content_batches_product_lookup_into_a_single_query(): void
    {
        $cart = (new CartService())->instance('default');
        for ($i = 0; $i < 3; $i++) {
            $product = $this->makeProduct('ZZ N+1 Content ' . $i);
            $cart->add(['id' => (string) $product->id, 'name' => $product->getName(), 'qty' => 1, 'storeId' => $this->storeId()]);
        }

        $needle = '`' . (new ShopProduct())->getTable() . '`';
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount, $needle) {
            if (str_contains($query->sql, $needle)) {
                $queryCount++;
            }
        });

        $cart->content();

        $this->assertSame(1, $queryCount, 'content() must batch the active/approved check into a single query regardless of cart size');
    }

    public function test_get_list_cart_batches_product_lookup_into_a_bounded_number_of_queries(): void
    {
        $cart = (new CartService())->instance('default');
        for ($i = 0; $i < 3; $i++) {
            $product = $this->makeProduct('ZZ N+1 List ' . $i);
            $cart->add(['id' => (string) $product->id, 'name' => $product->getName(), 'qty' => 1, 'storeId' => $this->storeId()]);
        }

        $needle = '`' . (new ShopProduct())->getTable() . '`';
        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount, $needle) {
            if (str_contains($query->sql, $needle)) {
                $queryCount++;
            }
        });

        $result = CartService::getListCart();

        // content()'s own batched check (1) + getListCart()'s batched fetch (1) —
        // constant regardless of cart size, not 1-per-item as before the fix.
        $this->assertLessThanOrEqual(2, $queryCount, 'getListCart() must not issue one product query per cart item (N+1)');
        $this->assertCount(3, $result['items']);
    }

    // -------------------------------------------------------------------------
    // 5. _updateDatabase() atomicity
    // -------------------------------------------------------------------------

    public function test_update_database_replaces_the_stored_cart_row_exactly_once(): void
    {
        $cart = (new CartService())->instance('default');
        $product = $this->makeProduct('ZZ Transactional Update');
        $cart->add(['id' => (string) $product->id, 'name' => $product->getName(), 'qty' => 1, 'storeId' => $this->storeId()]);

        $identifier = 999001;
        $updateDatabase = new ReflectionMethod(CartService::class, '_updateDatabase');
        $updateDatabase->setAccessible(true);

        $updateDatabase->invoke($cart, $identifier);
        $this->assertSame(1, ShopCart::where('identifier', $identifier)->count());

        // Re-run against the same identifier — the transaction must leave
        // exactly one row (delete-then-insert), never zero or two.
        $updateDatabase->invoke($cart, $identifier);
        $this->assertSame(1, ShopCart::where('identifier', $identifier)->count());
    }
}
