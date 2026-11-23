<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Livewire\TaxManager;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Tax standardization / hardening (ADR shop-admin_tax-standardization). Covers:
 *  - gp247_line_tax(): the single line-level rounding rule (D2/D4).
 *  - shop_tax.value stores fractional percents now that it is decimal(8,4) (D1).
 *  - TaxManager persists a fractional rate end-to-end.
 *  - ShopOrder::createOrder() writes per-line tax with the line-level formula so
 *    shop_order.tax == Σ shop_order_detail.tax (storefront path).
 *  - AdminOrder::updateSubTotal() keeps the same invariant (admin path).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003, US-SADM-005
 * @aidlc-adr shop-admin_tax-standardization
 */
class TaxStandardizationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * Point the global default tax at a rate and clear the ShopTax + AdminConfig
     * per-request static caches so getTaxValue()/checkStatus() re-read immediately.
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

        // WHY: getAllGlobal reloads when === null; getAllConfigOfStore keys by store
        // and calls array_key_exists, so it must stay an array (reset to []), not null.
        $globalProp = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $globalProp->setAccessible(true);
        $globalProp->setValue(null, null);

        $storeProp = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $storeProp->setAccessible(true);
        $storeProp->setValue(null, []);
        foreach (['getList', 'status', 'arrayId', 'arrayValue'] as $propName) {
            $prop = new \ReflectionProperty(ShopTax::class, $propName);
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    // -------------------------------------------------------------------------
    // gp247_line_tax() — the D2 formula
    // -------------------------------------------------------------------------

    #[DataProvider('lineTaxCases')]
    public function test_line_tax_rounds_at_line_level(float $price, float $qty, float $rate, float $expected): void
    {
        $this->assertSame($expected, gp247_line_tax($price, $qty, $rate));
    }

    /**
     * @return array<string, array{0:float,1:float,2:float,3:float}>
     */
    public static function lineTaxCases(): array
    {
        return [
            'whole percent'        => [100.0, 1.0, 10.0, 10.0],
            'fractional 8.5%'      => [100.0, 3.0, 8.5, 25.5],
            'US 8.375% rounds'     => [33.33, 3.0, 8.375, 8.37],   // 33.33*3*8.375/100 = 8.37416 -> 8.37
            'zero price'           => [0.0, 5.0, 10.0, 0.0],
            'zero qty'             => [100.0, 0.0, 10.0, 0.0],
            'zero rate (tax off)'  => [100.0, 4.0, 0.0, 0.0],
        ];
    }

    // -------------------------------------------------------------------------
    // Schema/model — decimal(8,4) (D1)
    // -------------------------------------------------------------------------

    public function test_shop_tax_stores_fractional_percent(): void
    {
        $tax = ShopTax::create(['name' => 'ZZ VAT 8.375', 'value' => 8.375]);

        $fresh = ShopTax::find($tax->id);

        $this->assertSame(8.375, (float) $fresh->value, 'shop_tax.value must keep fractional percents (decimal 8,4)');
    }

    public function test_tax_manager_persists_fractional_value(): void
    {
        $this->asUser(slugs: ['admin_tax']);

        Livewire::test(TaxManager::class)
            ->set('form.name', 'ZZ Fractional Tax')
            ->set('form.value', 8.5)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame(8.5, (float) ShopTax::where('name', 'ZZ Fractional Tax')->value('value'));
    }

    // -------------------------------------------------------------------------
    // Storefront path — createOrder() per-line tax + invariant
    // -------------------------------------------------------------------------

    public function test_create_order_persists_line_level_per_line_tax(): void
    {
        $tax = ShopTax::create(['name' => 'ZZ Order Tax', 'value' => 8.5]);
        $this->setDefaultTax($tax->id);

        // tax_id='auto' is the storefront default path: getTaxId() returns the global
        // default rate via ShopTax::checkStatus(). (The specific-tax_id path — once a
        // fatal via getListAll()->has() — was fixed and is
        // covered by ProductTaxResolutionTest.)
        $product = $this->makeProduct(['price' => 100, 'stock' => 100, 'tax_id' => 'auto']);

        $dataOrder = [
            'customer_id' => 0,
            'currency' => 'USD',
            'exchange_rate' => 1,
            'email' => 'zz-tax-' . uniqid() . '@example.com',
            'first_name' => 'ZZ Tax',
            'status' => 1,
            'payment_status' => 0,
            'shipping_status' => 1,
            'subtotal' => 200,
            'tax' => 17.0,      // Σ line tax expected below (100*1*8.5% + 50*2*8.5%)
            'shipping' => 0,
            'discount' => 0,
            'other_fee' => 0,
            'total' => 217.0,
            'received' => 0,
            'balance' => 217.0,
            'store_id' => $this->storeId(),
        ];

        // Two lines on the same product: 1 x 100 and 2 x 50 -> subtotal 200.
        $arrCartDetail = [
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 100, 'qty' => 1, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 50,  'qty' => 2, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
        ];

        $result = (new ShopOrder)->createOrder($dataOrder, [], $arrCartDetail);

        $this->assertSame(0, $result['error'], 'createOrder failed: ' . ($result['msg'] ?? ''));

        $details = ShopOrderDetail::where('order_id', $result['orderID'])->orderBy('qty')->get();
        $this->assertCount(2, $details);

        // Line-level: round(100*1*8.5/100)=8.5 ; round(50*2*8.5/100)=8.5
        $this->assertSame(8.5, (float) $details[0]->tax);
        $this->assertSame(8.5, (float) $details[1]->tax);

        $sumDetailTax = (float) $details->sum('tax');
        $this->assertSame(17.0, $sumDetailTax, 'shop_order.tax must equal Σ shop_order_detail.tax');
        $this->assertSame((float) ShopOrder::find($result['orderID'])->tax, $sumDetailTax);
    }

    // -------------------------------------------------------------------------
    // Admin path — updateSubTotal() invariant
    // -------------------------------------------------------------------------

    /**
     * tax_rate frozen on the line keeps 4-decimal precision (US-CMP-tax-rate-precision):
     * an 8.375% rate is stored as 8.375, not truncated to 8.38 (the old decimal(5,2)).
     */
    public function test_line_tax_rate_keeps_fractional_precision(): void
    {
        $tax = ShopTax::create(['name' => 'ZZ US 8.375', 'value' => 8.375]);
        $this->setDefaultTax($tax->id);
        $product = $this->makeProduct(['price' => 100, 'stock' => 100, 'tax_id' => 'auto']);

        $dataOrder = [
            'customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1,
            'email' => 'zz-rate-' . uniqid() . '@example.com', 'first_name' => 'ZZ Rate',
            'status' => 1, 'payment_status' => 0, 'shipping_status' => 1,
            'subtotal' => 100, 'tax' => 8.38, 'shipping' => 0, 'discount' => 0, 'other_fee' => 0,
            'total' => 108.38, 'received' => 0, 'balance' => 108.38, 'store_id' => $this->storeId(),
        ];
        $arrCartDetail = [
            ['product_id' => $product->id, 'name' => $product->sku, 'price' => 100, 'qty' => 1, 'store_id' => $this->storeId(), 'attribute' => null, 'total_price' => 100],
        ];

        $result = (new ShopOrder)->createOrder($dataOrder, [], $arrCartDetail);
        $this->assertSame(0, $result['error'], $result['msg'] ?? '');

        $detail = ShopOrderDetail::where('order_id', $result['orderID'])->first();
        $this->assertSame(8.375, (float) $detail->tax_rate, 'tax_rate must keep 4 decimals, not truncate to 8.38');
    }

    public function test_admin_update_subtotal_keeps_tax_invariant(): void
    {
        $order = $this->makeOrder();
        // 2 lines, per-line tax at 8.5% of line subtotal (line-level).
        $this->makeOrderItem($order, ['price' => 100, 'qty' => 1, 'total_price' => 100, 'tax' => gp247_line_tax(100, 1, 8.5)]);
        $this->makeOrderItem($order, ['price' => 50, 'qty' => 2, 'total_price' => 100, 'tax' => gp247_line_tax(50, 2, 8.5)]);

        AdminOrder::updateSubTotal($order->id);

        $fresh = ShopOrder::find($order->id);
        $sumDetailTax = (float) ShopOrderDetail::where('order_id', $order->id)->sum('tax');

        $this->assertSame(17.0, $sumDetailTax);
        $this->assertSame($sumDetailTax, (float) $fresh->tax, 'order.tax must equal Σ detail.tax after recalc');
    }
}
