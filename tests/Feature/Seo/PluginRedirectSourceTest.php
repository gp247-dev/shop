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
 * A package or plugin writes a 301 to a content of the site under its own source
 * name, through the same safety rules as the automatic and imported redirects, and
 * the "Redirect 301" screen shows which tool made it.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-plugin-redirect-source
 * @aidlc-adr ADR-seo-url-lifecycle
 */
class PluginRedirectSourceTest extends TestCase
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
        config(['gp247-config.front.seo_redirect_sources' => ['zz_tool' => ['label' => 'admin.seo_redirect.source_import']]]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_a_plugin_sends_a_broken_address_to_a_product_under_its_own_source(): void
    {
        $product = $this->makeProduct('ZZ Plugin Redirect Product');
        $from = '/zz-typo-' . uniqid();

        $this->assertSame('ok', SeoUrlLifecycle::checkContentRedirect($this->storeId(), $from, 'product', $product->alias));
        $id = SeoUrlLifecycle::redirectToContent($this->storeId(), $from, 'product', $product->alias, 'zz_tool');

        $this->assertIsInt($id);
        $row = FrontRedirect::find($id);
        $this->assertSame('zz_tool', $row->source);
        $this->assertSame($this->path($product->getUrl()), $row->to);
        $response = $this->get($from);
        $response->assertStatus(301);
        $this->assertStringEndsWith($this->path($product->getUrl()), (string) $response->headers->get('Location'));
    }

    public function test_a_source_that_is_not_registered_or_belongs_to_the_base_is_refused(): void
    {
        $product = $this->makeProduct('ZZ Plugin Redirect Refused');

        foreach (['zz_unknown', 'import', 'auto', 'manual', 'zz_way_too_long_source', 'ZZ Bad!'] as $source) {
            $from = '/zz-refused-' . uniqid();
            $this->assertNull(SeoUrlLifecycle::redirectToContent($this->storeId(), $from, 'product', $product->alias, $source), $source);
            $this->assertNull(FrontRedirect::where('from', $from)->first(), $source);
        }
    }

    public function test_a_live_product_address_is_never_taken_over(): void
    {
        $product = $this->makeProduct('ZZ Plugin Redirect Target');
        $other = $this->makeProduct('ZZ Plugin Redirect Live');
        $live = $this->path($other->getUrl());

        $this->assertSame('owned_by_content', SeoUrlLifecycle::checkContentRedirect($this->storeId(), $live, 'product', $product->alias));
        $this->assertNull(SeoUrlLifecycle::redirectToContent($this->storeId(), $live, 'product', $product->alias, 'zz_tool'));
    }

    public function test_an_existing_redirect_is_never_rewritten_and_the_same_one_keeps_its_source(): void
    {
        $product = $this->makeProduct('ZZ Plugin Redirect Existing');
        $to = $this->path($product->getUrl());
        $elsewhere = FrontRedirect::create(['from' => '/zz-hand-' . uniqid(), 'to' => '/zz-other.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId()]);
        $same = FrontRedirect::create(['from' => '/zz-same-' . uniqid(), 'to' => $to, 'code' => 301, 'status' => 1, 'store_id' => $this->storeId(), 'source' => 'manual']);

        $this->assertNull(SeoUrlLifecycle::redirectToContent($this->storeId(), $elsewhere->from, 'product', $product->alias, 'zz_tool'));
        $this->assertSame('/zz-other.html', $elsewhere->fresh()->to);

        $this->assertSame((int) $same->id, SeoUrlLifecycle::redirectToContent($this->storeId(), $same->from, 'product', $product->alias, 'zz_tool'));
        $this->assertSame('manual', $same->fresh()->source);
    }

    public function test_imported_redirects_still_work_as_before(): void
    {
        $product = $this->makeProduct('ZZ Plugin Redirect Import');
        $legacy = '/products/zz-import-' . uniqid();

        $id = SeoUrlLifecycle::redirectLegacyPath($this->storeId(), $legacy, 'product', $product->alias);

        $this->assertSame('import', FrontRedirect::find($id)->source);
    }

    public function test_the_redirect_screen_shows_the_label_of_a_registered_source_and_the_raw_name_of_a_removed_one(): void
    {
        $this->asUser(slugs: ['admin_seo_redirect']);
        $product = $this->makeProduct('ZZ Plugin Redirect Badge');
        config(['gp247-config.front.seo_redirect_sources.zz_tool' => ['label' => 'admin.seo_redirect.source_auto']]);
        SeoUrlLifecycle::redirectToContent($this->storeId(), '/zz-badge-' . uniqid(), 'product', $product->alias, 'zz_tool');
        FrontRedirect::create(['from' => '/zz-gone-' . uniqid(), 'to' => '/zz-x.html', 'code' => 301, 'status' => 1, 'store_id' => $this->storeId(), 'source' => 'zz_gone']);

        Livewire::test(SeoRedirectManager::class)
            ->assertSeeHtml('data-testid="seo-redirect-source-badge"')
            ->assertSee(gp247_language_render('admin.seo_redirect.source_auto'))
            ->assertSee('zz_gone');
    }

    public function test_editing_a_plugin_redirect_by_hand_makes_it_manual(): void
    {
        $this->asUser(slugs: ['admin_seo_redirect']);
        $product = $this->makeProduct('ZZ Plugin Redirect Edit');
        $id = SeoUrlLifecycle::redirectToContent($this->storeId(), '/zz-edit-' . uniqid(), 'product', $product->alias, 'zz_tool');

        Livewire::test(SeoRedirectManager::class)
            ->call('editRow', $id)
            ->set('form.to', '/zz-hand-target.html')
            ->call('save');

        $this->assertSame('manual', FrontRedirect::find($id)->source);
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
