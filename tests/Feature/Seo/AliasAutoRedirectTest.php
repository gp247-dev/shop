<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Models\FrontPage;
use GP247\Front\Models\FrontRedirect;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\ConfiguresSeoStore;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Changing the address (alias) of a product, category, brand or page keeps old links
 * working through an automatic 301 (SEO phase 2).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-alias-auto-redirect, US-SEO-006
 * @aidlc-adr ADR-seo-url-lifecycle
 */
class AliasAutoRedirectTest extends TestCase
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
    }

    public function test_renaming_a_product_alias_leaves_a_301_from_the_old_address_to_the_new_one(): void
    {
        $product = $this->makeProduct('ZZ Rename Product');
        $old = $this->path($product->getUrl());

        $this->rename($product, 'zz-renamed-' . uniqid());
        $new = $this->path($product->getUrl());

        $redirect = FrontRedirect::where('from', $old)->where('store_id', $product->store_id)->first();
        $this->assertNotNull($redirect);
        $this->assertSame($new, $redirect->to);
        $this->assertSame(301, (int) $redirect->code);
        $this->assertSame(1, (int) $redirect->status);
        $this->assertSame('auto', $redirect->source);
    }

    public function test_a_visitor_on_the_old_address_is_sent_to_the_new_one(): void
    {
        $this->setUpFrontStore();
        $product = $this->makeProduct('ZZ Visit Product');
        $old = $this->path($product->getUrl());
        $this->rename($product, 'zz-visit-new-' . uniqid());

        $response = $this->get($old);

        $response->assertStatus(301);
        $this->assertStringEndsWith($this->path($product->getUrl()), $response->headers->get('Location'));
        $this->tearDownFrontStore();
    }

    public function test_renaming_again_points_older_addresses_straight_at_the_newest(): void
    {
        $product = $this->makeProduct('ZZ Chain Product');
        $a = $this->path($product->getUrl());
        $this->rename($product, 'zz-chain-b-' . uniqid());
        $b = $this->path($product->getUrl());
        $this->rename($product, 'zz-chain-c-' . uniqid());
        $c = $this->path($product->getUrl());

        $this->assertSame($c, FrontRedirect::where('from', $a)->value('to'));
        $this->assertSame($c, FrontRedirect::where('from', $b)->value('to'));
    }

    public function test_going_back_to_an_earlier_alias_switches_off_the_redirect_that_would_hide_the_product(): void
    {
        $product = $this->makeProduct('ZZ Back Product');
        $aliasA = $product->alias;
        $a = $this->path($product->getUrl());
        $this->rename($product, 'zz-back-b-' . uniqid());
        $b = $this->path($product->getUrl());

        $this->rename($product, $aliasA);

        $this->assertSame(0, (int) FrontRedirect::where('from', $a)->value('status'));
        $this->assertSame($a, FrontRedirect::where('from', $b)->value('to'));
        $this->assertSame(0, FrontRedirect::whereColumn('from', 'to')->where('status', 1)->count());
    }

    public function test_a_hand_made_redirect_on_the_new_address_is_switched_off_not_deleted(): void
    {
        $product = $this->makeProduct('ZZ Manual Clash Product');
        $newAlias = 'zz-manual-clash-' . uniqid();
        $newPath = str_replace($product->alias, $newAlias, $this->path($product->getUrl()));
        $manual = FrontRedirect::create(['from' => $newPath, 'to' => '/somewhere', 'code' => 301, 'store_id' => $product->store_id, 'status' => 1]);

        $this->rename($product, $newAlias);

        $manual->refresh();
        $this->assertSame(0, (int) $manual->status);
        $this->assertSame('manual', $manual->source);
    }

    public function test_new_content_at_an_address_held_by_a_redirect_switches_that_redirect_off(): void
    {
        $alias = 'zz-new-at-redirect-' . uniqid();
        $probe = $this->makeProduct('ZZ Probe Product');
        $path = str_replace($probe->alias, $alias, $this->path($probe->getUrl()));
        $redirect = FrontRedirect::create(['from' => $path, 'to' => '/elsewhere', 'code' => 301, 'store_id' => $this->storeId(), 'status' => 1]);

        $this->makeProduct('ZZ New At Redirect', ['alias' => $alias]);

        $this->assertSame(0, (int) $redirect->fresh()->status);
    }

    public function test_an_alias_with_japanese_characters_gets_its_redirect(): void
    {
        $product = $this->makeProduct('ZZ Japanese Alias Product', ['alias' => 'zz-商品-' . uniqid()]);
        $old = $this->path($product->getUrl());

        $this->rename($product, 'zz-新しい-' . uniqid());

        $this->assertSame($this->path($product->getUrl()), FrontRedirect::where('from', $old)->value('to'));
    }

    public function test_an_address_that_would_leave_the_site_is_never_written(): void
    {
        \GP247\Front\Library\SeoUrlLifecycle::aliasChanged('product', $this->storeId(), 'zz-a', 'zz-b');
        $this->assertSame(0, FrontRedirect::where('to', 'like', '//%')->count());
    }

    public function test_with_the_switch_off_no_redirect_is_created(): void
    {
        $this->setSeoConfig('seo.auto_redirect_alias', '0', $this->storeId());
        $product = $this->makeProduct('ZZ Switch Off Product');
        $old = $this->path($product->getUrl());

        $this->rename($product, 'zz-switch-off-' . uniqid());

        $this->assertNull(FrontRedirect::where('from', $old)->first());
    }

    public function test_creating_or_deleting_content_creates_no_redirect(): void
    {
        $before = FrontRedirect::count();
        $product = $this->makeProduct('ZZ Create Delete Product');
        $product->delete();

        $this->assertSame($before, FrontRedirect::count());
    }

    public function test_categories_brands_and_pages_get_the_same_redirect(): void
    {
        $category = ShopCategory::create(['alias' => 'zz-auto-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0, 'status' => 1, 'sort' => 0, 'store_id' => $this->storeId()]);
        $brand = ShopBrand::create(['name' => 'ZZ Auto Brand', 'alias' => 'zz-auto-brand-' . uniqid(), 'image' => '', 'sort' => 0, 'status' => 1, 'store_id' => $this->storeId()]);
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-auto-page-' . uniqid(), 'status' => 1, 'store_id' => $this->storeId()]);

        foreach ([$category, $brand, $page] as $content) {
            $old = $this->path($content->getUrl());
            $this->rename($content, $content->alias . '-new');
            $this->assertSame($this->path($content->getUrl()), FrontRedirect::where('from', $old)->value('to'), get_class($content));
        }
    }

    public function test_renaming_from_the_product_screen_creates_the_redirect_too(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Screen Rename Product');
        $category = ShopCategory::create(['alias' => 'zz-screen-cat-' . uniqid(), 'status' => 1, 'sort' => 0, 'store_id' => $this->storeId()]);
        $old = $this->path($product->getUrl());
        $newAlias = 'zz-screen-renamed-' . uniqid();

        Livewire::test(ProductManager::class, ['id' => $product->id])
            ->set('form.category', [(string) $category->id])
            ->set('form.alias', $newAlias)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertStringEndsWith($newAlias . '.html', (string) FrontRedirect::where('from', $old)->value('to'));
    }

    public function test_the_redirect_screen_marks_automatic_redirects(): void
    {
        $this->asUser(slugs: ['admin_seo_redirect']);
        $product = $this->makeProduct('ZZ Badge Product');
        $this->rename($product, 'zz-badge-' . uniqid());

        Livewire::test(\GP247\Front\Admin\Livewire\SeoRedirectManager::class)
            ->assertSeeHtml('data-testid="seo-redirect-source-badge"')
            ->assertSeeHtml('data-testid="seo-redirect-auto-switch"');
    }

    public function test_the_switch_on_the_redirect_screen_turns_the_feature_off(): void
    {
        $this->asUser(slugs: ['admin_seo_redirect']);

        Livewire::test(\GP247\Front\Admin\Livewire\SeoRedirectManager::class)
            ->assertSet('autoRedirect', true)
            ->set('autoRedirect', false);

        $this->resetSeoConfigCache();
        $this->assertSame('0', (string) gp247_config('seo.auto_redirect_alias', config('app.storeId'), '1'));
    }

    private function rename($model, string $alias): void
    {
        $model->alias = $alias;
        $model->save();
    }

    private function path(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }
}
