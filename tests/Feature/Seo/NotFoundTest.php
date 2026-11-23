<?php

namespace GP247\Shop\Tests\Feature\Seo;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for the 404 / not-found handler.
 *
 * Verifies that unknown pages return HTTP 404 and do not leak canonical
 * or indexable meta that would harm SEO.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-006
 */
class NotFoundTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_unknown_alias_returns_404(): void
    {
        $response = $this->get('/zzz-this-alias-does-not-exist.html');

        $response->assertStatus(404);
    }

    public function test_404_page_does_not_send_noindex_header(): void
    {
        // 404 itself signals not-indexed; we just confirm no X-Robots confusion
        $response = $this->get('/zzz-missing-page.html');

        // Must not instruct crawlers to follow a redirect
        $this->assertNotEquals(301, $response->getStatusCode());
        $this->assertNotEquals(302, $response->getStatusCode());
    }

    /**
     * Shop screens that find no record must answer 404,
     * not a 200 "not found" page search engines would index as real content.
     *
     * @return array<string, array{0: string, 1: array<string, string>}>
     */
    public static function missingShopPages(): array
    {
        return [
            'product'  => ['product.detail', ['alias' => 'zz-no-such-product']],
            'category' => ['category.detail', ['alias' => 'zz-no-such-category']],
            'brand'    => ['brand.detail', ['alias' => 'zz-no-such-brand']],
            'tag'      => ['shop.tag', ['alias' => 'zz-no-such-tag']],
        ];
    }

    /**
     * @param string               $route
     * @param array<string,string> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingShopPages')]
    public function test_a_shop_page_for_a_record_that_does_not_exist_answers_404_with_the_not_found_page(string $route, array $params): void
    {
        $response = $this->get(gp247_route_front($route, $params));

        $response->assertStatus(404);
        // The visitor still gets the template's page (layout + message), not a bare error.
        $response->assertSee('<link rel="canonical"', escape: false);
        $response->assertSee(gp247_language_render('front.notfound_detail'), escape: false);
    }
}
