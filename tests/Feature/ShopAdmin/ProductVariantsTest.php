<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for product variants/attributes (group F, US-SADM-001): add/remove option
 * rows under an attribute group, persisted into ShopProductAttribute (SINGLE
 * only). Domain unchanged. Gated by `admin_product`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class ProductVariantsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    private function makeCategory(): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-varcat-' . uniqid(), 'status' => 1, 'sort' => 0]);
    }

    private function makeGroup(): ShopAttributeGroup
    {
        return ShopAttributeGroup::create(['name' => 'Color ' . uniqid(), 'type' => 'radio']);
    }

    public function test_saves_variants(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();
        $group = $this->makeGroup();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-VAR-SKU')
            ->set('form.alias', 'zz-var-alias')
            ->set('form.category', [(string) $category->id])
            ->set('variants', [
                ['attribute_group_id' => (string) $group->id, 'name' => 'Red', 'add_price' => 5, 'sort' => 0, 'status' => 1],
            ]);
        $this->withNames($component, 'Var Product')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-VAR-SKU')->first();
        $rows = ShopProductAttribute::where('product_id', $product->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('Red', $rows->first()->name);
        $this->assertEqualsWithDelta(5, (float) $rows->first()->add_price, 0.01);
    }

    public function test_edit_loads_variants(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $group = $this->makeGroup();
        $product = $this->makeProduct('Var Edit', ['sku' => 'ZZ-VAR-EDIT']);
        $product->attributes()->create([
            'attribute_group_id' => $group->id, 'name' => 'Blue', 'add_price' => 3, 'sort' => 0, 'status' => 1,
        ]);

        $component = Livewire::test(ProductManager::class, ['id' => (string) $product->id]);
        $this->assertCount(1, $component->get('variants'));
        $this->assertSame('Blue', $component->get('variants')[0]['name']);
    }

    public function test_remove_variant(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();

        $component = Livewire::test(ProductManager::class)
            ->set('variants', [
                ['attribute_group_id' => '1', 'name' => 'A', 'add_price' => 0, 'sort' => 0, 'status' => 1],
                ['attribute_group_id' => '1', 'name' => 'B', 'add_price' => 0, 'sort' => 0, 'status' => 1],
            ])
            ->call('removeVariant', 0);

        $this->assertCount(1, $component->get('variants'));
        $this->assertSame('B', $component->get('variants')[0]['name']);
    }
}
