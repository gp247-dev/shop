<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductBuild;
use GP247\Shop\Models\ShopProductGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for BUILD (bundle) + GROUP product composition (group F, US-SADM-001):
 * components/members persisted into ShopProductBuild / ShopProductGroup, with the
 * required-component validation. Domain unchanged. Gated by `admin_product`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class ProductCompositionTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    private function makeCategory(): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-compcat-' . uniqid(), 'status' => 1, 'sort' => 0]);
    }

    /**
     * Enable "Use STRUCTURE TYPE" for the duration of this test so BUILD/GROUP
     * products can be created. DatabaseTransactions rolls the DB change back afterwards.
     * WHY: AdminConfig has a per-request static cache; we must clear it so gp247_config()
     * reflects the updated value immediately.
     */
    private function enableStructureType(): void
    {
        AdminConfig::where('key', 'product_kind')
            ->where('store_id', defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0)
            ->update(['value' => '1']);

        // Reset both static caches so gp247_config('product_kind') re-reads from DB.
        // product_kind is stored at GP247_STORE_ID_GLOBAL so it's served via getAllGlobal().
        // WHY: getAllConfigOfStore does array_key_exists() on its property, so it must
        // stay an array (null would TypeError at render); getAllGlobal checks `=== null`.
        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }

    public function test_creates_build_product_with_components(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->enableStructureType();
        $category = $this->makeCategory();
        $component = $this->makeProduct('Comp A', ['sku' => 'ZZ-COMP-A']);

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-BUILD-SKU')
            ->set('form.alias', 'zz-build-alias')
            ->set('form.kind', GP247_PRODUCT_BUILD)
            ->set('form.category', [(string) $category->id])
            ->set('buildItems', [['product_id' => (string) $component->id, 'quantity' => 2]]);
        $this->withNames($lw, 'Bundle Product')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-BUILD-SKU')->first();
        $this->assertSame(1, ShopProductBuild::where('build_id', $product->id)->count());
    }

    public function test_creates_group_product_with_members(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->enableStructureType();
        $category = $this->makeCategory();
        $member = $this->makeProduct('Comp B', ['sku' => 'ZZ-COMP-B']);

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-GROUP-SKU')
            ->set('form.alias', 'zz-group-alias')
            ->set('form.kind', GP247_PRODUCT_GROUP)
            ->set('form.category', [(string) $category->id])
            ->set('groupItems', [['product_id' => (string) $member->id]]);
        $this->withNames($lw, 'Group Product')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-GROUP-SKU')->first();
        $this->assertSame(1, ShopProductGroup::where('group_id', $product->id)->count());
    }

    /**
     * Per-component quantity format follows product_qty_decimal (ADR-016) — previously unvalidated at all.
     */
    public function test_build_component_rejects_decimal_quantity_when_qty_decimal_disabled(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->enableStructureType();
        $this->setQtyDecimalEnabled(false);
        $category = $this->makeCategory();
        $component = $this->makeProduct('Comp Qty', ['sku' => 'ZZ-COMP-QTY']);

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-BUILD-QTY-REJECT')
            ->set('form.alias', 'zz-build-qty-reject')
            ->set('form.kind', GP247_PRODUCT_BUILD)
            ->set('form.category', [(string) $category->id])
            ->set('buildItems', [['product_id' => (string) $component->id, 'quantity' => 2.5]]);
        $this->withNames($lw, 'Bundle Qty Reject')->call('save')->assertHasErrors(['buildItems.0.quantity']);
    }

    public function test_build_component_accepts_decimal_quantity_when_qty_decimal_enabled(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $this->enableStructureType();
        $this->setQtyDecimalEnabled(true);
        $category = $this->makeCategory();
        $component = $this->makeProduct('Comp Qty 2', ['sku' => 'ZZ-COMP-QTY-2']);

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-BUILD-QTY-OK')
            ->set('form.alias', 'zz-build-qty-ok')
            ->set('form.kind', GP247_PRODUCT_BUILD)
            ->set('form.category', [(string) $category->id])
            ->set('buildItems', [['product_id' => (string) $component->id, 'quantity' => 2.5]]);
        $this->withNames($lw, 'Bundle Qty Ok')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-BUILD-QTY-OK')->first();
        $this->assertEqualsWithDelta(2.5, (float) ShopProductBuild::where('build_id', $product->id)->value('quantity'), 0.01);
    }

    public function test_build_requires_components(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-BUILD-EMPTY')
            ->set('form.alias', 'zz-build-empty')
            ->set('form.kind', GP247_PRODUCT_BUILD)
            ->set('form.category', [(string) $category->id])
            ->set('buildItems', []);
        $this->withNames($lw, 'Empty Bundle')->call('save')->assertHasErrors(['buildItems']);
    }

    public function test_group_requires_members(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();

        $lw = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-GROUP-EMPTY')
            ->set('form.alias', 'zz-group-empty')
            ->set('form.kind', GP247_PRODUCT_GROUP)
            ->set('form.category', [(string) $category->id])
            ->set('groupItems', []);
        $this->withNames($lw, 'Empty Group')->call('save')->assertHasErrors(['groupItems']);
    }
}
