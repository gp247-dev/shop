<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Private pages and filtered listings carry `noindex, follow`; content pages do not.
 *
 * WHY the pages are fetched over HTTP instead of calling SeoMeta directly: the
 * decision lives in the MetaHead component so it works with every template layout,
 * including the ones that never pass a `robots` prop — only a real request through the
 * real layout proves that.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-robots-meta, US-SEO-002
 * @aidlc-adr ADR-seo-indexing-signals
 */
class RobotsMetaTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    private const NOINDEX_TAG = '<meta name="robots" content="noindex, follow">';

    /**
     * Activate the store and reset AdminConfig's per-store memo (another test in this
     * directory leaves it as null, which makes gp247_config() fatal).
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
    }

    /**
     * Restore the store's active flag.
     */
    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_the_cart_page_is_kept_out_of_search_results(): void
    {
        $this->get(gp247_route_front('cart'))->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
    }

    public function test_wishlist_and_compare_pages_are_kept_out_of_search_results(): void
    {
        $this->get(gp247_route_front('cart.wishlist'))->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
        $this->get(gp247_route_front('cart.compare'))->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
    }

    public function test_customer_login_and_forgot_password_pages_are_kept_out_of_search_results(): void
    {
        $this->get(gp247_route_front('customer.login'))->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
        $this->get(gp247_route_front('customer.forgot'))->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
    }

    public function test_internal_search_results_are_kept_out_of_search_results(): void
    {
        $this->get(gp247_route_front('front.search') . '?keyword=zz')
            ->assertOk()
            ->assertSee(self::NOINDEX_TAG, escape: false);
    }

    public function test_a_product_listing_filtered_by_brand_or_sorted_is_kept_out_of_search_results(): void
    {
        $base = gp247_route_front('product.all');

        $this->get($base . '?brand=zz-brand')->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
        $this->get($base . '?filter_sort=price_asc')->assertOk()->assertSee(self::NOINDEX_TAG, escape: false);
    }

    public function test_the_unfiltered_product_listing_and_its_second_page_stay_indexable(): void
    {
        $base = gp247_route_front('product.all');

        $this->get($base)->assertOk()->assertDontSee('name="robots"', escape: false);
        $this->get($base . '?page=2')->assertOk()->assertDontSee('name="robots"', escape: false);
    }

    public function test_a_product_page_and_the_home_page_stay_indexable(): void
    {
        $product = $this->makeProduct('ZZ Robots Product');

        $this->get($product->getUrl())->assertOk()->assertDontSee('name="robots"', escape: false);
        $this->get('/')->assertOk()->assertDontSee('name="robots"', escape: false);
    }

    public function test_a_catch_all_registry_entry_is_ignored_so_the_whole_site_is_not_deindexed(): void
    {
        config(['gp247-config.front.seo_noindex_routes' => array_merge(
            (array) config('gp247-config.front.seo_noindex_routes', []),
            ['*']
        )]);
        $product = $this->makeProduct('ZZ Robots Wildcard Product');

        $this->get($product->getUrl())->assertOk()->assertDontSee('name="robots"', escape: false);
    }

    public function test_page_and_lang_are_never_treated_as_filter_parameters(): void
    {
        config(['gp247-config.front.seo_noindex_query_params' => ['page', 'lang']]);

        $this->get(gp247_route_front('product.all') . '?page=3')->assertOk()->assertDontSee('name="robots"', escape: false);
    }

    public function test_a_robots_value_passed_by_the_caller_wins_over_the_automatic_one(): void
    {
        $html = Blade::render('<x-gp247-front::meta-head robots="noindex, nofollow" />');

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    public function test_a_page_rendered_outside_any_route_has_no_robots_tag(): void
    {
        $html = Blade::render('<x-gp247-front::meta-head />');

        $this->assertStringNotContainsString('name="robots"', $html);
    }
}
