<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Library\SeoMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Packages and plugins add schema.org fields to the one Product / Offer /
 * Organization block the base prints, instead of printing a second block of the
 * same type.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-jsonld-extenders
 * @aidlc-adr ADR-014
 */
class SeoJsonLdExtenderTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    /** @var array<int, array> Contexts the extenders were called with. */
    public static array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
        self::$calls = [];
        config(['gp247-config.front.seo_jsonld_extenders' => []]);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * @param array<string, array<int, callable>> $extenders
     */
    private function useExtenders(array $extenders): void
    {
        config(['gp247-config.front.seo_jsonld_extenders' => $extenders]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productJsonLd(): array
    {
        return json_decode(SeoMeta::buildProductJsonLd(
            name: 'ZZ Product',
            url: 'https://shop.test/zz.html',
            price: '10.00',
            currency: 'USD',
            availability: 'InStock',
            imageUrl: null,
            description: null,
        ), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationJsonLd(): array
    {
        return json_decode(SeoMeta::buildOrganizationJsonLd('ZZ Shop', 'https://shop.test', null), true);
    }

    public function test_a_plugin_adds_a_return_policy_to_the_organization_block(): void
    {
        $this->useExtenders(['Organization' => [static function (array $context) {
            SeoJsonLdExtenderTest::$calls[] = $context;

            return ['hasMerchantReturnPolicy' => ['@type' => 'MerchantReturnPolicy', 'merchantReturnDays' => 30]];
        }]]);

        $data = $this->organizationJsonLd();

        $this->assertSame('ZZ Shop', $data['name']);
        $this->assertSame(30, $data['hasMerchantReturnPolicy']['merchantReturnDays']);
        $this->assertSame('https://shop.test', self::$calls[0]['url']);
        $this->assertArrayHasKey('store_id', self::$calls[0]);
    }

    public function test_a_plugin_adds_shipping_details_to_the_offer_of_the_product_being_shown(): void
    {
        SeoContentMeta::subject('product', 'zz-product-1');
        $this->useExtenders([
            'Offer' => [static function (array $context) {
                SeoJsonLdExtenderTest::$calls[] = $context;

                return ['shippingDetails' => ['@type' => 'OfferShippingDetails']];
            }],
            'Product' => [static fn (array $context) => ['color' => 'red']],
        ]);

        $data = $this->productJsonLd();

        $this->assertSame('OfferShippingDetails', $data['offers']['shippingDetails']['@type']);
        $this->assertSame('red', $data['color']);
        $this->assertSame('zz-product-1', self::$calls[0]['product_id']);
        $this->assertSame('10.00', self::$calls[0]['price']);
        $this->assertSame('USD', self::$calls[0]['currency']);
    }

    public function test_fields_the_base_already_prints_are_never_overwritten_and_the_first_extender_wins(): void
    {
        SeoContentMeta::subject('product', 'zz-product-2');
        $this->useExtenders([
            'Product' => [
                static fn () => ['name' => 'Hijacked', 'color' => 'blue'],
                static fn () => ['color' => 'green'],
            ],
            'Offer' => [static fn () => ['price' => '0.01']],
            'Organization' => [static fn () => ['url' => 'https://evil.test']],
        ]);

        $product = $this->productJsonLd();
        $organization = $this->organizationJsonLd();

        $this->assertSame('ZZ Product', $product['name']);
        $this->assertSame('blue', $product['color']);
        $this->assertSame('10.00', $product['offers']['price']);
        $this->assertSame('https://shop.test', $organization['url']);
    }

    public function test_a_failing_or_malformed_extender_is_skipped_and_the_page_keeps_the_base_data(): void
    {
        SeoContentMeta::subject('product', 'zz-product-3');
        $this->useExtenders([
            'Product' => [
                static function () {
                    throw new \RuntimeException('zz broken plugin');
                },
                static fn () => 'not an array',
                static fn () => ['zz_object' => new \stdClass(), 'zz_ok' => 'yes'],
                'not-callable',
            ],
        ]);

        $data = $this->productJsonLd();

        $this->assertSame('ZZ Product', $data['name']);
        $this->assertSame('yes', $data['zz_ok']);
        $this->assertArrayNotHasKey('zz_object', $data);
    }

    public function test_without_extenders_the_blocks_are_exactly_as_before(): void
    {
        SeoContentMeta::subject('product', 'zz-product-4');

        $this->assertSame(
            ['@context', '@type', 'name', 'url', 'description', 'offers'],
            array_keys($this->productJsonLd())
        );
        $this->assertSame(['@context', '@type', 'name', 'url'], array_keys($this->organizationJsonLd()));
    }

    public function test_product_and_offer_extenders_are_not_asked_when_no_product_is_being_shown(): void
    {
        $this->useExtenders([
            'Product' => [static function () {
                SeoJsonLdExtenderTest::$calls[] = 'product';

                return ['color' => 'red'];
            }],
        ]);

        $data = $this->productJsonLd();

        $this->assertArrayNotHasKey('color', $data);
        $this->assertSame([], self::$calls);
    }

    public function test_extender_values_cannot_break_out_of_the_script_tag(): void
    {
        $this->useExtenders(['Organization' => [static fn () => ['slogan' => '</script><script>alert(1)</script>']]]);

        $json = SeoMeta::buildOrganizationJsonLd('ZZ Shop', 'https://shop.test', null);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertSame('</script><script>alert(1)</script>', json_decode($json, true)['slogan']);
    }

    public function test_the_product_page_shows_fields_added_by_a_plugin(): void
    {
        $product = $this->makeProduct('ZZ Extender Product');
        $this->useExtenders(['Offer' => [static fn (array $context) => ['zzShippingNote' => 'ships-' . $context['product_id']]]]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('ships-' . $product->id, false);
    }
}
