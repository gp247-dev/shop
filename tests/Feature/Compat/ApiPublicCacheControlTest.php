<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Public catalog and content reads let browsers and a CDN keep a copy; anything that can
 * hold one person's data never does. Public caching is opted into route by route
 * (catalog, banners, pages) — never inferred from "no token", because a guest cart is
 * read without one.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-public-cache-control
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiPublicCacheControlTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    /**
     * Routes allowed to carry api.cache.public: GET list/detail of the public catalog and content.
     */
    private const PUBLIC_ROUTES = '#^api/v2/front/(product|category|brand|banner|page)/(list|detail/\{id\})$#';

    /**
     * GET with extra headers, forgetting earlier headers and cached guard users.
     *
     * @param string               $uri
     * @param array<string,string> $headers
     * @return TestResponse
     */
    private function getWith(string $uri, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        return $this->withHeaders($headers)->getJson($uri);
    }

    public function test_a_public_catalog_read_without_a_token_may_be_cached_publicly_and_revalidated(): void
    {
        $response = $this->getWith($this->v2Front() . '/product/list')->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=0', $cache);
        $this->assertStringContainsString('Authorization', (string) $response->headers->get('Vary'));
    }

    public function test_the_max_age_follows_the_site_setting_and_a_bad_value_means_zero(): void
    {
        config(['gp247-config.api.cache.public_max_age' => 120]);
        $this->assertStringContainsString('max-age=120', (string) $this->getWith($this->v2Front() . '/banner/list')->assertOk()->headers->get('Cache-Control'));

        config(['gp247-config.api.cache.public_max_age' => -5]);
        $this->assertStringContainsString('max-age=0', (string) $this->getWith($this->v2Front() . '/category/list')->assertOk()->headers->get('Cache-Control'));
    }

    public function test_the_same_route_read_with_a_token_is_private_and_not_stored(): void
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $cache = (string) $this->getWith($this->v2Front() . '/product/list', ['Authorization' => 'Bearer ' . $token])->assertOk()->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cache);
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('public', $cache);
    }

    public function test_nothing_is_public_while_the_site_requires_an_api_connection_key(): void
    {
        $connection = \GP247\Core\Models\AdminApiConnection::create([
            'description' => 'ZZ cache probe', 'apiconnection' => 'zzcache' . uniqid(), 'apikey' => $key = 'zz-cache-key-' . str_repeat('k', 32), 'status' => 1,
        ]);
        $this->setStoreConfig(['api_connection_required' => '1']);

        $response = $this->getWith($this->v2Front() . '/product/list', ['apiconnection' => $connection->apiconnection, 'apikey' => $key])->assertOk();

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_errors_and_a_guest_cart_are_never_public(): void
    {
        $missing = $this->getWith($this->v2Front() . '/product/detail/zz-missing-' . uniqid())->assertNotFound();
        $this->assertStringNotContainsString('public', (string) $missing->headers->get('Cache-Control'));

        $cart = $this->getWith($this->v2Front() . '/cart');
        $this->assertStringNotContainsString('public', (string) $cart->headers->get('Cache-Control'));
    }

    public function test_only_the_public_catalog_and_content_routes_opt_into_public_caching(): void
    {
        $opted = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('api.cache.public', $route->gatherMiddleware(), true)) {
                $opted[] = $route->uri();
                $this->assertMatchesRegularExpression(self::PUBLIC_ROUTES, $route->uri(), 'Route must not be publicly cacheable: ' . $route->uri());
                $this->assertSame(['GET', 'HEAD'], $route->methods(), 'Only reads may be publicly cacheable: ' . $route->uri());
            }
        }

        foreach (['product', 'category', 'brand', 'banner', 'page'] as $resource) {
            $this->assertContains('api/v2/front/' . $resource . '/list', $opted);
            $this->assertContains('api/v2/front/' . $resource . '/detail/{id}', $opted);
        }
    }
}
