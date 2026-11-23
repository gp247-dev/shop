<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Controllers\SeoController;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Models\FrontPage;
use GP247\Front\Models\FrontPageDescription;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The site owner keeps one piece of content out of search results (noindex /
 * nofollow) and points its canonical elsewhere; noindex content leaves the sitemap
 * (SEO phase 1).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-content-index-control, US-SEO-004
 * @aidlc-adr ADR-seo-content-meta-table, ADR-seo-indexing-signals
 */
class SeoContentIndexControlTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_a_product_marked_noindex_tells_search_engines_not_to_index_it(): void
    {
        $product = $this->makeProduct('ZZ Hidden Product');
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        $this->get($product->getUrl())
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, follow">', escape: false);
    }

    public function test_noindex_and_nofollow_together_give_noindex_nofollow(): void
    {
        $product = $this->makeProduct('ZZ Hidden Nofollow Product');
        SeoContentMeta::save('product', $product->id, ['noindex' => true, 'nofollow' => true]);

        $this->get($product->getUrl())->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }

    public function test_nofollow_alone_keeps_the_product_indexable(): void
    {
        $product = $this->makeProduct('ZZ Nofollow Product');
        SeoContentMeta::save('product', $product->id, ['nofollow' => true]);

        $this->get($product->getUrl())->assertSee('<meta name="robots" content="index, nofollow">', escape: false);
    }

    public function test_a_product_without_settings_has_no_robots_tag(): void
    {
        $product = $this->makeProduct('ZZ Normal Robots Product');

        $this->get($product->getUrl())->assertDontSee('<meta name="robots"', escape: false);
    }

    public function test_a_filtered_category_url_stays_noindex_even_when_the_category_sets_only_nofollow(): void
    {
        $category = $this->makeCategory('ZZ Filter Category');
        SeoContentMeta::save('category', $category->id, ['nofollow' => true]);

        $this->get($category->getUrl() . '?filter_sort=price_asc')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }

    public function test_an_own_canonical_is_used_on_the_first_unfiltered_page(): void
    {
        $product = $this->makeProduct('ZZ Canonical Product');
        SeoContentMeta::save('product', $product->id, [
            'desc' => [gp247_get_locale() => ['canonical' => 'https://brand.example.test/zz-original']],
        ]);

        $this->get($product->getUrl())
            ->assertSee('<link rel="canonical" href="https://brand.example.test/zz-original" />', escape: false)
            ->assertSee('<meta property="og:url" content="https://brand.example.test/zz-original" />', escape: false);
    }

    public function test_an_own_canonical_is_not_used_on_a_later_page(): void
    {
        $category = $this->makeCategory('ZZ Canonical Category');
        SeoContentMeta::save('category', $category->id, [
            'desc' => [gp247_get_locale() => ['canonical' => 'https://brand.example.test/zz-cat']],
        ]);

        $this->get($category->getUrl() . '?page=2')
            ->assertDontSee('href="https://brand.example.test/zz-cat"', escape: false);
    }

    public function test_a_canonical_that_is_not_http_is_refused(): void
    {
        $product = $this->makeProduct('ZZ Bad Canonical Product');

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        SeoContentMeta::save('product', $product->id, [
            'desc' => [gp247_get_locale() => ['canonical' => 'javascript:alert(1)']],
        ]);
    }

    public function test_noindex_content_is_left_out_of_the_sitemap(): void
    {
        $shown = $this->makeProduct('ZZ Sitemap Shown');
        $hidden = $this->makeProduct('ZZ Sitemap Hidden');
        $hiddenCategory = $this->makeCategory('ZZ Sitemap Hidden Category');
        $hiddenPage = $this->makePage('ZZ Sitemap Hidden Page');
        $nofollowOnly = $this->makeProduct('ZZ Sitemap Nofollow');
        SeoContentMeta::save('product', $hidden->id, ['noindex' => true]);
        SeoContentMeta::save('category', $hiddenCategory->id, ['noindex' => true]);
        SeoContentMeta::save('page', $hiddenPage->id, ['noindex' => true]);
        SeoContentMeta::save('product', $nofollowOnly->id, ['nofollow' => true]);
        SeoController::bumpCacheVersion(config('app.storeId'));

        $response = $this->get('/sitemap.xml');

        $response->assertSee($shown->alias, escape: false);
        $response->assertSee($nofollowOnly->alias, escape: false);
        $response->assertDontSee($hidden->alias, escape: false);
        $response->assertDontSee($hiddenCategory->alias, escape: false);
        $response->assertDontSee($hiddenPage->alias, escape: false);
    }

    private function makeCategory(string $name): ShopCategory
    {
        $category = ShopCategory::create([
            'alias' => 'zz-idx-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0,
            'status' => 1, 'sort' => 0, 'store_id' => $this->storeId(),
        ]);
        foreach ($this->langCodes() as $code) {
            ShopCategoryDescription::insert(['category_id' => $category->id, 'lang' => $code, 'name' => $name, 'keyword' => '', 'description' => '']);
        }

        return $category;
    }

    private function makePage(string $name): FrontPage
    {
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-idx-page-' . uniqid(), 'status' => 1, 'store_id' => $this->storeId()]);
        foreach ($this->langCodes() as $code) {
            FrontPageDescription::create(['page_id' => $page->id, 'lang' => $code, 'name' => $name, 'keyword' => '', 'description' => '', 'content' => 'x']);
        }

        return $page;
    }
}
