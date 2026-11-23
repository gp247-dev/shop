<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Per-product tax class resolution (P0-1).
 *
 * ShopProduct::getTaxId() used to call ->has() on ShopTax::getListAll(), which
 * returns a plain ARRAY padded with the 'none'/'auto' pseudo keys for admin
 * dropdowns — a fatal the moment tax was enabled AND a product carried a
 * specific tax_id. That is exactly the "per-product tax class" scenario, so the
 * feature was dead on arrival (RISK-TECH-tax-list-type-mismatch). Existence is
 * now checked against getArrayId(), the real ids.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-tax-resolution
 */
class ProductTaxResolutionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * Point the global default tax at a rate and clear the ShopTax + AdminConfig
     * per-request static caches so getTaxValue()/checkStatus() re-read immediately
     * (same reset dance as TaxStandardizationTest::setDefaultTax).
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

    /**
     * AC1 — the case that used to be a fatal: a product pinned to a specific tax
     * class resolves that class's rate.
     */
    public function test_a_specific_tax_class_resolves_its_own_rate(): void
    {
        $defaultTax = ShopTax::create(['name' => 'ZZ Default 10', 'value' => 10.0]);
        $specificTax = ShopTax::create(['name' => 'ZZ Specific 8.5', 'value' => 8.5]);
        $this->setDefaultTax($defaultTax->id);

        $product = $this->makeProduct(['tax_id' => (string) $specificTax->id]);

        $this->assertEquals($specificTax->id, ShopProduct::find($product->id)->getTaxId());
        $this->assertSame(8.5, (float) ShopProduct::find($product->id)->getTaxValue());
    }

    /**
     * AC2 — a tax_id pointing at a deleted/unknown class degrades to tax free, it
     * does not crash and it does not silently fall back to the default rate.
     */
    public function test_a_missing_tax_class_degrades_to_tax_free(): void
    {
        $defaultTax = ShopTax::create(['name' => 'ZZ Default 10b', 'value' => 10.0]);
        $this->setDefaultTax($defaultTax->id);

        $product = $this->makeProduct(['tax_id' => '999999']);

        $this->assertSame(0, ShopProduct::find($product->id)->getTaxId());
        $this->assertSame(0, (int) ShopProduct::find($product->id)->getTaxValue());
    }

    /**
     * AC4 — the two special values keep their meaning: 0 stays tax free, 'auto'
     * resolves the shop-wide default class.
     */
    public function test_zero_and_auto_semantics_are_unchanged(): void
    {
        $defaultTax = ShopTax::create(['name' => 'ZZ Default 10c', 'value' => 10.0]);
        $this->setDefaultTax($defaultTax->id);

        $taxFree = $this->makeProduct(['tax_id' => '0']);
        $auto = $this->makeProduct(['tax_id' => 'auto']);

        $this->assertSame(0, ShopProduct::find($taxFree->id)->getTaxId());
        $this->assertSame(0, (int) ShopProduct::find($taxFree->id)->getTaxValue());
        $this->assertEquals($defaultTax->id, ShopProduct::find($auto->id)->getTaxId());
        $this->assertSame(10.0, (float) ShopProduct::find($auto->id)->getTaxValue());
    }

    /**
     * Tax disabled globally wins over everything — a specific class on the
     * product changes nothing.
     */
    public function test_tax_disabled_globally_returns_zero_for_specific_class(): void
    {
        $specificTax = ShopTax::create(['name' => 'ZZ Specific off', 'value' => 8.5]);
        $this->setDefaultTax('none');

        $product = $this->makeProduct(['tax_id' => (string) $specificTax->id]);

        $this->assertSame(0, ShopProduct::find($product->id)->getTaxId());
        $this->assertSame(0, (int) ShopProduct::find($product->id)->getTaxValue());
    }
}
