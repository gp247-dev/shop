<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresStockPolicy;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Parity of the product-quantity/stock rules between the ADMIN order flows and
 * the storefront (US-SADM-order-stock-parity):
 *
 *  - Creating an order in admin (AdminOrderController::postCreate) must decrement
 *    stock/sold, exactly like ShopOrder::createOrder — previously it wrote details
 *    with ShopOrderDetail::create() and never touched stock (RISK-BIZ-admin-oversell).
 *  - Add-item / edit-qty (legacy + Livewire HasOrderItems) must check
 *    product_buy_out_of_stock and HARD-BLOCK over-stock — the admin policy is now unified with the storefront: over-stock
 *    is rejected (no line written, no stock moved), not merely warned
 *    (ADR shop-admin_order-stock-parity, revised 2026-08-16). To sell beyond
 *    stock, product_buy_out_of_stock must be enabled.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-stock-parity
 * @aidlc-adr shop-admin_order-stock-parity
 */
class OrderStockParityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;
    use ConfiguresStockPolicy;

    /**
     * Create + authenticate an administrator on the `admin` guard. The
     * administrator role short-circuits the URI permission middleware, so the
     * legacy postCreate controller runs without RBAC wiring in the way.
     *
     * @return AdminUser
     */
    private function loginAdministrator(): AdminUser
    {
        $user = AdminUser::create([
            'id' => 'AU-STK-' . substr(uniqid(), -6),
            'username' => 'stock_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Stock Admin',
            'email' => 'stock' . substr(uniqid(), -6) . '@test.local',
            'status' => 1,
        ]);

        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->storeId()]);

        return $user;
    }

    /**
     * A product resolvable by AdminProduct::getDetail (postCreate reads it): it
     * left-joins shop_product_description on the active locale, so the row needs a
     * description per active language on top of the base MakesShopOrders product.
     *
     * @param array<string, mixed> $overrides Column overrides (e.g. stock).
     * @return ShopProduct
     */
    private function makeCreatableProduct(array $overrides = []): ShopProduct
    {
        $product = $this->makeProduct($overrides);

        foreach (array_keys(AdminLanguage::getListActive()->all()) as $code) {
            ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => 'Stock Item',
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }

        return $product;
    }

    /**
     * A valid postCreate payload with one product line; overridable per test.
     *
     * @param ShopProduct $product
     * @param float       $qty
     * @return array<string, mixed>
     */
    private function createPayload(ShopProduct $product, float $qty): array
    {
        return [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'buyer@example.com',
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
                ['product_id' => (string) $product->id, 'name' => 'John item', 'qty' => $qty, 'price' => 10, 'tax' => 0],
            ],
        ];
    }

    // --- A. postCreate: decrement stock (parity with createOrder) -----------

    public function test_post_create_decrements_stock_and_sold(): void
    {
        $this->loginAdministrator();
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeCreatableProduct(['stock' => 10, 'sold' => 0]);

        $response = $this->post(route('admin_order.post_create'), $this->createPayload($product, 3));
        $response->assertRedirect();

        $fresh = ShopProduct::find($product->id);
        $this->assertEqualsWithDelta(7, (float) $fresh->stock, 0.001, 'stock must drop by ordered qty');
        $this->assertEqualsWithDelta(3, (float) $fresh->sold, 0.001, 'sold must rise by ordered qty');
        $this->assertSame(1, ShopOrderDetail::where('product_id', $product->id)->count());
    }

    // --- B. postCreate: over-stock policy = hard block (unified) ------------

    public function test_post_create_over_stock_is_blocked_when_buy_out_disabled(): void
    {
        $this->loginAdministrator();
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeCreatableProduct(['stock' => 2, 'sold' => 0]);

        $response = $this->post(route('admin_order.post_create'), $this->createPayload($product, 5));
        // Hard block: the throw rolls back the transaction and redirects back with error.
        $response->assertRedirect();
        $response->assertSessionHas('error');

        // No order line written and stock untouched (unified with storefront).
        $this->assertSame(0, ShopOrderDetail::where('product_id', $product->id)->count());
        $this->assertEqualsWithDelta(2, (float) ShopProduct::find($product->id)->stock, 0.001);
    }

    public function test_post_create_over_stock_creates_when_buy_out_enabled(): void
    {
        $this->loginAdministrator();
        $this->setProductStock(true);
        $this->setBuyOutOfStock(true);
        $product = $this->makeCreatableProduct(['stock' => 2, 'sold' => 0]);

        $response = $this->post(route('admin_order.post_create'), $this->createPayload($product, 5));
        $response->assertRedirect();
        $response->assertSessionMissing('error');

        // Over-stock allowed: order created, stock goes negative.
        $this->assertSame(1, ShopOrderDetail::where('product_id', $product->id)->count());
        $this->assertEqualsWithDelta(-3, (float) ShopProduct::find($product->id)->stock, 0.001);
    }

    // --- C. Livewire add item: hard block on over-stock ---------------------

    public function test_livewire_add_item_over_stock_is_blocked(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $order = $this->makeOrder();
        $product = $this->makeProduct(['stock' => 5, 'price' => 10]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('itemForm.product_id', (string) $product->id)
            ->set('itemForm.qty', 10)
            ->set('itemForm.price', 10)
            ->set('itemForm.tax', 0)
            ->call('saveItem')
            ->assertDispatched('notify', type: 'error');

        // Rejected: no line written and stock untouched (unified with storefront).
        $this->assertSame(0, ShopOrderDetail::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(5, (float) ShopProduct::find($product->id)->stock, 0.001);
    }

    public function test_livewire_add_item_within_stock_succeeds(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $order = $this->makeOrder();
        $product = $this->makeProduct(['stock' => 20, 'price' => 10]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('itemForm.product_id', (string) $product->id)
            ->set('itemForm.qty', 3)
            ->set('itemForm.price', 10)
            ->set('itemForm.tax', 0)
            ->call('saveItem')
            ->assertHasNoErrors()
            ->assertNotDispatched('notify', type: 'error');

        $this->assertSame(1, ShopOrderDetail::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(17, (float) ShopProduct::find($product->id)->stock, 0.001);
    }

    public function test_livewire_add_item_over_stock_allowed_when_buy_out_enabled(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $this->setProductStock(true);
        $this->setBuyOutOfStock(true);
        $order = $this->makeOrder();
        $product = $this->makeProduct(['stock' => 5, 'price' => 10]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->set('itemForm.product_id', (string) $product->id)
            ->set('itemForm.qty', 10)
            ->set('itemForm.price', 10)
            ->set('itemForm.tax', 0)
            ->call('saveItem')
            ->assertHasNoErrors()
            ->assertNotDispatched('notify', type: 'error');

        // Over-stock allowed: line written, stock goes negative.
        $this->assertSame(1, ShopOrderDetail::where('order_id', $order->id)->count());
        $this->assertEqualsWithDelta(-5, (float) ShopProduct::find($product->id)->stock, 0.001);
    }

    // --- D. Livewire edit qty up over-stock: hard block ---------------------

    public function test_livewire_edit_item_increase_over_stock_is_blocked(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $order = $this->makeOrder();
        $product = $this->makeProduct(['stock' => 10, 'price' => 10]);
        $item = $this->makeOrderItem($order, [
            'product_id' => (string) $product->id, 'qty' => 2, 'price' => 10, 'total_price' => 20,
        ]);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('editItem', (string) $item->id)
            ->set('itemForm.qty', 50)
            ->call('saveItem')
            ->assertDispatched('notify', type: 'error');

        // Rejected: qty unchanged, stock untouched.
        $this->assertEqualsWithDelta(2, (float) ShopOrderDetail::find($item->id)->qty, 0.001);
        $this->assertEqualsWithDelta(10, (float) ShopProduct::find($product->id)->stock, 0.001);
    }
}
