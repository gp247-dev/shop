<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Library\SeoMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Unit tests for JSON-LD builder methods in SeoMeta, plus HTTP-level
 * regression tests that assert the SEO <head> block (RISK-TECH-012) and
 * per-page JSON-LD (US-SEO-005) actually render on a real request — not
 * just that the underlying helper functions are correct in isolation.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-005
 * @aidlc-adr ADR-014
 */
class JsonLdTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    public function test_organization_jsonld_contains_schema_type(): void
    {
        $json = SeoMeta::buildOrganizationJsonLd(
            name: 'Test Store',
            url: 'https://example.test',
            logoUrl: 'https://example.test/logo.png',
        );

        $data = json_decode($json, true);

        $this->assertEquals('https://schema.org', $data['@context']);
        $this->assertEquals('Organization', $data['@type']);
        $this->assertEquals('Test Store', $data['name']);
        $this->assertEquals('https://example.test', $data['url']);
    }

    public function test_breadcrumb_jsonld_builds_list_items(): void
    {
        $items = [
            ['name' => 'Home',     'url' => 'https://example.test/'],
            ['name' => 'Products', 'url' => 'https://example.test/products.html'],
            ['name' => 'Widget',   'url' => null],
        ];

        $json = SeoMeta::buildBreadcrumbJsonLd($items);
        $data = json_decode($json, true);

        $this->assertEquals('BreadcrumbList', $data['@type']);
        $this->assertCount(3, $data['itemListElement']);
        $this->assertEquals(1, $data['itemListElement'][0]['position']);
        $this->assertEquals('Widget', $data['itemListElement'][2]['name']);
    }

    public function test_product_jsonld_contains_price(): void
    {
        $json = SeoMeta::buildProductJsonLd(
            name: 'My Widget',
            url: 'https://example.test/my-widget.html',
            price: '19.99',
            currency: 'USD',
            availability: 'InStock',
            imageUrl: 'https://example.test/img/widget.jpg',
            description: 'A great widget',
        );

        $data = json_decode($json, true);

        $this->assertEquals('Product', $data['@type']);
        $this->assertEquals('My Widget', $data['name']);
        $this->assertEquals('19.99', $data['offers']['price']);
        $this->assertEquals('USD', $data['offers']['priceCurrency']);
        $this->assertStringContainsString('InStock', $data['offers']['availability']);
    }

    public function test_jsonld_output_escapes_html_characters(): void
    {
        $json = SeoMeta::buildOrganizationJsonLd(
            name: '<script>alert(1)</script>',
            url: 'https://example.test',
            logoUrl: null,
        );

        // JSON_HEX_TAG must escape < and >
        $this->assertStringNotContainsString('<script>', $json);
    }

    public function test_breadcrumb_jsonld_with_empty_items_returns_null(): void
    {
        $result = SeoMeta::buildBreadcrumbJsonLd([]);

        $this->assertNull($result);
    }

    public function test_article_jsonld_contains_headline_and_omits_empty_optional_fields(): void
    {
        $json = SeoMeta::buildArticleJsonLd(
            headline: 'My Article',
            url: 'https://example.test/news/my-article.html',
            imageUrl: null,
            datePublished: null,
            dateModified: null,
            description: 'Short summary',
        );

        $data = json_decode($json, true);

        $this->assertEquals('Article', $data['@type']);
        $this->assertEquals('My Article', $data['headline']);
        $this->assertEquals('Short summary', $data['description']);
        $this->assertArrayNotHasKey('image', $data);
        $this->assertArrayNotHasKey('datePublished', $data);
        $this->assertArrayNotHasKey('dateModified', $data);
    }

    public function test_article_jsonld_includes_optional_fields_when_present(): void
    {
        $json = SeoMeta::buildArticleJsonLd(
            headline: 'My Article',
            url: 'https://example.test/news/my-article.html',
            imageUrl: 'https://example.test/img/article.jpg',
            datePublished: '2026-07-11T00:00:00+00:00',
            dateModified: '2026-07-11T00:00:00+00:00',
            description: 'Short summary',
        );

        $data = json_decode($json, true);

        $this->assertEquals('https://example.test/img/article.jpg', $data['image']);
        $this->assertEquals('2026-07-11T00:00:00+00:00', $data['datePublished']);
        $this->assertEquals('2026-07-11T00:00:00+00:00', $data['dateModified']);
    }

    /**
     * US-SEO-005: the admin master toggle
     * (`seo.jsonld_enabled`) must remove *every* JSON-LD script — both
     * site-wide (Organization) and per-page (`@stack('jsonld')`) — not just
     * hide one of them.
     */
    public function test_jsonld_disabled_admin_wide_removes_all_ldjson_script_tags(): void
    {
        $this->setUpFrontStore();

        \GP247\Core\Models\AdminConfig::updateOrCreate(
            ['key' => 'seo.jsonld_enabled', 'store_id' => config('app.storeId')],
            ['value' => '0', 'group' => 'seo', 'code' => 'seo_settings', 'sort' => 0]
        );
        $this->clearAdminConfigCache();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('application/ld+json', escape: false);

        \GP247\Core\Models\AdminConfig::where('key', 'seo.jsonld_enabled')
            ->where('store_id', config('app.storeId'))
            ->delete();
        $this->clearAdminConfigCache();

        $this->tearDownFrontStore();
    }

    /**
     * Resets AdminConfig's static per-store cache so a config row written
     * mid-test is picked up immediately by gp247_config()/SeoMeta.
     *
     * WHY []: the property is declared as an array and read with
     * array_key_exists(); resetting it to null makes every later
     * gp247_config() call in the same process throw a TypeError (HTTP 500
     * cascade across the Seo suite).
     */
    private function clearAdminConfigCache(): void
    {
        $ref = new \ReflectionProperty(\GP247\Core\Models\AdminConfig::class, 'getAllConfigOfStore');
        $ref->setValue(null, []);
    }

    /**
     * Regression guard for RISK-TECH-012: a prior unrelated refactor silently
     * dropped canonical/JSON-LD Organization from layout.blade.php's <head>
     * without any test catching it. Assert both actually render on a real
     * front page response, not just that SeoMeta's helper functions work.
     */
    public function test_canonical_and_organization_jsonld_render_on_a_real_front_page(): void
    {
        $this->setUpFrontStore();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('rel="canonical"', escape: false);
        $response->assertSee('"@type":"Organization"', escape: false);

        $this->tearDownFrontStore();
    }

    /**
     * US-SEO-005: JSON-LD Product/BreadcrumbList must be pushed from the shop
     * product detail screen (gp247/shop) and consumed by the shared partials
     * owned by gp247/front — proving the ADR-014 "shop plugs data into front"
     * contract actually works end-to-end, not just that the partials parse.
     */
    public function test_product_and_breadcrumb_jsonld_render_on_shop_product_detail_page(): void
    {
        $this->setUpFrontStore();
        $product = $this->makeProduct('ZZ SEO JSON-LD Product');

        // WHY: use the model's own gp247_route_front()-backed getUrl(), not
        // Laravel's bare route() helper — this dev environment has
        // GP247_SEO_LANG=1, so a valid front URL must include the /{lang}/
        // segment (auto-injected by gp247_route_front(), which route() does
        // not know about). Pre-existing bug surfaced while adding
        // NewsPluginJsonLdTest.
        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"@type":"Product"', escape: false);
        $response->assertSee('"@type":"BreadcrumbList"', escape: false);
        $response->assertSee('ZZ SEO JSON-LD Product', escape: false);

        $this->tearDownFrontStore();
    }
}
