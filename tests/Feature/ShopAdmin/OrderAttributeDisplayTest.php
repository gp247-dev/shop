<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopAttributeGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Feature test for read-only attribute display on the shop-admin Order detail
 * (US-SADM-order-attribute-display): each order line shows its selected product
 * attributes — group name + option name + surcharge — reusing
 * gp247_render_option_price so the format matches cart/storefront (the "(+price)"
 * shows only when add_price > 0). Lines with no attribute render nothing extra.
 * Display only: no schema/pricing/stock change. Gated by `admin_order`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-attribute-display
 * @aidlc-adr shop-admin_order-attribute-display
 */
class OrderAttributeDisplayTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    /**
     * A line with a surcharged option and a zero-price option: the group names
     * and option names appear; the surcharge markup shows only for add_price > 0.
     */
    public function test_order_detail_shows_attribute_name_and_surcharge(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $color = ShopAttributeGroup::create(['name' => 'Color', 'type' => 'select']);
        $size = ShopAttributeGroup::create(['name' => 'Size', 'type' => 'select']);
        $this->makeOrderItem($order, [
            'attribute' => json_encode([
                (string) $color->id => 'Red__50',
                (string) $size->id => 'XL__0',
            ]),
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->assertSee('shop-admin-order-item-attribute', escape: false)
            ->assertSee('Color')
            ->assertSee('Red')
            ->assertSee('Size')
            ->assertSee('XL')
            // WHY: gp247_render_option_price wraps a present surcharge in
            // <span class="option_price">(+...)</span>; assert the markup renders.
            ->assertSee('option_price', escape: false);
    }

    /**
     * refreshItems() exposes a display-ready `attributes` array per item: group
     * label + rendered value; surcharge only formatted when add_price > 0.
     */
    public function test_items_state_carries_rendered_attributes(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $color = ShopAttributeGroup::create(['name' => 'Color', 'type' => 'select']);
        $size = ShopAttributeGroup::create(['name' => 'Size', 'type' => 'select']);
        $item = $this->makeOrderItem($order, [
            'attribute' => json_encode([
                (string) $color->id => 'Red__50',
                (string) $size->id => 'XL__0',
            ]),
        ]);

        $items = Livewire::test(OrderManager::class, ['id' => (string) $order->id])->get('items');
        $row = collect($items)->firstWhere('id', (string) $item->id);

        $this->assertNotNull($row);
        $this->assertCount(2, $row['attributes']);
        $this->assertSame('Color', $row['attributes'][0]['name']);
        $this->assertStringContainsString('Red', $row['attributes'][0]['value']);
        $this->assertStringContainsString('(+', $row['attributes'][0]['value']);
        $this->assertSame('Size', $row['attributes'][1]['name']);
        $this->assertStringContainsString('XL', $row['attributes'][1]['value']);
        // add_price 0 => no surcharge fragment.
        $this->assertStringNotContainsString('(+', $row['attributes'][1]['value']);
    }

    /**
     * A line with no attribute ('[]') exposes no attributes and renders no
     * attribute block — additive change must not affect plain lines.
     */
    public function test_line_without_attribute_renders_nothing_extra(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $item = $this->makeOrderItem($order, ['attribute' => '[]']);

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);
        $component->assertDontSee('shop-admin-order-item-attribute', escape: false);

        $row = collect($component->get('items'))->firstWhere('id', (string) $item->id);
        $this->assertNotNull($row);
        $this->assertSame([], $row['attributes']);
    }

    /**
     * Malformed / null attribute JSON degrades to an empty attribute list rather
     * than throwing (guard in renderItemAttributes).
     */
    public function test_malformed_attribute_is_ignored(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $item = $this->makeOrderItem($order, ['attribute' => 'not-a-json']);

        $row = collect(
            Livewire::test(OrderManager::class, ['id' => (string) $order->id])->get('items')
        )->firstWhere('id', (string) $item->id);

        $this->assertNotNull($row);
        $this->assertSame([], $row['attributes']);
    }

    /**
     * An option keyed by a deleted attribute group falls back to "#<id>" instead
     * of blank, so a stale reference degrades gracefully.
     */
    public function test_deleted_group_falls_back_to_hash_id(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $item = $this->makeOrderItem($order, [
            'attribute' => json_encode(['999999' => 'Ghost__0']),
        ]);

        $row = collect(
            Livewire::test(OrderManager::class, ['id' => (string) $order->id])->get('items')
        )->firstWhere('id', (string) $item->id);

        $this->assertNotNull($row);
        $this->assertSame('#999999', $row['attributes'][0]['name']);
        $this->assertStringContainsString('Ghost', $row['attributes'][0]['value']);
    }
}
