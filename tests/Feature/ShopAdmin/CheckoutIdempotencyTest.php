<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Checkout idempotency at the createOrder seam (C1).
 *
 * A checkout session stamps one checkout_token; the unique index on
 * shop_order.checkout_token makes a second insert with the same token impossible, and
 * createOrder catches that and replays the winning order instead of erroring. This is
 * what stops a Back / refresh / double-click — especially after a gateway redirect left
 * the session intact — from creating a second order (RISK-BIZ-checkout-double-order).
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-checkout-idempotency
 * @aidlc-adr storefront_checkout-idempotency
 */
class CheckoutIdempotencyTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Build the minimal createOrder inputs for a single untaxed line.
     *
     * @param string $token checkout_token to stamp on the order.
     * @param string $email Unique order email.
     * @return array{0: array, 1: array, 2: array}
     */
    private function orderInputs(string $token, string $email): array
    {
        $product = $this->makeProduct(['price' => 50, 'stock' => 100, 'tax_id' => 0]);
        $dataOrder = [
            'customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1,
            'email' => $email, 'first_name' => 'ZZ Idem',
            'status' => 1, 'payment_status' => 0, 'shipping_status' => 1,
            'subtotal' => 100, 'tax' => 0, 'shipping' => 0, 'discount' => 0, 'other_fee' => 0,
            'total' => 100, 'received' => 0, 'balance' => 100,
            'store_id' => $this->storeId(), 'checkout_token' => $token,
        ];
        $dataTotal = [
            ['code' => 'subtotal', 'value' => 100, 'title' => 'Sub', 'sort' => 1],
            ['code' => 'tax', 'value' => 0, 'title' => 'Tax', 'sort' => 2],
            ['code' => 'total', 'value' => 100, 'title' => 'Total', 'sort' => 100],
        ];
        $arrCartDetail = [
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 50, 'qty' => 2, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
        ];

        return [$dataOrder, $dataTotal, $arrCartDetail];
    }

    /**
     * The token is persisted on the order it creates.
     */
    public function test_create_order_stores_the_checkout_token(): void
    {
        $token = 'zz-token-' . uniqid();
        [$o, $t, $d] = $this->orderInputs($token, 'zz-idem-' . uniqid() . '@example.com');

        $result = (new ShopOrder)->createOrder($o, $t, $d);

        $this->assertSame(0, $result['error'], $result['msg'] ?? '');
        $this->assertSame($token, ShopOrder::find($result['orderID'])->checkout_token);
    }

    /**
     * A second createOrder with the SAME token does not duplicate: the unique index
     * rejects the insert, createOrder replays the first order, and only one order exists.
     */
    public function test_same_token_replays_instead_of_duplicating(): void
    {
        $token = 'zz-token-' . uniqid();

        [$o1, $t1, $d1] = $this->orderInputs($token, 'zz-idem-a-' . uniqid() . '@example.com');
        $first = (new ShopOrder)->createOrder($o1, $t1, $d1);
        $this->assertSame(0, $first['error'], $first['msg'] ?? '');

        [$o2, $t2, $d2] = $this->orderInputs($token, 'zz-idem-b-' . uniqid() . '@example.com');
        $second = (new ShopOrder)->createOrder($o2, $t2, $d2);

        $this->assertSame(0, $second['error'], 'a replay is a success, not an error');
        $this->assertTrue(!empty($second['replayed']), 'second call is flagged as replayed');
        $this->assertSame($first['orderID'], $second['orderID'], 'replay returns the first order');
        $this->assertSame(1, ShopOrder::where('checkout_token', $token)->count(), 'exactly one order for the token');
    }

    /**
     * Different tokens create different orders (the guard is per-token, not global).
     */
    public function test_different_tokens_create_different_orders(): void
    {
        [$o1, $t1, $d1] = $this->orderInputs('zz-token-a-' . uniqid(), 'zz-idem-c-' . uniqid() . '@example.com');
        [$o2, $t2, $d2] = $this->orderInputs('zz-token-b-' . uniqid(), 'zz-idem-d-' . uniqid() . '@example.com');

        $first = (new ShopOrder)->createOrder($o1, $t1, $d1);
        $second = (new ShopOrder)->createOrder($o2, $t2, $d2);

        $this->assertNotSame($first['orderID'], $second['orderID']);
        $this->assertTrue(empty($second['replayed']));
    }
}
