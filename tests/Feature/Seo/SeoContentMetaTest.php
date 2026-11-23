<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Models\FrontPage;
use GP247\Front\Models\FrontPageDescription;
use GP247\Shop\Models\ShopBrand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The site owner gives a single piece of content its own SEO title, description and
 * share image; pages without own values keep today's behaviour (SEO phase 1).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-content-meta, US-SEO-001
 * @aidlc-adr ADR-seo-content-meta-table
 */
class SeoContentMetaTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
        $this->ensureStoreName();
    }

    /**
     * Give the store a name in the current locale (rolled back): MetaHead's JSON-LD
     * Organization needs one, and a copied test database may lack it.
     */
    private function ensureStoreName(): void
    {
        $storeId = (\GP247\Core\Models\AdminStore::find(config('app.storeId')) ?? \GP247\Core\Models\AdminStore::first())->id;
        $row = DB::table(GP247_DB_PREFIX . 'admin_store_description')->where('store_id', $storeId)->where('lang', app()->getLocale())->first();
        if ($row === null || trim((string) $row->name) === '') {
            DB::table(GP247_DB_PREFIX . 'admin_store_description')->updateOrInsert(
                ['store_id' => $storeId, 'lang' => app()->getLocale()],
                ['name' => 'ZZ Store', 'description' => 'ZZ store description', 'keyword' => '']
            );
            self::clearAdminStoreCache();
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_a_product_with_its_own_seo_title_and_description_shows_them_instead_of_its_name(): void
    {
        $product = $this->makeProduct('ZZ Plain Product Name');
        SeoContentMeta::save('product', $product->id, [
            'desc' => [gp247_get_locale() => ['title' => 'ZZ Best Running Shoes 2026', 'description' => 'ZZ Light shoes for daily runs']],
        ]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('<title>ZZ Best Running Shoes 2026</title>', escape: false);
        $response->assertSee('<meta property="og:title" content="ZZ Best Running Shoes 2026" />', escape: false);
        $response->assertSee('<meta name="description" content="ZZ Light shoes for daily runs">', escape: false);
        $response->assertSee('<meta property="og:description" content="ZZ Light shoes for daily runs" />', escape: false);
    }

    public function test_a_product_without_own_seo_values_keeps_its_name_as_the_title(): void
    {
        $product = $this->makeProduct('ZZ Untouched Product');

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('<title>ZZ Untouched Product</title>', escape: false);
    }

    public function test_an_own_share_image_replaces_the_product_image(): void
    {
        $product = $this->makeProduct('ZZ Share Image Product', ['image' => 'https://cdn.example.test/zz-product.jpg']);
        SeoContentMeta::save('product', $product->id, ['og_image' => 'https://cdn.example.test/zz-share.jpg']);

        $response = $this->get($product->getUrl());

        $response->assertSee('<meta property="og:image" content="https://cdn.example.test/zz-share.jpg"', escape: false);
    }

    public function test_a_brand_page_gets_its_own_description_instead_of_the_store_description(): void
    {
        $brand = ShopBrand::create([
            'name' => 'ZZ Seo Brand', 'alias' => 'zz-seo-brand-' . uniqid(), 'image' => '',
            'sort' => 0, 'status' => 1, 'store_id' => $this->storeId(),
        ]);
        SeoContentMeta::save('brand', $brand->id, [
            'desc' => [gp247_get_locale() => ['description' => 'ZZ Official ZZ brand products']],
        ]);

        $response = $this->get($brand->getUrl());

        $response->assertOk();
        $response->assertSee('<meta name="description" content="ZZ Official ZZ brand products">', escape: false);
    }

    public function test_a_cms_page_uses_its_own_seo_title(): void
    {
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-seo-page-' . uniqid(), 'status' => 1, 'store_id' => $this->storeId()]);
        foreach ($this->langCodes() as $code) {
            FrontPageDescription::create(['page_id' => $page->id, 'lang' => $code, 'name' => 'ZZ About', 'keyword' => '', 'description' => '', 'content' => 'x']);
        }
        SeoContentMeta::save('page', $page->id, ['desc' => [gp247_get_locale() => ['title' => 'ZZ About our shop in Hanoi']]]);

        $response = $this->get(gp247_route_front('front.page.detail', ['alias' => $page->alias]));

        $response->assertOk();
        $response->assertSee('<title>ZZ About our shop in Hanoi</title>', escape: false);
    }

    public function test_the_seo_title_is_escaped_when_printed(): void
    {
        $product = $this->makeProduct('ZZ Escape Product');
        SeoContentMeta::save('product', $product->id, [
            'desc' => [gp247_get_locale() => ['title' => 'ZZ <script>alert(1)</script>']],
        ]);

        $response = $this->get($product->getUrl());

        $response->assertDontSee('<title>ZZ <script>alert(1)</script></title>', escape: false);
        $response->assertSee('<title>ZZ &lt;script&gt;alert(1)&lt;/script&gt;</title>', escape: false);
    }

    public function test_meta_head_without_a_declared_content_is_unchanged(): void
    {
        $html = Blade::render('<x-gp247-front::meta-head title="ZZ Plain Title" description="ZZ plain description" />');

        $this->assertStringContainsString('<title>ZZ Plain Title</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="ZZ plain description">', $html);
    }

    public function test_saving_only_blank_values_stores_nothing_and_removes_an_earlier_row(): void
    {
        $product = $this->makeProduct('ZZ Blank Seo Product');
        SeoContentMeta::save('product', $product->id, ['desc' => [gp247_get_locale() => ['title' => 'ZZ Something']]]);
        $this->assertSame(1, $this->seoRows('product', $product->id));

        SeoContentMeta::save('product', $product->id, [
            'noindex' => false, 'nofollow' => false, 'og_image' => '   ',
            'desc' => [gp247_get_locale() => ['title' => '  ', 'description' => '', 'canonical' => null]],
        ]);

        $this->assertSame(0, $this->seoRows('product', $product->id));
        $this->assertNull(SeoContentMeta::for('product', $product->id));
    }

    public function test_deleting_a_product_removes_its_seo_data(): void
    {
        $product = $this->makeProduct('ZZ Deleted Seo Product');
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        $product->delete();

        $this->assertSame(0, $this->seoRows('product', $product->id));
    }

    public function test_deleting_a_brand_or_page_removes_its_seo_data(): void
    {
        $brand = ShopBrand::create(['name' => 'ZZ Del Brand', 'alias' => 'zz-del-brand-' . uniqid(), 'image' => '', 'sort' => 0, 'status' => 1, 'store_id' => $this->storeId()]);
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-del-page-' . uniqid(), 'status' => 1, 'store_id' => $this->storeId()]);
        SeoContentMeta::save('brand', $brand->id, ['noindex' => true]);
        SeoContentMeta::save('page', $page->id, ['noindex' => true]);

        $brand->delete();
        $page->delete();

        $this->assertSame(0, $this->seoRows('brand', $brand->id));
        $this->assertSame(0, $this->seoRows('page', $page->id));
    }

    private function seoRows(string $type, $id): int
    {
        return DB::table(GP247_DB_PREFIX . 'front_seo_meta')->where('type', $type)->where('content_id', (string) $id)->count();
    }
}
