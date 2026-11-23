<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin product LIST screen (group F, US-SADM-001): store-scoped
 * listing on the core ResourcePanel base with keyword + category filters. Domain
 * unchanged. Gated by `admin_product`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class ProductListTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    public function test_lists_products_in_store(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ List Product', ['sku' => 'ZZ-LIST-FIND']);

        Livewire::test(ProductManager::class)->assertSee('ZZ-LIST-FIND');
    }

    public function test_keyword_filters_by_sku(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $keep = $this->makeProduct('Keep', ['sku' => 'ZZ-KEEP-SKU']);
        $drop = $this->makeProduct('Drop', ['sku' => 'ZZ-DROP-SKU']);

        Livewire::test(ProductManager::class)
            ->set('keyword', 'ZZ-KEEP-SKU')
            ->assertSee('ZZ-KEEP-SKU')
            ->assertDontSee('ZZ-DROP-SKU');
    }

    public function test_filters_by_category(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = ShopCategory::create(['alias' => 'zz-fcat-' . uniqid(), 'status' => 1, 'sort' => 0]);

        $inCat = $this->makeProduct('In cat', ['sku' => 'ZZ-INCAT']);
        $inCat->categories()->attach($category->id);
        $notInCat = $this->makeProduct('No cat', ['sku' => 'ZZ-NOCAT']);

        Livewire::test(ProductManager::class)
            ->set('filterCategory', (string) $category->id)
            ->assertSee('ZZ-INCAT')
            ->assertDontSee('ZZ-NOCAT');
    }

    public function test_list_denied_without_permission(): void
    {
        $this->asUser();

        $this->assertDenied(fn () => Livewire::test(ProductManager::class));
    }
}
