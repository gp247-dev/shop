<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Models\FrontPage;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for the `seo.sitemap_include_products` / `seo.sitemap_include_categories`
 * toggles (US-SEO-004) and the
 * `seo.sitemap_exclude_aliases` wildcard exclusion list, both consumed by `SeoController::collectUrls()`. Default
 * (no config row) keeps pre-existing behaviour: everything included whenever
 * `gp247/shop` is installed and no exclusion pattern is set.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-004
 */
class SitemapToggleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        // WHY: see SeoSettingsTest::setUp() — AdminConfig's static per-store cache
        // outlives DB transaction rollback within a single PHPUnit process.
        self::clearAdminConfigCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        self::clearAdminConfigCache();
        parent::tearDown();
    }

    private function bustSitemapCache(): void
    {
        // Version bump invalidates root + all child-segment keys at once.
        \GP247\Front\Controllers\SeoController::bumpCacheVersion(config('app.storeId'));
    }

    private function makeProduct(): ShopProduct
    {
        $product = ShopProduct::create([
            'sku' => 'ZZ-SEO-SKU-' . uniqid(),
            'alias' => 'zz-seo-product-' . uniqid(),
            'price' => 10,
            'cost' => 5,
            'stock' => 100,
            'status' => 1,
            'approve' => 1,
            'kind' => defined('GP247_PRODUCT_SINGLE') ? GP247_PRODUCT_SINGLE : 0,
            'sort' => 0,
            // WHY: store ownership is a plain store_id column since the 1-1 normalization
            // (US-CMP-store-1to1-schema); the pivot relation/table no longer exists.
            'store_id' => config('app.storeId'),
        ]);

        return $product;
    }

    private function makeCategory(): ShopCategory
    {
        $category = ShopCategory::create([
            'alias' => 'zz-seo-category-' . uniqid(),
            'image' => '',
            'parent' => null,
            'top' => 0,
            'status' => 1,
            'sort' => 0,
            // WHY: store ownership is a plain store_id column since the 1-1 normalization
            // (US-CMP-store-1to1-schema); the pivot relation/table no longer exists.
            'store_id' => config('app.storeId'),
        ]);

        return $category;
    }

    public function test_sitemap_includes_product_by_default(): void
    {
        $product = $this->makeProduct();
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertSee($product->alias, escape: false);
    }

    public function test_sitemap_excludes_product_when_toggled_off(): void
    {
        $product = $this->makeProduct();
        AdminConfig::updateOrCreate(
            ['key' => 'seo.sitemap_include_products', 'store_id' => config('app.storeId')],
            ['value' => '0', 'group' => 'seo', 'code' => 'seo_settings', 'sort' => 0],
        );
        self::clearAdminConfigCache();
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee($product->alias, escape: false);
    }

    public function test_sitemap_excludes_category_when_toggled_off(): void
    {
        $category = $this->makeCategory();
        AdminConfig::updateOrCreate(
            ['key' => 'seo.sitemap_include_categories', 'store_id' => config('app.storeId')],
            ['value' => '0', 'group' => 'seo', 'code' => 'seo_settings', 'sort' => 0],
        );
        self::clearAdminConfigCache();
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee($category->alias, escape: false);
    }

    private function setExcludeAliases(string $patterns): void
    {
        AdminConfig::updateOrCreate(
            ['key' => 'seo.sitemap_exclude_aliases', 'store_id' => config('app.storeId')],
            ['value' => $patterns, 'group' => 'seo', 'code' => 'seo_settings', 'sort' => 0],
        );
        self::clearAdminConfigCache();
    }

    public function test_sitemap_does_not_exclude_product_when_pattern_does_not_match(): void
    {
        $product = $this->makeProduct();
        $this->setExcludeAliases("old-*\n");
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertSee($product->alias, escape: false);
    }

    public function test_sitemap_excludes_product_matching_exact_alias(): void
    {
        $product = $this->makeProduct();
        $this->setExcludeAliases($product->alias);
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee($product->alias, escape: false);
    }

    public function test_sitemap_excludes_category_matching_wildcard_pattern(): void
    {
        $category = $this->makeCategory();
        $this->setExcludeAliases('zz-seo-category-*');
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee($category->alias, escape: false);
    }

    public function test_sitemap_excludes_front_page_matching_alias(): void
    {
        $page = FrontPage::create(['alias' => 'zz-seo-exclude-page-' . uniqid(), 'status' => 1, 'store_id' => config('app.storeId')]);
        $this->setExcludeAliases($page->alias);
        $this->bustSitemapCache();

        $response = $this->get('/sitemap.xml');

        $response->assertDontSee($page->alias, escape: false);
    }

    private static function clearAdminConfigCache(): void
    {
        $ref = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        // WHY []: declared as an array and read via array_key_exists(); null makes later gp247_config() calls throw.
        $ref->setValue(null, []);
    }
}
