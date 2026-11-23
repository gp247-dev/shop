<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * `stock` (and, at rule level, `minimum`) follow the `product_qty_decimal`
 * Shop Config (ADR-016): integer-only by default,
 * numeric when the site enables decimal quantities. Domain/schema unchanged —
 * `shop_product.stock`/`minimum` stay `decimal(15,2)` either way (NFR-MAINT-008).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-016
 */
class ProductQuantityFormatTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    private function makeCategory(): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-qtycat-' . uniqid(), 'status' => 1, 'sort' => 0]);
    }

    public function test_decimal_stock_rejected_when_qty_decimal_disabled(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->setQtyDecimalEnabled(false);
        $category = $this->makeCategory();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-QTY-DEC-REJECT')
            ->set('form.alias', 'zz-qty-dec-reject')
            ->set('form.price', 25)
            ->set('form.stock', 10.5)
            ->set('form.category', [(string) $category->id]);
        $this->withNames($component, 'ZZ Qty Reject')
            ->call('save')
            ->assertHasErrors(['form.stock']);

        $this->assertNull(ShopProduct::where('sku', 'ZZ-QTY-DEC-REJECT')->first());
    }

    public function test_integer_stock_accepted_when_qty_decimal_disabled(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->setQtyDecimalEnabled(false);
        $category = $this->makeCategory();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-QTY-INT-OK')
            ->set('form.alias', 'zz-qty-int-ok')
            ->set('form.price', 25)
            ->set('form.stock', 10)
            ->set('form.category', [(string) $category->id]);
        $this->withNames($component, 'ZZ Qty Ok')
            ->call('save')
            ->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-QTY-INT-OK')->first();
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(10, (float) $product->stock, 0.01);
    }

    public function test_decimal_stock_accepted_when_qty_decimal_enabled(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->setQtyDecimalEnabled(true);
        $category = $this->makeCategory();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-QTY-DEC-OK')
            ->set('form.alias', 'zz-qty-dec-ok')
            ->set('form.price', 25)
            ->set('form.stock', 10.5)
            ->set('form.category', [(string) $category->id]);
        $this->withNames($component, 'ZZ Qty Decimal Ok')
            ->call('save')
            ->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-QTY-DEC-OK')->first();
        $this->assertNotNull($product);
        $this->assertEqualsWithDelta(10.5, (float) $product->stock, 0.01);
    }
}
