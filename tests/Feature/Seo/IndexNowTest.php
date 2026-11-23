<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Admin\Livewire\SeoMetaSettings;
use GP247\Front\Library\IndexNow;
use GP247\Front\Library\SeoContentMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\ConfiguresSeoStore;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * With IndexNow switched on, changed content is reported to api.indexnow.org after the
 * response, never slowing or breaking the save (SEO phase 2).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-indexnow
 * @aidlc-adr ADR-seo-url-lifecycle
 */
class IndexNowTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresSeoStore;

    private const KEY = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSeoConfigCache();
        Http::fake(['api.indexnow.org/*' => Http::response('', 202)]);
        IndexNow::reset();
    }

    public function test_nothing_is_sent_while_indexnow_is_off(): void
    {
        $product = $this->makeProduct('ZZ IndexNow Off');
        $product->alias = 'zz-indexnow-off-' . uniqid();
        $product->save();
        IndexNow::flush();

        Http::assertNothingSent();
    }

    public function test_a_saved_product_is_reported_once_with_the_store_host_and_key(): void
    {
        $this->turnOn();
        $product = $this->makeProduct('ZZ IndexNow Saved');
        $product->stock = 50;
        $product->save();
        IndexNow::flush();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($product) {
            $data = $request->data();

            return $request->url() === 'https://api.indexnow.org/indexnow'
                && $data['key'] === self::KEY
                && $data['host'] === parse_url(IndexNow::baseUrl($this->storeId()), PHP_URL_HOST)
                && in_array(IndexNow::baseUrl($this->storeId()) . parse_url($product->getUrl(), PHP_URL_PATH), $data['urlList'], true);
        });
    }

    public function test_an_alias_change_reports_the_old_and_the_new_address(): void
    {
        $this->turnOn();
        $product = $this->makeProduct('ZZ IndexNow Rename');
        IndexNow::reset();
        $old = parse_url($product->getUrl(), PHP_URL_PATH);
        $product->alias = 'zz-indexnow-new-' . uniqid();
        $product->save();
        $new = parse_url($product->getUrl(), PHP_URL_PATH);
        IndexNow::flush();

        Http::assertSent(function (Request $request) use ($old, $new) {
            $list = $request->data()['urlList'];
            $base = IndexNow::baseUrl($this->storeId());

            return in_array($base . $old, $list, true) && in_array($base . $new, $list, true);
        });
    }

    public function test_noindex_and_switched_off_content_is_not_reported_when_saved(): void
    {
        $this->turnOn();
        $hidden = $this->makeProduct('ZZ IndexNow Hidden');
        SeoContentMeta::save('product', $hidden->id, ['noindex' => true]);
        $off = $this->makeProduct('ZZ IndexNow Off Status', ['status' => 0]);
        IndexNow::reset();
        $hidden->stock = 7;
        $hidden->save();
        $off->stock = 7;
        $off->save();
        IndexNow::flush();

        Http::assertNothingSent();
    }

    public function test_a_failing_endpoint_never_breaks_the_save(): void
    {
        Http::fake(['api.indexnow.org/*' => Http::response('nope', 500)]);
        $this->turnOn();
        $product = $this->makeProduct('ZZ IndexNow Failing');
        $product->stock = 3;
        $product->save();

        IndexNow::flush();

        $this->assertSame(3.0, (float) $product->fresh()->stock);
    }

    public function test_the_key_file_answers_only_with_the_right_key_while_on(): void
    {
        $this->setUpFrontStore();
        $this->get('/' . self::KEY . '.txt')->assertNotFound();

        $this->turnOn();
        $response = $this->get('/' . self::KEY . '.txt');
        $response->assertOk();
        $this->assertSame(self::KEY, trim($response->getContent()));
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $this->get('/ffffffffffffffffffffffffffffffff.txt')->assertNotFound();
        $this->tearDownFrontStore();
    }

    public function test_switching_on_from_the_screen_creates_a_random_key(): void
    {
        $this->asUser(slugs: ['admin_seo_meta']);

        $component = Livewire::test(SeoMetaSettings::class)->set('indexNowEnabled', true);

        $this->resetSeoConfigCache();
        $key = (string) gp247_config('seo.indexnow_key', config('app.storeId'), '');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $component->assertSet('indexNowKey', $key)->assertSeeHtml('data-testid="seo-indexnow-key"');
    }

    private function turnOn(): void
    {
        $this->setSeoConfig('seo.indexnow_enabled', '1', $this->storeId());
        $this->setSeoConfig('seo.indexnow_key', self::KEY, $this->storeId());
        $this->setSeoConfig('seo.indexnow_enabled', '1');
        $this->setSeoConfig('seo.indexnow_key', self::KEY);
    }
}
