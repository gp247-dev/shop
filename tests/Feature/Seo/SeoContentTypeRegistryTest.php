<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Library\SeoAudit;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Models\FrontPage;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * Packages and plugins register the content types that carry their own SEO data;
 * front never knows shop or a plugin in advance (SEO phase 1).
 * A type may also say where its content lives, so tools that list content (the SEO
 * check, plugins) cover every registered type.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-content-type-registry
 * @aidlc-adr ADR-seo-content-meta-table, ADR-seo-pro-base-boundary
 */
class SeoContentTypeRegistryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_front_registers_pages_and_shop_registers_products_categories_and_brands(): void
    {
        $types = array_keys(SeoContentMeta::types());

        foreach (['page', 'product', 'category', 'brand'] as $type) {
            $this->assertContains($type, $types);
        }
    }

    public function test_a_plugin_can_register_its_own_content_type(): void
    {
        config(['gp247-config.front.seo_content_types.zz_article' => ['label' => 'zz.article']]);

        SeoContentMeta::save('zz_article', 'zz-1', ['noindex' => true]);

        $this->assertTrue(SeoContentMeta::for('zz_article', 'zz-1')['noindex']);
    }

    public function test_an_unregistered_type_is_neither_stored_nor_read(): void
    {
        SeoContentMeta::save('zz_unknown', 'zz-2', ['noindex' => true]);

        $this->assertSame(0, DB::table(GP247_DB_PREFIX . 'front_seo_meta')->where('type', 'zz_unknown')->count());
        $this->assertNull(SeoContentMeta::for('zz_unknown', 'zz-2'));
    }

    public function test_a_badly_formed_type_key_is_ignored(): void
    {
        config(['gp247-config.front.seo_content_types.Bad Key!' => ['label' => 'x']]);

        $this->assertArrayNotHasKey('Bad Key!', SeoContentMeta::types());
    }

    public function test_a_page_without_declared_content_does_not_read_seo_data(): void
    {
        DB::enableQueryLog();
        $this->assertNull(SeoContentMeta::current());
        $queries = array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'front_seo_meta'));
        DB::disableQueryLog();

        $this->assertSame([], $queries);
    }

    public function test_a_declared_content_is_read_once_per_request(): void
    {
        config(['gp247-config.front.seo_content_types.zz_article' => ['label' => 'zz.article']]);
        SeoContentMeta::save('zz_article', 'zz-3', ['nofollow' => true]);
        SeoContentMeta::subject('zz_article', 'zz-3');

        DB::enableQueryLog();
        SeoContentMeta::current();
        SeoContentMeta::current();
        $queries = array_filter(DB::getQueryLog(), static fn ($q) => str_contains($q['query'], 'front_seo_meta'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
    }

    public function test_front_and_shop_say_where_pages_products_categories_and_brands_live(): void
    {
        $sources = SeoContentMeta::sources();

        $this->assertSame('front_page', $sources['page']['table']);
        $this->assertSame('front_page_description', $sources['page']['desc']);
        $this->assertSame('page_id', $sources['page']['fk']);
        $this->assertSame('admin_page.edit', $sources['page']['edit_route']);
        $this->assertSame(['content'], $sources['page']['text']);
        $this->assertSame('shop_product', $sources['product']['table']);
        $this->assertSame('admin_product.edit', $sources['product']['edit_route']);
        $this->assertSame('shop_category', $sources['category']['table']);
        $this->assertSame('shop_brand', $sources['brand']['table']);
        $this->assertNull($sources['brand']['desc']);
        $this->assertSame('name', $sources['brand']['name']);
    }

    public function test_the_seo_check_covers_a_content_type_a_plugin_registers_with_its_source(): void
    {
        config(['gp247-config.front.seo_content_types.zz_plugin_page' => [
            'label' => 'zz.page',
            'source' => [
                'table' => 'front_page', 'desc' => 'front_page_description', 'fk' => 'page_id',
                'name' => 'name', 'edit_route' => 'admin_page.edit', 'class' => FrontPage::class,
            ],
        ]]);
        SeoContentMeta::save('zz_plugin_page', 'zz-gone-' . uniqid(), ['noindex' => true]);

        $this->assertArrayHasKey('zz_plugin_page', SeoContentMeta::sources());
        SeoAudit::cleanOrphans();

        $this->assertSame(0, DB::table(GP247_DB_PREFIX . 'front_seo_meta')->where('type', 'zz_plugin_page')->count());
    }

    public function test_a_type_without_a_source_keeps_its_seo_values_but_is_not_listed(): void
    {
        config(['gp247-config.front.seo_content_types.zz_article' => ['label' => 'zz.article']]);
        SeoContentMeta::save('zz_article', 'zz-4', ['noindex' => true]);

        $this->assertArrayNotHasKey('zz_article', SeoContentMeta::sources());
        SeoAudit::cleanOrphans();

        $this->assertTrue(SeoContentMeta::for('zz_article', 'zz-4')['noindex']);
    }

    public function test_a_source_whose_model_does_not_own_the_declared_table_is_ignored_and_its_seo_values_are_kept(): void
    {
        config(['gp247-config.front.seo_content_types.zz_mismatch' => [
            'label' => 'zz.mismatch',
            'source' => ['table' => 'front_page', 'name' => 'name', 'class' => ShopProduct::class],
        ]]);
        SeoContentMeta::save('zz_mismatch', 'zz-5', ['noindex' => true]);

        $this->assertArrayNotHasKey('zz_mismatch', SeoContentMeta::sources());
        SeoAudit::cleanOrphans();

        $this->assertTrue(SeoContentMeta::for('zz_mismatch', 'zz-5')['noindex']);
    }

    public function test_a_source_with_an_unsafe_table_or_column_name_is_ignored(): void
    {
        $page = ['table' => 'front_page', 'name' => 'name', 'class' => FrontPage::class];
        config(['gp247-config.front.seo_content_types.zz_unsafe_table' => [
            'label' => 'x', 'source' => ['table' => 'front_page`; DROP TABLE x; --'] + $page,
        ]]);
        config(['gp247-config.front.seo_content_types.zz_unsafe_column' => [
            'label' => 'x', 'source' => ['name' => 'name) OR (1'] + $page,
        ]]);
        config(['gp247-config.front.seo_content_types.zz_missing_class' => [
            'label' => 'x', 'source' => ['class' => 'App\ZzNoSuchModel'] + $page,
        ]]);

        $sources = SeoContentMeta::sources();

        $this->assertArrayNotHasKey('zz_unsafe_table', $sources);
        $this->assertArrayNotHasKey('zz_unsafe_column', $sources);
        $this->assertArrayNotHasKey('zz_missing_class', $sources);
        $this->assertArrayHasKey('page', $sources);
    }
}
