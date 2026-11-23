<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Storefront createOrder writes the reallocated figures back onto the header
 * (T2). Before this, createOrder called
 * reallocateDiscountAndTax() and DISCARDED the result, so shop_order.tax kept the
 * pre-discount snapshot while the lines were re-taxed — the invariant
 * shop_order.tax = Σ detail.tax held only after the first admin edit
 * (RISK-BIZ-order-header-line-drift). It must hold the instant the order exists.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-checkout-total-freshness
 * @aidlc-adr storefront_checkout-idempotency
 */
class CheckoutTotalFreshnessTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Point the global default tax at a rate and clear the caches (as TaxStandardizationTest).
     *
     * @param int|string $taxId
     * @return void
     */
    private function setDefaultTax($taxId): void
    {
        $storeId = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;
        AdminConfig::updateOrCreate(
            ['key' => 'product_tax', 'store_id' => $storeId],
            ['group' => 'gp247_cart', 'code' => 'product_config_attribute', 'value' => (string) $taxId, 'sort' => 0, 'detail' => '']
        );
        $g = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $g->setAccessible(true);
        $g->setValue(null, null);
        $s = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $s->setAccessible(true);
        $s->setValue(null, []);
        foreach (['getList', 'status', 'arrayId', 'arrayValue'] as $p) {
            $rp = new \ReflectionProperty(ShopTax::class, $p);
            $rp->setAccessible(true);
            $rp->setValue(null, null);
        }
    }

    /**
     * The header agrees with the lines the moment a discounted, taxed order is created —
     * even when the incoming snapshot header carried the WRONG (pre-discount) tax.
     */
    public function test_header_matches_lines_right_after_create_with_discount(): void
    {
        $tax = ShopTax::create(['name' => 'ZZ Fresh 10', 'value' => 10.0]);
        $this->setDefaultTax($tax->id);
        $product = $this->makeProduct(['price' => 100, 'stock' => 100, 'tax_id' => 'auto']);

        // Two lines of 100 → subtotal 200; a 20 discount, split 10/10; tax on 90 each = 9+9 = 18.
        // The snapshot header below deliberately carries tax=20 (pre-discount) and a stale
        // total, to prove createOrder writes back the reallocated figures rather than trusting it.
        $dataOrder = [
            'customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1,
            'email' => 'zz-fresh-' . uniqid() . '@example.com', 'first_name' => 'ZZ Fresh',
            'status' => 1, 'payment_status' => 0, 'shipping_status' => 1,
            'subtotal' => 200, 'tax' => 20.0, 'shipping' => 0, 'discount' => 20, 'other_fee' => 0,
            'total' => 200.0, 'received' => 0, 'balance' => 200.0, 'store_id' => $this->storeId(),
        ];
        $dataTotal = [
            ['code' => 'subtotal', 'value' => 200, 'title' => 'Sub', 'sort' => 1],
            ['code' => 'tax', 'value' => 20.0, 'title' => 'Tax', 'sort' => 2],
            ['code' => 'discount', 'value' => 20, 'title' => 'Discount', 'sort' => 20],
            ['code' => 'total', 'value' => 200.0, 'title' => 'Total', 'sort' => 100],
        ];
        $arrCartDetail = [
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 100, 'qty' => 1, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 100, 'qty' => 1, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
        ];

        $result = (new ShopOrder)->createOrder($dataOrder, $dataTotal, $arrCartDetail);
        $this->assertSame(0, $result['error'], 'createOrder failed: ' . ($result['msg'] ?? ''));

        $order = ShopOrder::find($result['orderID']);
        $sumDetailTax = (float) ShopOrderDetail::where('order_id', $order->id)->sum('tax');
        $sumTotalPrice = (float) ShopOrderDetail::where('order_id', $order->id)->sum('total_price');

        // The invariants must hold immediately — not only after an admin edit.
        $this->assertEqualsWithDelta(18.0, $sumDetailTax, 0.01, 'Σ detail.tax after discount');
        $this->assertEqualsWithDelta($sumDetailTax, (float) $order->tax, 0.01, 'header tax written back to match lines');
        $this->assertEqualsWithDelta(200.0, $sumTotalPrice, 0.01);
        $this->assertEqualsWithDelta($sumTotalPrice, (float) $order->subtotal, 0.01, 'header subtotal = Σ total_price');

        // total row on the document agrees too: 200 + 18 − 20 = 198.
        $this->assertEqualsWithDelta(198.0, (float) $order->total, 0.01);
        $taxRow = ShopOrderTotal::where('order_id', $order->id)->where('code', 'tax')->value('value');
        $this->assertEqualsWithDelta(18.0, (float) $taxRow, 0.01, 'tax total row written back too');
    }
}
