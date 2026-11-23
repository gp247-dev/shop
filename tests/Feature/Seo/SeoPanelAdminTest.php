<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminLanguage;
use GP247\Front\Admin\Livewire\PageManager;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Models\FrontPage;
use GP247\Front\Models\FrontPageDescription;
use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Admin\Livewire\CategoryManager;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The shared SEO panel on the product, category, brand and page edit screens saves
 * with the screen's own Save button (SEO phase 1).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-content-meta, US-SEO-content-index-control, US-SADM-001, US-SADM-002, US-FADM-002
 * @aidlc-adr ADR-seo-content-meta-table
 */
class SeoPanelAdminTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    public function test_the_product_screen_saves_and_reloads_the_seo_panel(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Panel Product');
        $category = ShopCategory::create(['alias' => 'zz-panel-pcat-' . uniqid(), 'status' => 1, 'sort' => 0, 'store_id' => $this->storeId()]);
        $code = $this->langCodes()[0];

        Livewire::test(ProductManager::class, ['id' => $product->id])
            ->set('form.category', [(string) $category->id])
            ->set("seo.desc.{$code}.title", 'ZZ Panel SEO title')
            ->set("seo.desc.{$code}.description", 'ZZ Panel SEO description')
            ->set('seo.noindex', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet("seo.desc.{$code}.title", 'ZZ Panel SEO title')
            ->assertSet('seo.noindex', true);

        $saved = SeoContentMeta::for('product', $product->id, $code);
        $this->assertSame('ZZ Panel SEO title', $saved['title']);
        $this->assertSame('ZZ Panel SEO description', $saved['description']);
        $this->assertTrue($saved['noindex']);
    }

    public function test_the_product_screen_rejects_a_canonical_that_is_not_a_web_address(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Panel Bad Canonical');
        $code = $this->langCodes()[0];

        Livewire::test(ProductManager::class, ['id' => $product->id])
            ->set("seo.desc.{$code}.canonical", 'javascript:alert(1)')
            ->call('save')
            ->assertHasErrors(["seo.desc.{$code}.canonical"]);

        $this->assertNull(SeoContentMeta::for('product', $product->id, $code));
    }

    public function test_the_product_screen_rejects_a_seo_title_that_is_too_long(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Panel Long Title');
        $code = $this->langCodes()[0];

        Livewire::test(ProductManager::class, ['id' => $product->id])
            ->set("seo.desc.{$code}.title", str_repeat('x', 256))
            ->call('save')
            ->assertHasErrors(["seo.desc.{$code}.title"]);
    }

    public function test_the_category_screen_saves_the_seo_panel(): void
    {
        $this->asUser(slugs: ['admin_category']);
        $this->useAdminStore();
        $category = ShopCategory::create(['alias' => 'zz-panel-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0, 'status' => 1, 'sort' => 0, 'store_id' => $this->storeId()]);
        foreach ($this->langCodes() as $c) {
            ShopCategoryDescription::insert(['category_id' => $category->id, 'lang' => $c, 'name' => 'ZZ Panel Cat', 'keyword' => '', 'description' => '']);
        }
        $code = $this->langCodes()[0];

        Livewire::test(CategoryManager::class, ['id' => $category->id])
            ->set("seo.desc.{$code}.description", 'ZZ Cat SEO description')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('ZZ Cat SEO description', SeoContentMeta::for('category', $category->id, $code)['description']);
    }

    public function test_the_brand_screen_saves_the_seo_panel(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->useAdminStore();
        $brand = ShopBrand::create(['name' => 'ZZ Panel Brand', 'alias' => 'zz-panel-brand-' . uniqid(), 'image' => 'path/seed.png', 'sort' => 0, 'status' => 1, 'store_id' => $this->storeId()]);
        $code = $this->langCodes()[0];

        Livewire::test(BrandManager::class, ['id' => $brand->id])
            ->set("seo.desc.{$code}.description", 'ZZ Brand SEO description')
            ->set('seo.og_image', 'path/zz-brand-share.png')
            ->call('save')
            ->assertHasNoErrors();

        $saved = SeoContentMeta::for('brand', $brand->id, $code);
        $this->assertSame('ZZ Brand SEO description', $saved['description']);
        $this->assertSame('path/zz-brand-share.png', $saved['og_image']);
    }

    public function test_the_page_screen_saves_the_seo_panel(): void
    {
        $this->asUser(slugs: ['admin_page']);
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-panel-page-' . uniqid(), 'status' => 1, 'store_id' => $this->storeId()]);
        foreach (AdminLanguage::getListActive() as $c => $language) {
            FrontPageDescription::create(['page_id' => $page->id, 'lang' => $c, 'name' => 'ZZ Panel Page', 'keyword' => '', 'description' => '', 'content' => '']);
        }
        $code = $this->langCodes()[0];

        Livewire::test(PageManager::class, ['id' => $page->id])
            ->set("seo.desc.{$code}.title", 'ZZ Page SEO title')
            ->set('seo.nofollow', true)
            ->call('save')
            ->assertHasNoErrors();

        $saved = SeoContentMeta::for('page', $page->id, $code);
        $this->assertSame('ZZ Page SEO title', $saved['title']);
        $this->assertTrue($saved['nofollow']);
    }

    public function test_clearing_every_seo_field_on_a_screen_removes_the_seo_data(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->useAdminStore();
        $brand = ShopBrand::create(['name' => 'ZZ Clear Brand', 'alias' => 'zz-clear-brand-' . uniqid(), 'image' => 'path/seed.png', 'sort' => 0, 'status' => 1, 'store_id' => $this->storeId()]);
        SeoContentMeta::save('brand', $brand->id, ['noindex' => true]);

        Livewire::test(BrandManager::class, ['id' => $brand->id])
            ->assertSet('seo.noindex', true)
            ->set('seo.noindex', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(SeoContentMeta::for('brand', $brand->id));
    }

    public function test_the_panel_is_shown_on_the_product_form(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();

        Livewire::test(ProductManager::class)
            ->assertSeeHtml('data-testid="product-form-tab-seo"')
            ->assertSeeHtml('data-testid="seo-panel"');
    }
}
