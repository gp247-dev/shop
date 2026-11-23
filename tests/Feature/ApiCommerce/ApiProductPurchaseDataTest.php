<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A product in API v2 carries what an app needs to sell it: the attribute groups to
 * choose from with the surcharge the server charges, the image in its sizes and whether
 * it is in stock — never the absolute stock — while every field it had before stays.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-product-purchase-data
 */
#[Group('api-commerce')]
class ApiProductPurchaseDataTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
        $this->setApiSwitch('api_v2_front', true);
    }

    /**
     * @param ShopProduct $product
     * @return array<string, mixed> The product as the public API shows it.
     */
    private function detail(ShopProduct $product): array
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return (array) $this->getJson($this->v2Front() . '/product/detail/' . $product->id)->assertOk()->json('data');
    }

    public function test_the_attribute_groups_come_with_the_surcharge_the_cart_charges(): void
    {
        $product = $this->makeProduct(['price' => 25, 'tax_id' => 0]);
        $group = $this->addAttributeGroup($product, ['Red' => 5, 'Blue' => 8]);

        $attributes = collect($this->detail($product)['attributes'])->keyBy('group_id');

        $this->assertSame((string) $group->id, (string) $attributes[(string) $group->id]['group_id']);
        $this->assertSame($group->name, $attributes[(string) $group->id]['name']);
        $this->assertTrue($attributes[(string) $group->id]['required'], 'the cart needs one option of every group');
        $options = collect($attributes[(string) $group->id]['options'])->keyBy('value');
        $this->assertEquals(5, $options['Red']['add_price']);
        $this->assertEquals(8, $options['Blue']['add_price']);

        $line = $this->cartCall('POST', '/cart/items', null, null, ['product_id' => (string) $product->id, 'qty' => 1, 'options' => [(string) $group->id => 'Red']])
            ->assertStatus(201)->json('data.stores.0.lines.0');
        $this->assertEquals(25 + $options['Red']['add_price'], $line['unit_price'], 'the surcharge shown is the surcharge charged');
    }

    public function test_a_product_without_attributes_has_an_empty_list(): void
    {
        $this->assertSame([], $this->detail($this->makeProduct())['attributes']);
    }

    public function test_the_stock_status_says_in_or_out_of_stock_never_the_stock_itself(): void
    {
        $inStock = $this->detail($this->makeProduct(['stock' => 3]));
        $outOfStock = $this->detail($this->makeProduct(['stock' => 0]));

        $this->assertSame('in_stock', $inStock['stock_status']);
        $this->assertSame('out_of_stock', $outOfStock['stock_status']);
        $this->assertArrayNotHasKey('stock', $inStock, 'the absolute stock stays private');
    }

    public function test_a_shop_that_sells_out_of_stock_or_does_not_track_stock_shows_in_stock(): void
    {
        $product = $this->makeProduct(['stock' => 0]);

        $this->setShopConfig(['product_buy_out_of_stock' => '1']);
        $this->assertSame('in_stock', $this->detail($product)['stock_status']);

        $this->setShopConfig(['product_buy_out_of_stock' => '0', 'product_stock' => '0']);
        $this->assertSame('in_stock', $this->detail($product)['stock_status']);
    }

    public function test_the_image_comes_in_its_original_and_thumbnail_sizes(): void
    {
        $sizes = $this->detail($this->makeProduct(['image' => null]))['image_sizes'];

        $this->assertSame(['original', 'thumb'], array_keys($sizes));
        $this->assertStringStartsWith('http', $sizes['original']);
        $this->assertStringStartsWith('http', $sizes['thumb']);
    }

    public function test_a_shop_that_hides_prices_hides_the_surcharges_too(): void
    {
        $product = $this->makeProduct(['price' => 25]);
        $group = $this->addAttributeGroup($product, ['Red' => 5]);
        $this->setShopConfig(['product_price' => '0']);

        $option = $this->detail($product)['attributes'][0]['options'][0];

        $this->assertSame('Red', $option['value']);
        $this->assertNull($option['add_price']);
    }

    public function test_every_field_the_product_had_before_is_still_there(): void
    {
        $data = $this->detail($this->makeProduct(['price' => 25]));

        foreach (['id', 'sku', 'alias', 'name', 'image', 'images', 'price', 'price_final', 'tax_id', 'kind', 'product_type', 'brand_id',
            'category_ids', 'minimum', 'weight', 'weight_class', 'length', 'width', 'height', 'length_class', 'date_available', 'store_id',
            'created_at', 'updated_at'] as $field) {
            $this->assertArrayHasKey($field, $data, "v2 field {$field} must stay");
        }
        $this->assertEquals(25, $data['price']);
    }

    public function test_the_product_list_carries_the_same_data_without_a_query_per_product(): void
    {
        $product = $this->makeProduct();
        $this->addAttributeGroup($product, ['Red' => 5]);
        $this->addAttributeGroup($this->makeProduct(), ['Blue' => 8]);

        $count = function (int $size): int {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            DB::connection(GP247_DB_CONNECTION)->flushQueryLog();
            DB::connection(GP247_DB_CONNECTION)->enableQueryLog();
            $rows = $this->getJson($this->v2Front() . '/product/list?sort=-created_at&page[size]=' . $size)->assertOk()->json('data');
            $queries = count(DB::connection(GP247_DB_CONNECTION)->getQueryLog());
            DB::connection(GP247_DB_CONNECTION)->disableQueryLog();
            $this->assertArrayHasKey('attributes', $rows[0]);
            $this->assertArrayHasKey('stock_status', $rows[0]);

            return $queries;
        };

        $count(2); // WHY: the first call also reads the shop settings once; compare warm calls only.
        $this->assertSame($count(2), $count(10), 'attributes are eager loaded, not read per product');
    }
}
