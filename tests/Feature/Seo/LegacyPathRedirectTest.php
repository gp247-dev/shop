<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Admin\Livewire\SeoRedirectManager;
use GP247\Front\Library\SeoUrlLifecycle;
use GP247\Front\Models\FrontRedirect;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\ConfiguresSeoStore;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Links of the platform a shop moved away from (Shopify `/products/x`, WordPress
 * `/product/x/`, Magento `/x.html`) keep working: packages write a 301 from the old
 * path through the base, and redirect lookup tolerates a trailing slash and the
 * letter case of `%xx` escapes.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-legacy-path-redirect, US-SEO-006
 * @aidlc-adr ADR-seo-url-lifecycle
 */
class LegacyPathRedirectTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresSeoStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSeoConfigCache();
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_a_shopify_product_link_is_sent_to_the_product_with_a_301(): void
    {
        $product = $this->makeProduct('ZZ Legacy Shopify Product');
        $legacy = '/products/zz-shopify-' . uniqid();

        $id = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);

        $this->assertIsInt($id);
        $row = FrontRedirect::find($id);
        $this->assertSame($legacy, $row->from);
        $this->assertSame($this->path($product->getUrl()), $row->to);
        $this->assertSame('import', $row->source);
        $this->assertSame(301, (int) $row->code);
        $response = $this->get($legacy);
        $response->assertStatus(301);
        $this->assertStringEndsWith($this->path($product->getUrl()), (string) $response->headers->get('Location'));
    }

    public function test_a_hand_made_redirect_from_an_address_that_matches_no_route_works(): void
    {
        $from = '/zz/old/shop/' . uniqid();
        FrontRedirect::create(['from' => $from, 'to' => '/zz-target.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);

        $response = $this->get($from);

        $response->assertStatus(301);
        $this->assertStringEndsWith('/zz-target.html', (string) $response->headers->get('Location'));
    }

    public function test_a_magento_style_html_address_is_redirected_when_no_page_owns_it(): void
    {
        $product = $this->makeProduct('ZZ Legacy Magento Product');
        $legacy = '/zz-magento-' . uniqid() . '.html';

        $this->assertSame('ok', SeoUrlLifecycle::checkLegacyPath($this->storeId(), $legacy, 'product', $product->alias));
        SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);

        $this->get($legacy)->assertStatus(301);
    }

    public function test_a_wordpress_link_with_a_trailing_slash_still_finds_its_redirect(): void
    {
        $product = $this->makeProduct('ZZ Legacy Woo Product');
        $legacy = '/product/zz-woo-' . uniqid() . '/';

        $id = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);

        $this->assertSame(rtrim($legacy, '/'), FrontRedirect::find($id)->from);
        $this->get($legacy)->assertStatus(301);
        $this->get(rtrim($legacy, '/'))->assertStatus(301);
    }

    public function test_a_hand_made_redirect_typed_with_a_trailing_slash_matches_the_address_without_it(): void
    {
        $from = '/zz-wp-page-' . uniqid() . '/';
        FrontRedirect::create(['from' => $from, 'to' => '/zz-target.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);

        $this->assertNotNull(FrontRedirect::findActive(rtrim($from, '/')));
    }

    public function test_percent_escapes_match_whatever_their_letter_case(): void
    {
        $product = $this->makeProduct('ZZ Legacy Japanese Product');
        $tail = uniqid();
        $lower = '/product/%e3%81%82-' . $tail . '/';

        $id = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $lower, 'product', $product->alias);

        $this->assertSame('/product/%E3%81%82-' . $tail, FrontRedirect::find($id)->from);
        $this->assertNotNull(FrontRedirect::findActive('/product/%E3%81%82-' . $tail));
        $this->assertNotNull(FrontRedirect::findActive('/product/%e3%81%82-' . $tail . '/'));
    }

    public function test_paths_are_normalised_one_way(): void
    {
        $this->assertSame('/', FrontRedirect::normalizePath('/'));
        $this->assertSame('/a/b', FrontRedirect::normalizePath('/a//b/'));
        $this->assertSame('/%E3%81%82', FrontRedirect::normalizePath('/%e3%81%82'));
        $this->assertSame('/%E3%81%82', FrontRedirect::normalizePath('/あ'));
        $this->assertSame('/a%20b', FrontRedirect::normalizePath('/a b'));
        $this->assertSame('/a%2Fb', FrontRedirect::normalizePath('/a%2fb'));
        $this->assertSame('/Ab-c_d.html', FrontRedirect::normalizePath('/Ab-c_d.html'));
    }

    public function test_addresses_off_the_site_or_unsafe_are_never_written(): void
    {
        $product = $this->makeProduct('ZZ Legacy Unsafe Product');
        $before = FrontRedirect::count();

        foreach (['//evil.example/x', 'http://evil.example/x', 'javascript:alert(1)', '/a/../admin', "/zz\x01bad", '/zz\\bad', ''] as $legacy) {
            $this->assertSame('not_local', SeoUrlLifecycle::checkLegacyPath($this->storeId(), $legacy, 'product', $product->alias), $legacy);
            $this->assertNull(SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias), $legacy);
        }
        $this->assertSame($before, FrontRedirect::count());
    }

    public function test_an_old_path_equal_to_the_new_address_is_skipped(): void
    {
        $product = $this->makeProduct('ZZ Legacy Same Product');

        $reason = SeoUrlLifecycle::checkLegacyPath($this->storeId(), $this->path($product->getUrl()), 'product', $product->alias);

        $this->assertSame('same_as_target', $reason);
    }

    public function test_an_old_path_that_is_another_live_product_is_never_taken_over(): void
    {
        $product = $this->makeProduct('ZZ Legacy Target Product');
        $other = $this->makeProduct('ZZ Legacy Other Product');

        $reason = SeoUrlLifecycle::checkLegacyPath($this->storeId(), $this->path($other->getUrl()), 'product', $product->alias);

        $this->assertSame('owned_by_content', $reason);
        $this->assertNull(SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $this->path($other->getUrl()), 'product', $product->alias));
    }

    public function test_fixed_pages_of_the_shop_are_never_taken_over(): void
    {
        $product = $this->makeProduct('ZZ Legacy Reserved Product');

        foreach (['/', '/cart.html', '/customer/login.html'] as $legacy) {
            $this->assertSame('reserved_route', SeoUrlLifecycle::checkLegacyPath($this->storeId(), $legacy, 'product', $product->alias), $legacy);
        }
    }

    public function test_an_existing_redirect_from_the_old_path_is_never_overwritten(): void
    {
        $product = $this->makeProduct('ZZ Legacy Owner Product');
        $manual = FrontRedirect::create(['from' => '/products/zz-own-' . uniqid(), 'to' => '/zz-owner-choice.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);
        $off = FrontRedirect::create(['from' => '/products/zz-off-' . uniqid(), 'to' => $this->path($product->getUrl()), 'code' => 301, 'status' => 0, 'store_id' => $this->storeId()]);

        foreach ([$manual, $off] as $row) {
            $this->assertSame('redirect_exists', SeoUrlLifecycle::checkLegacyPath($this->storeId(), $row->from, 'product', $product->alias));
            $this->assertNull(SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $row->from, 'product', $product->alias));
        }
        $this->assertSame('/zz-owner-choice.html', $manual->fresh()->to);
        $this->assertSame('manual', $manual->fresh()->source);
        $this->assertSame(0, (int) $off->fresh()->status);
    }

    public function test_a_page_answering_only_post_is_never_taken_over(): void
    {
        $product = $this->makeProduct('ZZ Legacy Post Product');

        $this->assertSame('reserved_route', SeoUrlLifecycle::checkLegacyPath($this->storeId(), '/subscribe', 'product', $product->alias));
    }

    public function test_an_old_address_with_a_query_string_is_refused(): void
    {
        $product = $this->makeProduct('ZZ Legacy Query Product');

        $this->assertSame('not_local', SeoUrlLifecycle::checkLegacyPath($this->storeId(), '/index.php?product_id=42', 'product', $product->alias));
    }

    public function test_a_rule_whose_target_is_the_requested_page_never_loops(): void
    {
        $page = '/zz-loop-' . uniqid() . '.html';
        FrontRedirect::create(['from' => $page . '/', 'to' => $page, 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);

        $this->assertNull(FrontRedirect::findActive($page));
        $this->assertNotNull(FrontRedirect::findActive($page . '/'));
    }

    public function test_new_content_switches_off_a_redirect_typed_with_a_trailing_slash_on_its_address(): void
    {
        $alias = 'zz-slash-' . uniqid();
        $hidden = FrontRedirect::create(['from' => '/product/' . $alias . '.html/', 'to' => '/zz-elsewhere.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);

        $product = $this->makeProduct('ZZ Legacy Slash Product', ['alias' => $alias]);

        $this->assertSame('/product/' . $alias . '.html', $this->path($product->getUrl()));
        $this->assertSame(0, (int) $hidden->fresh()->status);
    }

    public function test_an_unknown_content_type_or_an_empty_alias_is_unsupported(): void
    {
        $this->assertSame('unsupported_type', SeoUrlLifecycle::checkLegacyPath($this->storeId(), '/products/zz-x', 'zz-unknown-type', 'zz-x'));
        $this->assertSame('unsupported_type', SeoUrlLifecycle::checkLegacyPath($this->storeId(), '/products/zz-x', 'product', ''));
    }

    public function test_writing_the_same_old_path_twice_keeps_one_redirect(): void
    {
        $product = $this->makeProduct('ZZ Legacy Twice Product');
        $legacy = '/products/zz-twice-' . uniqid();

        $first = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);
        $second = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy . '/', 'product', $product->alias);

        $this->assertSame($first, $second);
        $this->assertSame(1, FrontRedirect::where('store_id', $this->storeId())->where('from', $legacy)->count());
        $this->assertSame('already_redirected', SeoUrlLifecycle::checkLegacyPath($this->storeId(), $legacy, 'product', $product->alias));
    }

    public function test_redirects_pointing_at_the_old_path_now_point_straight_at_the_new_address(): void
    {
        $product = $this->makeProduct('ZZ Legacy Chain Product');
        $legacy = '/products/zz-chain-' . uniqid();
        $older = FrontRedirect::create(['from' => '/zz-older-' . uniqid(), 'to' => $legacy, 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);

        SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);

        $this->assertSame($this->path($product->getUrl()), $older->fresh()->to);
    }

    public function test_the_automatic_redirect_switch_does_not_stop_imported_redirects(): void
    {
        $this->setSeoConfig('seo.auto_redirect_alias', '0', $this->storeId());
        $product = $this->makeProduct('ZZ Legacy Switch Product');

        $this->assertIsInt(SeoUrlLifecycle::redirectLegacyPath($this->storeId(), '/products/zz-switch-' . uniqid(), 'product', $product->alias));
    }

    public function test_an_imported_redirect_is_removed_only_while_nobody_changed_it(): void
    {
        $product = $this->makeProduct('ZZ Legacy Undo Product');
        $since = now()->subSecond();
        $to = $this->path($product->getUrl());
        $kept = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), '/products/zz-undo-a-' . uniqid(), 'product', $product->alias);
        $edited = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), '/products/zz-undo-b-' . uniqid(), 'product', $product->alias);
        $moved = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), '/products/zz-undo-c-' . uniqid(), 'product', $product->alias);
        FrontRedirect::whereKey($edited)->update(['source' => FrontRedirect::SOURCE_MANUAL]);
        FrontRedirect::whereKey($moved)->update(['to' => '/zz-elsewhere.html']);

        $this->assertFalse(SeoUrlLifecycle::removeImportedRedirect($edited, $to, $since));
        $this->assertFalse(SeoUrlLifecycle::removeImportedRedirect($moved, $to, $since));
        $this->assertFalse(SeoUrlLifecycle::removeImportedRedirect($kept, $to, now()->addMinute()));
        $this->assertTrue(SeoUrlLifecycle::removeImportedRedirect($kept, $to, $since));
        $this->assertNull(FrontRedirect::find($kept));
        $this->assertNotNull(FrontRedirect::find($edited));
        $this->assertNotNull(FrontRedirect::find($moved));
    }

    public function test_the_redirect_screen_labels_imported_redirects(): void
    {
        $this->asUser(slugs: ['admin_seo_redirect']);
        $product = $this->makeProduct('ZZ Legacy Badge Product');
        SeoUrlLifecycle::redirectLegacyPath($this->storeId(), '/products/zz-badge-' . uniqid(), 'product', $product->alias);

        Livewire::test(SeoRedirectManager::class)
            ->assertSeeHtml('data-testid="seo-redirect-source-badge"')
            ->assertSee(gp247_language_render('admin.seo_redirect.source_import'));
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
