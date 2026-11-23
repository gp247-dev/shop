<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminStore;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Finding a product on the admin product LIST: the search box also matches the
 * product name in the admin's current language, and the category filter is a
 * searchable select.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-list-find
 */
class ProductListFindTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
    }

    public function test_the_keyword_finds_a_product_by_its_name_in_the_admin_language(): void
    {
        $code = strtoupper(substr(uniqid(), -6));
        $this->makeProduct('ZZ Blue Kettle ' . $code, ['sku' => 'ZZ-KETTLE-' . $code]);
        $this->makeProduct('ZZ Red Teapot ' . $code, ['sku' => 'ZZ-TEAPOT-' . $code]);
        app()->setLocale('en');

        Livewire::test(ProductManager::class)
            ->set('keyword', '  blue kettle ' . $code . ' ')
            ->assertSee('ZZ-KETTLE-' . $code)
            ->assertDontSee('ZZ-TEAPOT-' . $code);
    }

    public function test_a_name_only_written_in_another_language_does_not_match(): void
    {
        $code = strtoupper(substr(uniqid(), -6));
        $product = $this->makeProduct('ZZ Common Name ' . $code, ['sku' => 'ZZ-LANG-' . $code]);
        ShopProductDescription::where('product_id', $product->id)->where('lang', 'vi')
            ->update(['name' => 'ZZ Am xanh ' . $code]);

        app()->setLocale('en');
        Livewire::test(ProductManager::class)
            ->set('keyword', 'Am xanh ' . $code)
            ->assertDontSee('ZZ-LANG-' . $code);

        app()->setLocale('vi');
        Livewire::test(ProductManager::class)
            ->set('keyword', 'Am xanh ' . $code)
            ->assertSee('ZZ-LANG-' . $code);
    }

    public function test_sku_and_slug_are_still_searched(): void
    {
        $code = strtoupper(substr(uniqid(), -6));
        $this->makeProduct('ZZ Plain ' . $code, ['sku' => 'ZZ-BYSKU-' . $code]);
        $this->makeProduct('ZZ Plain ' . $code, ['sku' => 'ZZ-BYSLUG-' . $code, 'alias' => 'zz-find-slug-' . strtolower($code)]);

        Livewire::test(ProductManager::class)
            ->set('keyword', 'ZZ-BYSKU-' . $code)
            ->assertSee('ZZ-BYSKU-' . $code)
            ->assertDontSee('ZZ-BYSLUG-' . $code)
            ->set('keyword', 'zz-find-slug-' . strtolower($code))
            ->assertSee('ZZ-BYSLUG-' . $code)
            ->assertDontSee('ZZ-BYSKU-' . $code);
    }

    public function test_the_search_hint_names_sku_slug_and_name(): void
    {
        $component = Livewire::test(ProductManager::class);

        $labels = $component->instance()->searchColumnLabels();
        $this->assertSame([
            (string) gp247_language_render('product.sku'),
            (string) gp247_language_render('admin.search.col_alias'),
            (string) gp247_language_render('product.name'),
        ], $labels);
        $component->assertSee($component->instance()->searchHint());
    }

    public function test_a_name_search_never_shows_another_stores_product_to_a_store_admin(): void
    {
        $code = strtoupper(substr(uniqid(), -6));
        $this->makeProduct('ZZ Shared Name ' . $code, ['sku' => 'ZZ-ROOTSTORE-' . $code]);
        $suffix = uniqid();
        $store = AdminStore::create([
            'language' => 'en', 'currency' => 'USD', 'template' => 'GP247Front',
            'code' => 'lf' . substr($suffix, -10), 'domain' => 'lf-' . $suffix . '.local', 'status' => 1,
        ]);
        $this->makeProduct('ZZ Shared Name ' . $code, ['sku' => 'ZZ-OWNSTORE-' . $code, 'store_id' => $store->id]);
        session(['adminStoreId' => $store->id]);
        app()->setLocale('en');

        Livewire::test(ProductManager::class)
            ->set('keyword', 'Shared Name ' . $code)
            ->assertSee('ZZ-OWNSTORE-' . $code)
            ->assertDontSee('ZZ-ROOTSTORE-' . $code);
    }

    public function test_the_category_filter_is_a_searchable_select_that_filters_and_clears(): void
    {
        $code = strtoupper(substr(uniqid(), -6));
        $category = ShopCategory::create(['alias' => 'zz-lf-cat-' . strtolower($code), 'status' => 1, 'sort' => 0, 'store_id' => $this->storeId()]);
        $inCat = $this->makeProduct('ZZ In ' . $code, ['sku' => 'ZZ-IN-' . $code]);
        $inCat->categories()->attach($category->id);
        $this->makeProduct('ZZ Out ' . $code, ['sku' => 'ZZ-OUT-' . $code]);

        $component = Livewire::test(ProductManager::class);
        $component->assertSeeHtml('data-testid="admin-product-list-filter-category"')
            ->assertDontSeeHtml('wire:model.live="filterCategory"');

        $component->set('filterCategory', (string) $category->id)
            ->assertSee('ZZ-IN-' . $code)
            ->assertDontSee('ZZ-OUT-' . $code)
            // The searchable select clears to null ("all categories").
            ->set('filterCategory', null)
            ->assertSee('ZZ-IN-' . $code)
            ->assertSee('ZZ-OUT-' . $code);
    }
}
