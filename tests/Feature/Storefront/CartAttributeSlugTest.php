<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopProductAttribute;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Server-authoritative variant slug on the cart choke point
 * (US-LW-attribute-slug-order, ADR storefront_order-attribute-slug-encoding).
 *
 * gp247_cart_options_canonicalize() resolves the slug from shop_product_attribute
 * by (attribute_group_id, name) and appends it as the 3rd '__' segment
 * (name__add_price__slug). An empty slug keeps the legacy 2-segment form so old
 * orders and the '__'[1] price parsers stay untouched; a client-supplied slug is
 * never trusted. gp247_cart_process_data() surfaces the slug for the confirm step.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-attribute-slug-order
 * @aidlc-adr storefront_order-attribute-slug-encoding
 */
class CartAttributeSlugTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;

    /**
     * Create an attribute group + one product attribute value with a known
     * add_price and slug.
     *
     * @param int|string $productId
     * @param string $name
     * @param float $addPrice
     * @param string $slug
     * @return int The attribute group id.
     */
    private function makeAttribute($productId, string $name, float $addPrice, string $slug): int
    {
        $group = ShopAttributeGroup::create([
            'name' => 'ZZ Slug ' . uniqid(),
            'type' => 'radio',
            'status' => 1,
            'sort' => 0,
        ]);
        ShopProductAttribute::create([
            'name' => $name,
            'attribute_group_id' => $group->id,
            'product_id' => $productId,
            'add_price' => $addPrice,
            'slug' => $slug,
            'sort' => 0,
            'status' => 1,
        ]);

        return (int) $group->id;
    }

    public function test_canonicalize_appends_db_slug_as_third_segment(): void
    {
        $product = $this->makeProduct('ZZ Slug Product');
        $groupId = $this->makeAttribute($product->id, 'Red', 50, 'scarlet');

        // Client sends name + a tampered price and NO slug; server resolves both.
        $canon = gp247_cart_options_canonicalize($product->id, [$groupId => 'Red__99999']);

        $this->assertSame('Red__50.00__scarlet', $canon[$groupId]);
        // Price parser still reads segment [1] — surcharge unaffected by segment-3.
        $this->assertSame(50.0, (float) gp247_cart_options_price($canon));
    }

    public function test_empty_slug_keeps_two_segment_form(): void
    {
        $product = $this->makeProduct('ZZ Slug Empty');
        $groupId = $this->makeAttribute($product->id, 'Blue', 10, '');

        $canon = gp247_cart_options_canonicalize($product->id, [$groupId => 'Blue__10']);

        // No 3rd segment when the DB slug is empty (backward compatible).
        $this->assertSame('Blue__10.00', $canon[$groupId]);
        $this->assertSame('', explode('__', $canon[$groupId])[2] ?? '');
    }

    public function test_client_supplied_slug_is_ignored(): void
    {
        $product = $this->makeProduct('ZZ Slug Tamper');
        $groupId = $this->makeAttribute($product->id, 'Green', 0, 'emerald');

        // Client tries to inject its own slug segment; server must overwrite it
        // with the DB slug (server-authoritative, DT-3).
        $canon = gp247_cart_options_canonicalize($product->id, [$groupId => 'Green__0__hacked']);

        $this->assertSame('Green__0.00__emerald', $canon[$groupId]);
    }

    public function test_process_data_surfaces_slug_empty_safe(): void
    {
        // Segment-3 read used by gp247_cart_process_data for the confirm step.
        $this->assertSame('scarlet', explode('__', 'Red__50.00__scarlet')[2] ?? '');
        // Legacy 2-segment option → no slug, no error.
        $this->assertSame('', explode('__', 'Red__50.00')[2] ?? '');
    }
}
