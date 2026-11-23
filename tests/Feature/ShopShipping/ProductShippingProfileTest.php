<?php

/**
 * @aidlc-story US-shop-shipping-product-profile
 */

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductBuild;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\ShippingItem;
use GP247\Shop\Shipping\RateRequestFactory;
use GP247\Shop\Shipping\ShipmentService;
use GP247\Shop\Shipping\ShippingItemFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * Every carrier receives the same, complete shipping profile of a product — grams and
 * centimetres whatever unit the product was entered in, its codes, variant, brand and
 * category — whether the parcel is being quoted at checkout or booked from the order;
 * digital goods are never shipped and a bundle weighs what its parts weigh.
 */
#[Group('shop-shipping')]
class ProductShippingProfileTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    protected function tearDown(): void
    {
        config(['gp247-config.shop.shipping_item_resolvers' => []]);
        parent::tearDown();
    }

    public function test_a_product_is_described_in_grams_and_centimetres_with_its_codes_variant_brand_and_category(): void
    {
        $brand = ShopBrand::create(['id' => gp247_generate_id(), 'name' => 'ZZ Brand', 'alias' => 'zz-brand-' . uniqid(), 'status' => 1]);
        $product = $this->product(['weight' => 1.2, 'weight_class' => 'kg', 'length' => 300, 'width' => 200, 'height' => 50, 'length_class' => 'mm',
            'upc' => '012345678905', 'ean' => '4006381333931', 'mpn' => 'MPN-1', 'brand_id' => $brand->id, 'image' => 'data/product/a.jpg']);
        $group = ShopAttributeGroup::create(['name' => 'Màu', 'status' => 1, 'sort' => 0, 'type' => 'radio']);

        $item = ShippingItemFactory::fromProduct($product, 2, [$group->id => 'Xanh__10'], new Money('100', 'USD'), '1');

        $this->assertSame(1200, $item->weightGrams);
        $this->assertSame([30, 20, 5], [$item->lengthCm, $item->widthCm, $item->heightCm]);
        $this->assertSame((string) $product->id, $item->productId);
        $this->assertSame('4006381333931', $item->barcode, 'EAN first');
        $this->assertSame('MPN-1', $item->mpn);
        $this->assertSame('ZZ Brand', $item->brand);
        $this->assertSame('Màu: Xanh', $item->variant);
        $this->assertStringContainsString('a.jpg', $item->imageUrl);
        $this->assertSame(2, $item->qty);
    }

    public function test_digital_goods_are_not_shipped(): void
    {
        foreach (['download', 'digital'] as $type) {
            $this->assertNull(ShippingItemFactory::fromProduct($this->product(['product_type' => $type, 'weight' => 1]), 1, [], new Money('1', 'USD'), '1'), $type);
        }
    }

    public function test_a_bundle_without_its_own_weight_weighs_what_its_parts_weigh(): void
    {
        $partA = $this->product(['weight' => 300, 'weight_class' => 'g']);
        $partB = $this->product(['weight' => 0.5, 'weight_class' => 'kg']);
        $bundle = $this->product(['kind' => 1, 'weight' => 0]);
        ShopProductBuild::insert([
            ['build_id' => $bundle->id, 'product_id' => $partA->id, 'quantity' => 2],
            ['build_id' => $bundle->id, 'product_id' => $partB->id, 'quantity' => 1],
        ]);

        $item = ShippingItemFactory::fromProduct($bundle, 1, [], new Money('1', 'USD'), '1');

        $this->assertSame(1100, $item->weightGrams);
    }

    public function test_a_plugin_can_enrich_the_item_and_a_broken_resolver_is_ignored(): void
    {
        config(['gp247-config.shop.shipping_item_resolvers' => [
            static fn (ShippingItem $item, ShopProduct $p): ShippingItem => $item->withMeta(['fragile' => true]),
            static fn () => throw new \RuntimeException('boom'),
            static fn () => 'not an item',
        ]]);

        $item = ShippingItemFactory::fromProduct($this->product(['weight' => 1]), 1, [], new Money('1', 'USD'), '1');

        $this->assertSame(['fragile' => true], $item->meta);
    }

    public function test_checkout_quote_and_shipment_booking_describe_the_same_product_the_same_way(): void
    {
        $product = $this->product(['weight' => 250, 'weight_class' => 'g', 'length' => 20, 'width' => 15, 'height' => 4, 'length_class' => 'cm']);
        $digital = $this->product(['product_type' => 'download', 'weight' => 999]);
        $quote = RateRequestFactory::fromCheckout(['city' => 'Hà Nội'], [
            ['rowId' => 'r1', 'id' => $product->id, 'qty' => 3, 'name' => 'P', 'options' => [], 'storeId' => '1'],
            ['rowId' => 'r2', 'id' => $digital->id, 'qty' => 1, 'name' => 'D', 'options' => [], 'storeId' => '1'],
        ], '1');

        $order = $this->makeOrder(['city' => 'Hà Nội', 'phone' => '0900', 'address1' => '1 A', 'country' => 'VN', 'shipping_method' => 'FakeCarrier']);
        ShopOrderDetail::create(['id' => gp247_generate_id(), 'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'P', 'sku' => $product->sku, 'price' => 10, 'qty' => 3, 'store_id' => '1', 'total_price' => 30, 'currency' => 'USD', 'exchange_rate' => 1]);
        $carrier = FakeCarrier::withServices(['std' => 1], 'FakeCarrier', 'USD');
        config(['gp247-config.shop.shipping_providers.FakeCarrier' => ['driver' => FakeCarrier::class, 'capabilities' => ['rates', 'shipments']]]);
        FakeShippingRegistry::bind(['FakeCarrier' => $carrier]);
        ShipmentService::instance()->create($order->fresh(), ShipmentService::instance()->defaults($order->fresh()) + ['service_code' => 'std', 'ward' => 'P1'], '0');

        $this->assertCount(1, $quote->items, 'the download product is not part of the parcel');
        $booked = $carrier->booked[0]->items[0];
        foreach (['weightGrams', 'lengthCm', 'widthCm', 'heightCm', 'qty', 'productId'] as $field) {
            $this->assertSame($quote->items[0]->{$field}, $booked->{$field}, $field);
        }
    }

    public function test_the_parcel_the_admin_measured_is_passed_to_the_carrier(): void
    {
        $order = $this->makeOrder(['city' => 'Hà Nội', 'phone' => '0900', 'address1' => '1 A', 'country' => 'VN', 'shipping_method' => 'FakeCarrier']);
        $carrier = FakeCarrier::withServices(['std' => 1], 'FakeCarrier', 'USD');
        config(['gp247-config.shop.shipping_providers.FakeCarrier' => ['driver' => FakeCarrier::class, 'capabilities' => ['rates', 'shipments']]]);
        FakeShippingRegistry::bind(['FakeCarrier' => $carrier]);

        ShipmentService::instance()->create($order, array_merge(ShipmentService::instance()->defaults($order), [
            'service_code' => 'std', 'ward' => 'P1', 'weight_grams' => 1800, 'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 20,
        ]), '0');

        $parcel = $carrier->booked[0]->parcel;
        $this->assertSame([1800, 40, 30, 20], [$parcel->weightGrams, $parcel->lengthCm, $parcel->widthCm, $parcel->heightCm]);
    }

    /**
     * @param array<string, mixed> $attributes Product columns.
     * @return ShopProduct
     */
    private function product(array $attributes): ShopProduct
    {
        $id = gp247_generate_id();
        $product = ShopProduct::create(array_merge([
            'id' => $id, 'sku' => 'ZZ-SHIP-' . substr($id, 0, 8), 'alias' => 'zz-ship-' . $id, 'price' => 10, 'status' => 1,
            'product_type' => 'physical', 'kind' => 0, 'store_id' => '1', 'weight' => 0, 'weight_class' => 'g',
        ], $attributes));
        ShopProductDescription::create(['product_id' => $id, 'lang' => gp247_get_locale(), 'name' => 'ZZ product']);

        return $product->fresh();
    }
}
