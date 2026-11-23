<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Server-authoritative pricing for product attributes on add-to-cart
 * (RISK-TECH-attribute-price-tampering) and the widened
 * shop_order_detail.attribute column (RISK-TECH-order-attribute-truncation).
 *
 * The storefront submits form_attr[<group_id>] = "name__add_price"; the add_price
 * part is client-controlled, so `ShopCartController::addToCart()` now rebuilds it
 * from `shop_product_attribute` via `gp247_cart_options_canonicalize()`.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 * @aidlc-adr storefront_attribute-price-integrity
 */
class CartAttributePriceIntegrityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Create an attribute group + one product attribute value with a known add_price.
     *
     * @param int|string $productId
     * @param string $name
     * @param float $addPrice
     * @return int The attribute group id.
     */
    private function makeAttribute($productId, string $name, float $addPrice): int
    {
        $group = ShopAttributeGroup::create([
            'name' => 'ZZ Color ' . uniqid(),
            // WHY: shop_attribute_group.type is NOT NULL with no default since the
            // attribute-group screen migration — the fixture predates that column.
            'type' => 'radio',
            'status' => 1,
            'sort' => 0,
        ]);
        ShopProductAttribute::create([
            'name' => $name,
            'attribute_group_id' => $group->id,
            'product_id' => $productId,
            'add_price' => $addPrice,
            'sort' => 0,
            'status' => 1,
        ]);

        return (int) $group->id;
    }

    public function test_client_add_price_is_overridden_by_db_value(): void
    {
        $product = $this->makeProduct('ZZ Attr Price Product');
        $groupId = $this->makeAttribute($product->id, 'Red', 50);

        // Client tampers the surcharge: submits 99999 instead of the real 50.
        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => 1,
            'form_attr' => [$groupId => 'Red__99999'],
        ]);

        $response->assertSessionHasNoErrors();

        $cart = (new CartService())->instance('default');
        $this->assertSame(1, $cart->count());

        $item = $cart->content()->first();
        // The stored option must carry the DB add_price (50), not the tampered 99999.
        $this->assertSame(50.0, (float) gp247_cart_options_price($item->options));
        $this->assertSame('Red__50.00', $item->options[$groupId]);
    }

    public function test_attribute_not_belonging_to_product_is_rejected(): void
    {
        $product = $this->makeProduct('ZZ Attr Reject Product');
        $groupId = $this->makeAttribute($product->id, 'Red', 50);

        // Valid group id, but a name that does not exist for this product.
        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => 1,
            'form_attr' => [$groupId => 'Purple__0'],
        ]);

        $response->assertSessionHas('error');
        $cart = (new CartService())->instance('default');
        $this->assertSame(0, $cart->count());
    }

    public function test_order_detail_attribute_column_is_text(): void
    {
        $type = Schema::connection(GP247_DB_CONNECTION)
            ->getColumnType(GP247_DB_PREFIX . 'shop_order_detail', 'attribute');

        // varchar(100) truncated long JSON options; text has no practical limit.
        $this->assertSame('text', $type);
    }
}
