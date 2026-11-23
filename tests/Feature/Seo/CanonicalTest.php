<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Library\SeoMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\TestCase;

/**
 * Canonical points at the page itself and keeps `page` > 1; `og:url` is the canonical.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-002, US-SEO-001
 * @aidlc-adr ADR-seo-indexing-signals
 */
class CanonicalTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;

    /**
     * Activate the store and reset AdminConfig's per-store memo.
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

    /**
     * Point the container's request at a URL without going through front middleware.
     *
     * @param string $url Absolute URL.
     */
    private function useRequest(string $url): void
    {
        $request = Request::create($url, 'GET');
        app()->instance('request', $request);
        request()->setRouteResolver(fn () => null);
    }

    public function test_the_second_page_of_a_listing_is_its_own_canonical(): void
    {
        $this->useRequest('http://localhost/product?page=2');

        $this->assertSame('http://localhost/product?page=2', SeoMeta::canonicalUrl());
    }

    public function test_the_first_page_and_other_parameters_are_dropped_from_the_canonical(): void
    {
        $this->useRequest('http://localhost/product?page=1&utm_source=zz');
        $this->assertSame('http://localhost/product', SeoMeta::canonicalUrl());

        $this->useRequest('http://localhost/product?brand=zz&page=3');
        $this->assertSame('http://localhost/product?page=3', SeoMeta::canonicalUrl());
    }

    public function test_a_page_parameter_that_is_not_a_positive_number_is_ignored(): void
    {
        $this->useRequest('http://localhost/product?page=abc');
        $this->assertSame('http://localhost/product', SeoMeta::canonicalUrl());

        $this->useRequest('http://localhost/product?page[]=2');
        $this->assertSame('http://localhost/product', SeoMeta::canonicalUrl());
    }

    public function test_og_url_is_the_canonical_on_a_real_listing_page(): void
    {
        $base = gp247_route_front('product.all');

        $response = $this->get($base . '?page=2&filter_sort=price_asc&utm_source=zz');

        $response->assertOk();
        $response->assertSee('<link rel="canonical" href="' . $base . '?page=2" />', escape: false);
        $response->assertSee('<meta property="og:url" content="' . $base . '?page=2" />', escape: false);
        $response->assertDontSee('utm_source', escape: false);
    }
}
