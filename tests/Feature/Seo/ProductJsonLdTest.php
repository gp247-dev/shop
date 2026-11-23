<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Library\SeoMeta;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopProductPromotion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * JSON-LD Product carries the identifiers Google uses for merchant listings (sku, brand,
 * GTIN, MPN), the promotion end date, and an aggregate rating supplied by whichever
 * plugin registered a resolver.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-005
 * @aidlc-adr ADR-014
 */
class ProductJsonLdTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

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
     * Build a Product JSON-LD document with only the required fields plus $extra.
     *
     * @param array<string, mixed> $extra Optional named arguments.
     * @return array<string, mixed> Decoded JSON-LD.
     */
    private function productJsonLd(array $extra = []): array
    {
        $json = SeoMeta::buildProductJsonLd(...array_merge([
            'name' => 'ZZ Product',
            'url' => 'https://shop.test/zz.html',
            'price' => '10.00',
            'currency' => 'USD',
            'availability' => 'InStock',
            'imageUrl' => null,
            'description' => null,
        ], $extra));

        return json_decode($json, true);
    }

    /**
     * Register rating resolvers for this test only.
     *
     * @param array<int, mixed> $resolvers
     */
    private function useRatingResolvers(array $resolvers): void
    {
        config(['gp247-config.front.seo_product_rating_resolvers' => $resolvers]);
    }

    public function test_sku_brand_gtin_and_mpn_are_included_when_the_product_has_them(): void
    {
        $data = $this->productJsonLd([
            'sku' => 'ZZ-SKU-1',
            'brand' => 'ZZ Brand',
            'gtin12' => '012345678905',
            'gtin13' => '4006381333931',
            'mpn' => 'ZZ-MPN-7',
        ]);

        $this->assertSame('ZZ-SKU-1', $data['sku']);
        $this->assertSame(['@type' => 'Brand', 'name' => 'ZZ Brand'], $data['brand']);
        $this->assertSame('012345678905', $data['gtin12']);
        $this->assertSame('4006381333931', $data['gtin13']);
        $this->assertSame('ZZ-MPN-7', $data['mpn']);
    }

    public function test_missing_or_malformed_codes_are_left_out_instead_of_printed_empty(): void
    {
        $data = $this->productJsonLd([
            'sku' => '',
            'brand' => '  ',
            'gtin12' => 'ABC123',
            'gtin13' => '123',
            'mpn' => null,
        ]);

        foreach (['sku', 'brand', 'gtin12', 'gtin13', 'mpn', 'aggregateRating'] as $key) {
            $this->assertArrayNotHasKey($key, $data, $key . ' must be omitted');
        }
        $this->assertArrayNotHasKey('priceValidUntil', $data['offers']);
    }

    public function test_price_valid_until_is_part_of_the_offer(): void
    {
        $data = $this->productJsonLd(['priceValidUntil' => '2099-12-31']);

        $this->assertSame('2099-12-31', $data['offers']['priceValidUntil']);
    }

    public function test_a_registered_resolver_supplies_the_aggregate_rating(): void
    {
        $this->useRatingResolvers([
            fn (string $productId, $storeId) => $productId === 'zz-p1'
                ? ['ratingValue' => 4.5, 'reviewCount' => 12, 'bestRating' => 5]
                : null,
        ]);

        $this->assertSame(
            ['@type' => 'AggregateRating', 'ratingValue' => 4.5, 'reviewCount' => 12, 'bestRating' => 5],
            SeoMeta::productRating('zz-p1')
        );
        $this->assertNull(SeoMeta::productRating('zz-other'));
    }

    public function test_a_rating_with_no_reviews_or_a_malformed_answer_is_not_used(): void
    {
        $this->useRatingResolvers([
            fn () => ['ratingValue' => 4, 'reviewCount' => 0],
            fn () => ['ratingValue' => 'lots', 'reviewCount' => 3],
            fn () => 'not-an-array',
            'zz_function_that_does_not_exist',
        ]);

        $this->assertNull(SeoMeta::productRating('zz-p1'));
    }

    public function test_a_failing_resolver_is_skipped_and_the_next_one_still_answers(): void
    {
        $this->useRatingResolvers([
            function () {
                throw new \RuntimeException('ZZ plugin table missing');
            },
            fn () => ['ratingValue' => 3, 'reviewCount' => 2],
        ]);

        $this->assertSame(
            ['@type' => 'AggregateRating', 'ratingValue' => 3, 'reviewCount' => 2],
            SeoMeta::productRating('zz-p1')
        );
    }

    public function test_the_product_page_publishes_sku_brand_and_gtin_from_the_product(): void
    {
        $brand = ShopBrand::create([
            'name' => 'ZZ JsonLd Brand',
            'alias' => 'zz-jsonld-brand-' . uniqid(),
            'status' => 1,
            'store_id' => $this->storeId(),
        ]);
        $product = $this->makeProduct('ZZ JsonLd Codes Product', [
            'sku' => 'ZZ-JSONLD-SKU',
            'brand_id' => $brand->id,
            'upc' => '012345678905',
            'ean' => '',
            'jan' => '4901234567894',
            'mpn' => 'ZZ-JSONLD-MPN',
        ]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"sku":"ZZ-JSONLD-SKU"', escape: false);
        $response->assertSee('"brand":{"@type":"Brand","name":"ZZ JsonLd Brand"}', escape: false);
        $response->assertSee('"gtin12":"012345678905"', escape: false);
        // ean is empty, so the JAN code stands in as the GTIN-13.
        $response->assertSee('"gtin13":"4901234567894"', escape: false);
        $response->assertSee('"mpn":"ZZ-JSONLD-MPN"', escape: false);
    }

    public function test_the_product_page_publishes_the_end_date_of_the_running_promotion(): void
    {
        $product = $this->makeProduct('ZZ JsonLd Promo Product', ['price' => 20]);
        ShopProductPromotion::create([
            'product_id' => $product->id,
            'price_promotion' => 15,
            'date_start' => null,
            // WHY 2037: date_end is a TIMESTAMP column (max 2038-01-19).
            'date_end' => '2037-12-31 00:00:00',
            'status_promotion' => 1,
        ]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"priceValidUntil":"2037-12-31"', escape: false);
    }

    public function test_a_product_without_a_promotion_has_no_price_valid_until(): void
    {
        $product = $this->makeProduct('ZZ JsonLd No Promo Product');

        $this->get($product->getUrl())->assertOk()->assertDontSee('priceValidUntil', escape: false);
    }

    public function test_the_product_page_publishes_the_rating_a_plugin_resolves(): void
    {
        $product = $this->makeProduct('ZZ JsonLd Rated Product');
        $this->useRatingResolvers([
            fn (string $productId) => $productId === (string) $product->id
                ? ['ratingValue' => 4.2, 'reviewCount' => 7, 'bestRating' => 5]
                : null,
        ]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"aggregateRating":{"@type":"AggregateRating","ratingValue":4.2,"reviewCount":7,"bestRating":5}', escape: false);
    }

    public function test_a_broken_rating_plugin_does_not_break_the_product_page(): void
    {
        $product = $this->makeProduct('ZZ JsonLd Broken Rating Product');
        $this->useRatingResolvers([
            function () {
                throw new \RuntimeException('ZZ broken plugin');
            },
        ]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"@type":"Product"', escape: false);
        $response->assertDontSee('aggregateRating', escape: false);
    }
}
