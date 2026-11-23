<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An app that reloads a v2 screen whose data did not change gets 304 Not Modified with
 * an empty body: every successful v2 read carries a weak ETag hashed from the body, so
 * any change the caller would see — even one that leaves updated_at alone — gives a new
 * ETag. Errors, writes and v1 carry none, and Last-Modified is never used.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-conditional-get
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiConditionalGetTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

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

    public function test_reloading_a_product_that_did_not_change_answers_304_with_an_empty_body(): void
    {
        $product = $this->makeProduct();
        $uri = $this->v2Front() . '/product/detail/' . $product->id;

        $first = $this->getWith($uri)->assertOk();
        $etag = (string) $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{64}"$/', $etag);

        $again = $this->getWith($uri, ['If-None-Match' => $etag]);
        $again->assertStatus(304);
        $this->assertSame('', (string) $again->getContent());
        $this->assertSame($etag, $again->headers->get('ETag'));
        $this->assertNotEmpty($again->headers->get('X-Request-Id'));
    }

    public function test_a_list_of_etags_or_a_star_also_counts_as_unchanged(): void
    {
        $product = $this->makeProduct();
        $uri = $this->v2Front() . '/product/detail/' . $product->id;
        $etag = (string) $this->getWith($uri)->headers->get('ETag');

        $this->getWith($uri, ['If-None-Match' => 'W/"zz-other", ' . $etag])->assertStatus(304);
        $this->getWith($uri, ['If-None-Match' => '*'])->assertStatus(304);
        $this->getWith($uri, ['If-None-Match' => 'W/"zz-other"'])->assertOk();
    }

    public function test_a_change_that_leaves_updated_at_alone_still_gives_a_new_etag(): void
    {
        $product = $this->makeProduct(['price' => 100]);
        $uri = $this->v2Front() . '/product/detail/' . $product->id;
        $etag = (string) $this->getWith($uri)->headers->get('ETag');

        // WHY the query builder: it changes the price without touching updated_at — exactly
        // the kind of change (promotion window, variant stock) a Last-Modified check misses.
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'shop_product')->where('id', $product->id)->update(['price' => 150]);

        $fresh = $this->getWith($uri, ['If-None-Match' => $etag])->assertOk();
        $this->assertNotSame($etag, $fresh->headers->get('ETag'));
        $this->assertEquals(150.0, (float) $fresh->json('data.price'));
    }

    public function test_lists_and_signed_in_reads_get_an_etag_too(): void
    {
        $list = $this->v2Front() . '/product/list';
        $etag = (string) $this->getWith($list)->assertOk()->headers->get('ETag');
        $this->getWith($list, ['If-None-Match' => $etag])->assertStatus(304);

        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $profile = $this->v2Front() . '/member/profile';
        $mine = (string) $this->getWith($profile, ['Authorization' => 'Bearer ' . $token])->assertOk()->headers->get('ETag');
        $this->assertNotSame('', $mine);
        $this->getWith($profile, ['Authorization' => 'Bearer ' . $token, 'If-None-Match' => $mine])->assertStatus(304);
    }

    public function test_errors_writes_and_v1_carry_no_etag_and_last_modified_is_never_used(): void
    {
        $this->getWith($this->v2Front() . '/product/detail/zz-missing-' . uniqid())->assertNotFound()->assertHeaderMissing('ETag');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson($this->v2Front() . '/login', [])->assertHeaderMissing('ETag');

        $this->getWith($this->frontApi() . '/product/list')->assertOk()->assertHeaderMissing('ETag');

        $product = $this->makeProduct();
        $uri = $this->v2Front() . '/product/detail/' . $product->id;
        $this->getWith($uri)->assertHeaderMissing('Last-Modified');
        $this->getWith($uri, ['If-Modified-Since' => gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT'])->assertOk();
    }
}
