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
 * Read-only snapshot slug on the shop-admin Order detail
 * (US-SADM-order-attribute-slug): each order line surfaces the variant slug from
 * the 3rd '__' segment of the stored attribute JSON. Legacy 2-segment orders yield
 * an empty slug (no display), so the additive change never affects old data.
 * Gated by `admin_order`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-attribute-slug
 * @aidlc-adr storefront_order-attribute-slug-encoding
 */
class OrderAttributeSlugTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    public function test_items_state_carries_slug_from_third_segment(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $color = ShopAttributeGroup::create(['name' => 'Color', 'type' => 'select']);
        $item = $this->makeOrderItem($order, [
            'attribute' => json_encode([(string) $color->id => 'Red__50__scarlet']),
        ]);

        $row = collect(
            Livewire::test(OrderManager::class, ['id' => (string) $order->id])->get('items')
        )->firstWhere('id', (string) $item->id);

        $this->assertNotNull($row);
        $this->assertSame('scarlet', $row['attributes'][0]['slug']);
    }

    public function test_legacy_two_segment_option_yields_empty_slug(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $color = ShopAttributeGroup::create(['name' => 'Color', 'type' => 'select']);
        $item = $this->makeOrderItem($order, [
            'attribute' => json_encode([(string) $color->id => 'Red__50']),
        ]);

        $row = collect(
            Livewire::test(OrderManager::class, ['id' => (string) $order->id])->get('items')
        )->firstWhere('id', (string) $item->id);

        $this->assertNotNull($row);
        $this->assertSame('', $row['attributes'][0]['slug']);
    }

    public function test_order_detail_renders_slug_testid_when_present(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $color = ShopAttributeGroup::create(['name' => 'Color', 'type' => 'select']);
        $this->makeOrderItem($order, [
            'attribute' => json_encode([(string) $color->id => 'Red__50__scarlet']),
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->assertSee('shop-admin-order-item-attribute-slug', escape: false)
            ->assertSee('scarlet');
    }
}
