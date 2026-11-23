<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Admin\Livewire\SeoMetaSettings;
use GP247\Front\Library\SeoContentMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * A title template per page type puts the store name into every generated title,
 * without touching titles the site owner typed (SEO phase 1).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-title-template
 * @aidlc-adr ADR-seo-content-meta-table
 */
class SeoTitleTemplateTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetConfigCache();
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

    public function test_the_product_template_wraps_the_product_name(): void
    {
        $this->setTemplate('product', '{title} | {site}');
        $product = $this->makeProduct('ZZ Template Product');

        $this->get($product->getUrl())
            ->assertSee('<title>ZZ Template Product | ' . e(gp247_store_info('name')) . '</title>', escape: false);
    }

    public function test_a_typed_seo_title_is_never_wrapped(): void
    {
        $this->setTemplate('product', '{title} | {site}');
        $product = $this->makeProduct('ZZ Template Product Two');
        SeoContentMeta::save('product', $product->id, ['desc' => [gp247_get_locale() => ['title' => 'ZZ Typed Title']]]);

        $this->get($product->getUrl())->assertSee('<title>ZZ Typed Title</title>', escape: false);
    }

    public function test_without_a_template_titles_stay_as_before(): void
    {
        $product = $this->makeProduct('ZZ No Template Product');

        $this->get($product->getUrl())->assertSee('<title>ZZ No Template Product</title>', escape: false);
    }

    public function test_the_default_template_wraps_a_page_without_declared_content(): void
    {
        $this->setTemplate('default', '{title} - {site}');

        $html = Blade::render('<x-gp247-front::meta-head title="ZZ Search results" />');

        $this->assertStringContainsString('<title>ZZ Search results - ' . e(gp247_store_info('name')) . '</title>', $html);
    }

    public function test_a_title_equal_to_the_store_name_is_not_wrapped_twice(): void
    {
        $this->setTemplate('default', '{title} | {site}');
        $site = (string) gp247_store_info('name');

        $html = Blade::render('<x-gp247-front::meta-head :title="$t" />', ['t' => $site]);

        $this->assertStringContainsString('<title>' . e($site) . '</title>', $html);
    }

    public function test_the_admin_saves_a_template_per_type(): void
    {
        $this->asUser(slugs: ['admin_seo_meta']);

        Livewire::test(SeoMetaSettings::class)
            ->set('titleTemplates.product', '{title} | {site}')
            ->assertHasNoErrors();

        $this->resetConfigCache();
        $this->assertSame('{title} | {site}', SeoContentMeta::titleTemplate('product', config('app.storeId')));
    }

    public function test_a_template_without_the_title_variable_is_refused(): void
    {
        $this->asUser(slugs: ['admin_seo_meta']);

        Livewire::test(SeoMetaSettings::class)
            ->set('titleTemplates.product', 'Always the same title')
            ->assertHasErrors(['titleTemplates.product']);

        $this->resetConfigCache();
        $this->assertSame('', SeoContentMeta::titleTemplate('product', config('app.storeId')));
    }

    private function setTemplate(string $type, string $template): void
    {
        AdminConfig::updateOrCreate(
            ['key' => 'seo.title_template.' . $type, 'store_id' => config('app.storeId')],
            ['group' => 'seo', 'code' => 'seo_meta_settings', 'value' => $template, 'sort' => 0, 'detail' => '']
        );
        $this->resetConfigCache();
    }

    private function resetConfigCache(): void
    {
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
    }
}
