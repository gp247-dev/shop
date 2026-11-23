<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Attribute selection when adding/editing an order line on the shop-admin screens
 * (US-SADM-order-item-attribute-select, ADR shop-admin_order-item-attribute-select):
 * mandatory selection when the product has attributes, server-authoritative
 * add_price (rebuilt from the DB), canonical JSON persisted to
 * shop_order_detail.attribute — on both the Livewire Order-detail screen and the
 * Alpine Create-order screen.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-item-attribute-select
 * @aidlc-adr shop-admin_order-item-attribute-select
 */
class OrderItemAttributeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use ConfiguresQtyDecimal;
    // WHY: both order + product fixture traits declare storeId()/makeProduct();
    // pick the product trait's makeProduct (name + overrides signature, attaches
    // store + descriptions) and either storeId (identical root-store value).
    use MakesShopOrders, MakesShopProducts {
        MakesShopProducts::storeId insteadof MakesShopOrders;
        MakesShopProducts::makeProduct insteadof MakesShopOrders;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
    }

    /**
     * Create an attribute group + one option value with a known add_price for a product.
     *
     * @param int|string $productId
     * @param string $name
     * @param float $addPrice
     * @return int The attribute group id.
     */
    private function makeAttribute($productId, string $name, float $addPrice): int
    {
        $group = ShopAttributeGroup::create([
            'name' => 'ZZ Grp ' . uniqid(),
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

    // -------------------------------------------------------------------------
    // Màn A — Order detail (Livewire)
    // -------------------------------------------------------------------------

    public function test_select_product_preselects_first_option_and_suggests_price(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $product = $this->makeProduct('ZZ Attr Prod', ['price' => 100]);
        $groupId = $this->makeAttribute($product->id, 'Red', 5);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('selectProduct', (string) $product->id)
            ->assertSet('itemForm.attributes.' . $groupId, 'Red')
            ->assertSet('itemForm.price', 105.0);
    }

    public function test_add_item_persists_canonical_attribute_and_server_add_price(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $product = $this->makeProduct('ZZ Attr Prod 2', ['price' => 100]);
        $groupId = $this->makeAttribute($product->id, 'Red', 5);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('selectProduct', (string) $product->id)
            ->set('itemForm.qty', 2)
            ->call('saveItem')
            ->assertHasNoErrors();

        $detail = ShopOrderDetail::where('order_id', $order->id)->first();
        $this->assertNotNull($detail);
        $stored = json_decode((string) $detail->attribute, true);
        // add_price rebuilt from DB (5), not from any client value.
        $this->assertSame('Red__5.00', (string) $stored[$groupId]);
        $this->assertEqualsWithDelta(105, (float) $detail->price, 0.01);
        $this->assertEqualsWithDelta(210, (float) $detail->total_price, 0.01);
    }

    public function test_add_item_blocked_when_attribute_missing(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $product = $this->makeProduct('ZZ Attr Prod 3', ['price' => 100]);
        $this->makeAttribute($product->id, 'Red', 5);

        // Set product_id directly WITHOUT selecting an attribute → must be blocked.
        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('itemForm.product_id', (string) $product->id)
            ->set('itemForm.qty', 1)
            ->set('itemForm.price', 100)
            ->set('itemForm.attributes', [])
            ->call('saveItem');

        $this->assertSame(0, ShopOrderDetail::where('order_id', $order->id)->count());
    }

    public function test_change_attribute_adjusts_price_by_delta(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $product = $this->makeProduct('ZZ Attr Prod 4', ['price' => 100]);
        // One group, two options: Red(+5) default, Blue(+8).
        $group = ShopAttributeGroup::create(['name' => 'ZZ Color ' . uniqid(), 'type' => 'radio', 'status' => 1, 'sort' => 0]);
        foreach ([['Red', 5], ['Blue', 8]] as [$n, $p]) {
            ShopProductAttribute::create(['name' => $n, 'attribute_group_id' => $group->id, 'product_id' => $product->id, 'add_price' => $p, 'sort' => 0, 'status' => 1]);
        }

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('selectProduct', (string) $product->id)
            ->assertSet('itemForm.price', 105.0)          // 100 + Red(5)
            ->call('setItemAttribute', (string) $group->id, 'Blue')
            ->assertSet('itemForm.price', 108.0);         // 100 + Blue(8), delta applied
    }

    public function test_product_without_attributes_saves_normally(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();
        $product = $this->makeProduct('ZZ No Attr', ['price' => 40]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('itemForm.product_id', (string) $product->id)
            ->set('itemForm.qty', 2)
            ->set('itemForm.price', 40)
            ->call('saveItem')
            ->assertHasNoErrors();

        $detail = ShopOrderDetail::where('order_id', $order->id)->first();
        $this->assertNotNull($detail);
        $this->assertSame('[]', (string) $detail->attribute);
    }

    // -------------------------------------------------------------------------
    // Màn B — Tạo đơn admin (postCreate)
    // -------------------------------------------------------------------------

    /**
     * Create + authenticate an administrator (short-circuits URI permission).
     *
     * @return void
     */
    private function loginAdministrator(): void
    {
        $user = AdminUser::create([
            'id' => 'AU-ATT-' . substr(uniqid(), -6),
            'username' => 'attr_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Attr Admin',
            'email' => 'attr' . substr(uniqid(), -6) . '@test.local',
            'status' => 1,
        ]);
        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->storeId()]);
    }

    /**
     * Product resolvable by AdminProduct::getDetail (postCreate reads it).
     *
     * @param array<string, mixed> $overrides
     * @return ShopProduct
     */
    private function makeCreatableProduct(array $overrides = []): ShopProduct
    {
        $product = ShopProduct::create(array_merge([
            'sku' => 'ZZ-ATT-' . uniqid(),
            'alias' => 'zz-att-' . uniqid(),
            'price' => 100,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
        ], $overrides));

        foreach (array_keys(AdminLanguage::getListActive()->all()) as $code) {
            ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => 'Attr Item',
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }

        return $product;
    }

    /**
     * @param ShopProduct $product
     * @param array<int|string, string> $attributes group_id => option name.
     * @return array<string, mixed>
     */
    private function createPayload(ShopProduct $product, array $attributes): array
    {
        return [
            'first_name' => 'John',
            'email' => 'buyer-attr@example.com',
            'phone' => '0123456789',
            'address1' => 'Addr 1',
            'country' => 'US',
            'postcode' => '12345',
            'company' => 'ACME',
            'currency' => 'USD',
            'exchange_rate' => '1',
            'status' => '1',
            'payment_method' => 'Cash',
            'shipping_method' => 'Free',
            'shipping' => 0,
            'discount' => 0,
            'received' => 0,
            'products' => [
                ['product_id' => (string) $product->id, 'name' => 'John item', 'qty' => 2, 'price' => 105, 'tax' => 0, 'attributes' => $attributes],
            ],
        ];
    }

    public function test_post_create_persists_canonical_attribute(): void
    {
        $this->loginAdministrator();
        $product = $this->makeCreatableProduct();
        $groupId = $this->makeAttribute($product->id, 'Red', 5);

        // Client sends a tampered add_price shape — server must ignore it and use DB.
        $this->post(route('admin_order.post_create'), $this->createPayload($product, [$groupId => 'Red']))
            ->assertRedirect();

        $detail = ShopOrderDetail::where('product_id', $product->id)->first();
        $this->assertNotNull($detail);
        $stored = json_decode((string) $detail->attribute, true);
        $this->assertSame('Red__5.00', (string) $stored[$groupId]);
    }

    public function test_post_create_rolls_back_when_attribute_missing(): void
    {
        $this->loginAdministrator();
        $product = $this->makeCreatableProduct();
        $this->makeAttribute($product->id, 'Red', 5);

        // No attributes supplied for a product that requires them → whole order rolls back.
        $this->post(route('admin_order.post_create'), $this->createPayload($product, []))
            ->assertRedirect();

        $this->assertSame(0, ShopOrderDetail::where('product_id', $product->id)->count());
    }
}
