<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminStore;
use GP247\Front\Library\SeoMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * og:image of a page must be the page's own image.
 *
 * WHY these tests render the component instead of calling SeoMeta: from
 * 2026-07-02 until this fix every page shared the store image because
 * MetaHead's constructor parameter was named `$og_image` — Blade only feeds a
 * tag attribute into the constructor when Str::camel(attribute) matches a
 * parameter name, so `:og_image` silently landed in $attributes. SeoMetaTest
 * never caught it because it only exercises SeoMeta::build()
 * (RISK-TECH-blade-component-snake-param).
 *
 * There is no bundled default image any more
 * (GP247/Core/images/org.jpg was removed) — a page with no image of its own on a
 * store without an og_image renders no og:image tag at all. Each test sets the
 * store's og_image explicitly so the result does not depend on the test DB.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-001
 * @aidlc-adr ADR-013
 */
class MetaHeadOgImageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    private const PAGE_IMAGE = 'https://cdn.example.test/zz-og-page-image.jpg';

    private const STORE_IMAGE = 'GP247/zz-og-store-image.jpg';

    /**
     * Reset AdminConfig's static per-store memo to its declared empty array.
     * WHY: an earlier test in this directory (JsonLdTest) resets it to null,
     * which makes every later gp247_config() call fatal; this test must not
     * depend on run order.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(\GP247\Core\Models\AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setStoreOgImage(self::STORE_IMAGE);
    }

    /**
     * Drop the store cache so the next test re-reads the (rolled back) row.
     */
    protected function tearDown(): void
    {
        self::clearAdminStoreCache();
        parent::tearDown();
    }

    public function test_page_image_passed_as_og_image_attribute_becomes_og_image_and_twitter_image(): void
    {
        $html = $this->renderMetaHead('<x-gp247-front::meta-head :og_image="$img" />', ['img' => self::PAGE_IMAGE]);

        $this->assertStringContainsString('<meta property="og:image" content="' . self::PAGE_IMAGE . '"', $html);
        $this->assertStringContainsString('<meta name="twitter:image" content="' . self::PAGE_IMAGE . '"', $html);
    }

    public function test_page_image_passed_as_kebab_case_og_image_attribute_is_used_too(): void
    {
        $html = $this->renderMetaHead('<x-gp247-front::meta-head :og-image="$img" />', ['img' => self::PAGE_IMAGE]);

        $this->assertStringContainsString('<meta property="og:image" content="' . self::PAGE_IMAGE . '"', $html);
    }

    public function test_page_without_image_shares_the_store_og_image(): void
    {
        foreach ([null, ''] as $empty) {
            $html = $this->renderMetaHead('<x-gp247-front::meta-head :og_image="$img" />', ['img' => $empty]);

            $this->assertStringContainsString('<meta property="og:image" content="' . $this->storeOgImage() . '"', $html);
            $this->assertStringNotContainsString('twitter:image', $html);
        }
    }

    public function test_no_image_placeholder_is_treated_as_no_image_and_shares_the_store_og_image(): void
    {
        // gp247_image_get_path() returns this placeholder when a record has no
        // image (or its file is missing); controllers then wrap it in gp247_file().
        foreach (['GP247/Core/images/no-image.jpg', gp247_file('GP247/Core/images/no-image.jpg')] as $placeholder) {
            $html = $this->renderMetaHead('<x-gp247-front::meta-head :og_image="$img" />', ['img' => $placeholder]);

            $this->assertStringContainsString('<meta property="og:image" content="' . $this->storeOgImage() . '"', $html);
            $this->assertStringNotContainsString('no-image.jpg', $html);
            $this->assertStringNotContainsString('twitter:image', $html);
        }
    }

    public function test_page_without_image_on_a_store_without_og_image_has_no_og_image_tag(): void
    {
        foreach ([null, '', '  '] as $storeImage) {
            $this->setStoreOgImage($storeImage);

            foreach ([null, '', 'GP247/Core/images/no-image.jpg'] as $pageImage) {
                $html = $this->renderMetaHead('<x-gp247-front::meta-head :og_image="$img" />', ['img' => $pageImage]);

                $this->assertStringNotContainsString('og:image', $html);
                $this->assertStringNotContainsString('twitter:image', $html);
                $this->assertStringNotContainsString('org.jpg', $html);
                // The other Open Graph tags stay (MC-004).
                $this->assertStringContainsString('<meta property="og:title"', $html);
            }
        }
    }

    public function test_page_image_is_used_even_when_the_store_has_no_og_image(): void
    {
        $this->setStoreOgImage(null);

        $html = $this->renderMetaHead('<x-gp247-front::meta-head :og_image="$img" />', ['img' => self::PAGE_IMAGE]);

        $this->assertStringContainsString('<meta property="og:image" content="' . self::PAGE_IMAGE . '"', $html);
    }

    public function test_seo_meta_og_image_falls_back_to_the_store_image_then_to_nothing(): void
    {
        $this->assertSame(gp247_file(self::STORE_IMAGE), SeoMeta::build(title: 'T', ogImage: null)['og_image']);

        foreach ([null, ''] as $storeImage) {
            $this->setStoreOgImage($storeImage);

            $this->assertSame('', SeoMeta::build(title: 'T', ogImage: null)['og_image']);
            $this->assertSame('', SeoMeta::build(title: 'T', ogImage: '')['og_image']);
        }
    }

    public function test_shop_product_detail_page_shares_the_products_own_image(): void
    {
        $this->setUpFrontStore();
        $product = $this->makeProduct('ZZ OG Image Product', ['image' => self::PAGE_IMAGE]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('<meta property="og:image" content="' . self::PAGE_IMAGE . '"', escape: false);
        $response->assertSee('<meta name="twitter:image" content="' . self::PAGE_IMAGE . '"', escape: false);

        $this->tearDownFrontStore();
    }

    private function renderMetaHead(string $tag, array $data): string
    {
        return Blade::render($tag, $data);
    }

    private function storeOgImage(): string
    {
        return gp247_file(self::STORE_IMAGE);
    }

    /**
     * Set og_image of the current store (rolled back by DatabaseTransactions).
     *
     * @param string|null $image Stored value, null for none.
     */
    private function setStoreOgImage(?string $image): void
    {
        $store = AdminStore::find(config('app.storeId')) ?? AdminStore::first();
        $store->update(['og_image' => $image]);
        self::clearAdminStoreCache();
    }
}
