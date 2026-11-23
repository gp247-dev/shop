<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Admin\Livewire\CustomerManager;
use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\ShopEvents;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\ShopEventFixtures;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The shop records an event for every change of an order, a product's stock, a product or
 * a customer — whether it comes from the website, the admin screens, the API or a plugin
 * writing through the models — so an ERP misses nothing that happened outside the API.
 * MATRIX is the measure of MC-api-sync-event-coverage: each cell names the test proving it,
 * or why it does not apply.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-shop-events
 * @aidlc-adr api-admin-write_model-hook-event-emission
 */
#[Group('api-admin-write')]
class ShopEventCoverageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use OrderWriteFixtures;
    use ShopEventFixtures;
    use ApiCommerceFixtures, MakesShopProducts {
        ApiCommerceFixtures::makeProduct insteadof MakesShopProducts;
        MakesShopProducts::makeProduct as makeNamedProduct;
    }

    /** Event type × source → test proving it, or "n/a: <reason>". "plugin" = a write that a plugin makes through the shop models. */
    private const MATRIX = [
        'order.created' => [
            'web' => 'test_an_order_placed_on_the_website_or_through_the_api_records_the_order_and_the_stock_taken',
            'api' => 'test_an_order_placed_on_the_website_or_through_the_api_records_the_order_and_the_stock_taken',
            'admin' => 'test_an_order_created_on_the_admin_screen_or_by_a_plugin_records_order_created',
            'plugin' => 'test_an_order_created_on_the_admin_screen_or_by_a_plugin_records_order_created',
        ],
        'order.status_changed' => [
            'web' => 'n/a: the storefront has no page that changes an order status (a customer cancels through the API)',
            'api' => 'test_an_order_status_changed_on_the_admin_screen_through_the_api_or_by_a_plugin_records_order_status_changed',
            'admin' => 'test_an_order_status_changed_on_the_admin_screen_through_the_api_or_by_a_plugin_records_order_status_changed',
            'plugin' => 'test_an_order_status_changed_on_the_admin_screen_through_the_api_or_by_a_plugin_records_order_status_changed',
        ],
        'order.payment_status_changed' => [
            'web' => 'test_a_payment_entered_in_the_order_ledger_records_order_payment_status_changed',
            'api' => 'n/a: API clients never write the payment status; gateways confirm API orders on their web routes',
            'admin' => 'test_a_payment_entered_in_the_order_ledger_records_order_payment_status_changed',
            'plugin' => 'test_a_payment_entered_in_the_order_ledger_records_order_payment_status_changed',
        ],
        'stock.changed' => [
            'web' => 'test_an_order_placed_on_the_website_or_through_the_api_records_the_order_and_the_stock_taken',
            'api' => 'test_stock_moved_through_the_api_records_stock_changed',
            'admin' => 'test_stock_moved_on_the_admin_screens_or_by_a_plugin_records_stock_changed',
            'plugin' => 'test_stock_moved_on_the_admin_screens_or_by_a_plugin_records_stock_changed',
        ],
        'product.updated' => [
            'web' => 'n/a: the storefront does not edit products',
            'api' => 'test_a_product_changed_on_the_admin_form_through_the_api_or_by_a_plugin_records_product_updated',
            'admin' => 'test_a_product_changed_on_the_admin_form_through_the_api_or_by_a_plugin_records_product_updated',
            'plugin' => 'test_a_product_changed_on_the_admin_form_through_the_api_or_by_a_plugin_records_product_updated',
        ],
        'product.deleted' => [
            'web' => 'n/a: the storefront does not delete products',
            'api' => 'n/a: the API has no product delete endpoint',
            'admin' => 'test_a_product_deleted_on_the_admin_screen_or_by_a_plugin_records_product_deleted_with_its_sku',
            'plugin' => 'test_a_product_deleted_on_the_admin_screen_or_by_a_plugin_records_product_deleted_with_its_sku',
        ],
        'customer.created' => [
            'web' => 'test_a_customer_signing_up_anywhere_records_customer_created',
            'api' => 'test_a_customer_signing_up_anywhere_records_customer_created',
            'admin' => 'test_a_customer_signing_up_anywhere_records_customer_created',
            'plugin' => 'test_a_customer_signing_up_anywhere_records_customer_created',
        ],
        'customer.updated' => [
            'web' => 'test_a_customer_changed_anywhere_records_customer_updated_with_field_names_only',
            'api' => 'test_a_customer_changed_anywhere_records_customer_updated_with_field_names_only',
            'admin' => 'test_a_customer_changed_anywhere_records_customer_updated_with_field_names_only',
            'plugin' => 'test_a_customer_changed_anywhere_records_customer_updated_with_field_names_only',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->useSellableShop();
        $this->useMinimalCustomerForm();
        $this->setShopConfig(['use_shipping' => '0', 'use_payment' => '0', 'shop_allow_guest' => '1']);
        $this->setApiSwitch('api_v2_front_checkout', true);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_every_shop_event_type_is_registered_and_each_cell_of_the_coverage_matrix_is_proved_or_explained(): void
    {
        $this->assertSame(array_keys(ShopEvents::TYPES), array_keys(self::MATRIX));
        foreach (ShopEvents::TYPES as $type => $definition) {
            // WHY not config('…events.' . $type): a dot inside the type name would read as nesting.
            $this->assertSame($definition, config('gp247-config.api.events')[$type] ?? null, "{$type} is registered with core");
        }
        foreach (self::MATRIX as $type => $cells) {
            $this->assertSame(['web', 'api', 'admin', 'plugin'], array_keys($cells), $type);
            foreach ($cells as $source => $proof) {
                $this->assertTrue(str_starts_with($proof, 'n/a: ') || method_exists($this, $proof), "{$type} × {$source}: {$proof}");
            }
        }
    }

    public function test_an_order_placed_on_the_website_or_through_the_api_records_the_order_and_the_stock_taken(): void
    {
        $product = $this->makeNamedProduct('ZZ Event ' . uniqid(), ['price' => 25, 'stock' => 20, 'tax_id' => 0]);
        $mark = $this->startEventLog();

        $web = $this->orderOnTheWebsite($product);
        $api = $this->orderThroughTheApi($product);

        foreach (['web' => $web, 'api' => $api] as $source => $order) {
            $created = $this->eventsAfter($mark, 'order.created', (string) $order->id);
            $this->assertCount(1, $created, "{$source} order");
            $this->assertSame($source, $created[0]->source);
            $this->assertSame(['status', 'payment_status', 'channel', 'total', 'currency'], array_keys($created[0]->data));
        }
        $stock = $this->eventsAfter($mark, 'stock.changed', (string) $product->id);
        $this->assertSame(['web', 'api'], $stock->pluck('source')->all());
        $this->assertEquals([['delta' => -2, 'stock' => 18], ['delta' => -2, 'stock' => 16]], $stock->pluck('data')->all());
    }

    public function test_an_order_created_on_the_admin_screen_or_by_a_plugin_records_order_created(): void
    {
        $mark = $this->startEventLog();

        $byPlugin = $this->makeOrder(['total' => 50]);
        $onAdmin = AdminOrder::create(['customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1, 'email' => 'zz-admin-order@example.com',
            'first_name' => 'ZZ Admin', 'status' => 1, 'payment_status' => 1, 'shipping_status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);

        $this->assertEquals(50, $this->eventsAfter($mark, 'order.created', (string) $byPlugin->id)->sole()->data['total']);
        $this->assertCount(1, $this->eventsAfter($mark, 'order.created', (string) $onAdmin->id));
    }

    public function test_an_order_status_changed_on_the_admin_screen_through_the_api_or_by_a_plugin_records_order_status_changed(): void
    {
        $order = $this->makeOrder(['status' => 1, 'shipping_status' => 1]);
        $mark = $this->startEventLog();
        $this->asUser(administrator: true);

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])->call('changeOrderStatus', 2);
        $this->write($this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']), 'order/' . $order->id . '/status', ['status' => 3])->assertOk();
        ShopOrder::find($order->id)->update(['shipping_status' => 2]);

        $data = $this->eventsAfter($mark, 'order.status_changed', (string) $order->id)->pluck('data')->all();
        $this->assertEquals([
            ['field' => 'status', 'from' => 1, 'to' => 2],
            ['field' => 'status', 'from' => 2, 'to' => 3],
            ['field' => 'shipping_status', 'from' => 1, 'to' => 2],
        ], $data);
    }

    public function test_a_payment_entered_in_the_order_ledger_records_order_payment_status_changed(): void
    {
        $order = $this->makeOrder(['total' => 100, 'balance' => 100, 'received' => 0, 'payment_status' => 1]);
        $mark = $this->startEventLog();

        // The admin ledger screen and the payment plugins all record money here.
        $order->recordPayment(40, 'CashPayment');
        ShopOrder::find($order->id)->recordPayment(60, 'CashPayment');

        $events = $this->eventsAfter($mark, 'order.payment_status_changed', (string) $order->id);
        $this->assertCount(2, $events);
        $this->assertEquals(['received' => 40, 'balance' => 60], array_intersect_key($events[0]->data, ['received' => 1, 'balance' => 1]));
        $this->assertEquals(['received' => 100, 'balance' => 0], array_intersect_key($events[1]->data, ['received' => 1, 'balance' => 1]));
        $this->assertSame($events[0]->data['to'], $events[1]->data['from']);
    }

    public function test_stock_moved_through_the_api_records_stock_changed(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $token = $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
        $mark = $this->startEventLog();

        $this->stock($token, $product, ['delta' => -3], 'zz-ev-stock-1')->assertOk();
        $this->stock($token, $product, ['set' => 40], 'zz-ev-stock-2')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Idempotency-Key' => 'zz-ev-stock-3'])
            ->postJson($this->v2Core() . '/stock/batch', ['items' => [['sku' => $product->sku, 'delta' => 2]]])->assertOk();

        $this->assertEquals([['delta' => -3, 'stock' => 7], ['delta' => 33, 'stock' => 40], ['delta' => 2, 'stock' => 42]],
            $this->eventsAfter($mark, 'stock.changed', (string) $product->id)->pluck('data')->all());
    }

    public function test_stock_moved_on_the_admin_screens_or_by_a_plugin_records_stock_changed(): void
    {
        [$order, $product] = $this->orderWithStock(98);
        $mark = $this->startEventLog();
        $this->asUser(administrator: true);

        // Cancelling on the admin screen gives the goods back.
        Livewire::test(OrderManager::class, ['id' => (string) $order->id])->call('changeOrderStatus', ShopOrderStatus::CANCELED);
        // A plugin receiving goods through the model.
        $fresh = ShopProduct::find($product->id);
        $fresh->stock += 5;
        $fresh->save();

        $this->assertEquals([['delta' => 2, 'stock' => 100], ['delta' => 5, 'stock' => 105]],
            $this->eventsAfter($mark, 'stock.changed', (string) $product->id)->pluck('data')->all());
        $this->assertCount(0, $this->eventsAfter($mark, 'product.updated', (string) $product->id), 'a stock move alone is not a product edit');
    }

    public function test_a_product_changed_on_the_admin_form_through_the_api_or_by_a_plugin_records_product_updated(): void
    {
        $product = $this->inCategory($this->makeNamedProduct('ZZ Edit ' . uniqid()));
        $mark = $this->startEventLog();
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();

        Livewire::test(ProductManager::class, ['id' => (string) $product->id])->set('form.price', 77)->call('save')->assertHasNoErrors();
        $this->apiCall('PATCH', $this->v2Core() . '/product/' . $product->id, $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']), ['status' => 0])->assertOk();
        ShopProduct::find($product->id)->update(['approve' => 0]);
        ShopProduct::find($product->id)->update(['view' => 999, 'sold' => 3]);

        $data = $this->eventsAfter($mark, 'product.updated', (string) $product->id)->pluck('data')->all();
        $this->assertCount(3, $data, 'the view counter and the sold counter are not edits');
        $this->assertContains('price', $data[0]['fields']);
        $this->assertEquals(['fields' => ['status'], 'status' => 0], $data[1]);
        $this->assertEquals(['fields' => ['approve'], 'status' => 0], $data[2]);
    }

    public function test_a_bulk_change_the_site_owner_chose_to_keep_quiet_records_no_event_and_recording_resumes_after_it(): void
    {
        $product = $this->makeNamedProduct('ZZ Quiet ' . uniqid());
        $mark = $this->startEventLog();

        $returned = ShopEvents::muted(function () use ($product) {
            ShopProduct::find($product->id)->update(['approve' => 0]);
            ShopEvents::stockMoved($product->id, -1);

            return 'done';
        });
        $this->assertSame('done', $returned);
        $this->assertCount(0, $this->eventsAfter($mark, null, (string) $product->id), 'nothing is recorded inside the quiet bulk change');

        try {
            ShopEvents::muted(function () {
                throw new \RuntimeException('import row failed');
            });
        } catch (\RuntimeException $e) {
            // The failing bulk step must not leave the shop silent afterwards.
        }
        ShopProduct::find($product->id)->update(['approve' => 1]);

        $this->assertCount(1, $this->eventsAfter($mark, 'product.updated', (string) $product->id));
        $this->assertTrue(ShopEvents::active());
    }

    public function test_a_product_deleted_on_the_admin_screen_or_by_a_plugin_records_product_deleted_with_its_sku(): void
    {
        $onAdmin = $this->makeNamedProduct('ZZ Delete A');
        $byPlugin = $this->makeProduct();
        $mark = $this->startEventLog();
        $this->asUser(administrator: true);
        $this->useAdminStore();

        Livewire::test(ProductManager::class)->call('delete', (string) $onAdmin->id);
        $byPlugin->delete();

        $this->assertNull(ShopProduct::find($onAdmin->id));
        $this->assertEquals(['sku' => $onAdmin->sku], $this->eventsAfter($mark, 'product.deleted', (string) $onAdmin->id)->sole()->data);
        $this->assertEquals(['sku' => $byPlugin->sku], $this->eventsAfter($mark, 'product.deleted', (string) $byPlugin->id)->sole()->data);
    }

    public function test_a_customer_signing_up_anywhere_records_customer_created(): void
    {
        $mark = $this->startEventLog();
        $this->asUser(slugs: ['admin_customer']);

        $this->flushHeaders();
        $this->postJson($this->v2Front() . '/register', ['first_name' => 'ZZ', 'email' => $apiEmail = 'zz-ev-api-' . uniqid() . '@example.com',
            'address1' => '1 ZZ street', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123'])->assertStatus(201);
        $this->post(route('customer.postRegister'), ['first_name' => 'ZZ', 'last_name' => 'Web', 'email' => $webEmail = 'zz-ev-web-' . uniqid() . '@example.com',
            'address1' => '1 ZZ street', 'country' => 'VN', 'password' => 'Secret#123', 'password_confirmation' => 'Secret#123'])->assertStatus(302);
        $this->app['auth']->guard('customer')->logout();
        Livewire::test(CustomerManager::class)->set('form.email', $adminEmail = 'zz-ev-admin-' . uniqid() . '@example.com')
            ->set('form.first_name', 'ZZ')->set('form.last_name', 'Admin')->set('form.country', 'VN')->set('form.address1', 'Some street')
            ->set('form.password', 'secret123')->set('form.password_confirmation', 'secret123')->call('save')->assertHasNoErrors();
        $byPlugin = $this->makeCustomer();

        foreach ([$apiEmail => 'api', $webEmail => 'web', $adminEmail => null, $byPlugin->email => null] as $email => $source) {
            $customer = ShopCustomer::where('email', $email)->firstOrFail();
            $event = $this->eventsAfter($mark, 'customer.created', (string) $customer->id)->sole();
            $this->assertSame(['status' => 1], $event->data, $email);
            if ($source !== null) {
                $this->assertSame($source, $event->source, $email);
            }
        }
    }

    public function test_a_customer_changed_anywhere_records_customer_updated_with_field_names_only(): void
    {
        $customer = $this->makeCustomer();
        $customer->update(['address1' => '1 ZZ street', 'country' => 'VN']);
        $mark = $this->startEventLog();
        $this->asUser(slugs: ['admin_customer']);

        $session = $this->apiCall('POST', $this->v2Front() . '/login', null, ['email' => $customer->email, 'password' => 'Secret#123'])->assertOk()->json('data');
        $this->apiCall('PUT', $this->v2Front() . '/member/profile', $session['access_token'], ['first_name' => 'ZZ Api', 'email' => $customer->email, 'address1' => '1 ZZ street'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs(ShopCustomer::find($customer->id), 'customer');
        $this->post(route('customer.post_change_infomation'), ['first_name' => 'ZZ Web', 'last_name' => 'Api', 'email' => $customer->email,
            'address1' => '1 ZZ street', 'country' => 'VN'])->assertStatus(302);
        $this->app['auth']->guard('customer')->logout();
        Livewire::test(CustomerManager::class, ['id' => (string) $customer->id])->set('form.first_name', 'ZZ Admin')->call('save')->assertHasNoErrors();
        ShopCustomer::find($customer->id)->update(['phone' => '0900000000', 'password' => bcrypt('Other#123')]);

        $events = $this->eventsAfter($mark, 'customer.updated', (string) $customer->id);
        $this->assertSame([['first_name'], ['first_name'], ['first_name'], ['phone']], $events->map(fn ($e) => $e->data['fields'])->all());
        $this->assertSame(['api', 'web'], $events->take(2)->pluck('source')->all());
        $this->assertStringNotContainsString('ZZ Admin', json_encode($events->pluck('data')->all()), 'values never leave in an event');
    }

    /**
     * @param ShopProduct $product
     * @return ShopOrder
     */
    private function orderOnTheWebsite(ShopProduct $product): ShopOrder
    {
        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 2])->assertStatus(302);
        $rowId = (string) (new \GP247\Shop\Services\CartService())->instance('default')->content()->first()->rowId;
        $this->post(route('checkout.prepare'), ['store_id' => (string) GP247_STORE_ID_ROOT, "qty-{$rowId}" => 2])->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk();
        Livewire::test(CheckoutWizard::class)->set('first_name', 'ZZ Buyer')->set('email', 'zz-event@example.com')->set('address1', '1 ZZ street')
            ->call('nextStep')->assertSet('step', 'confirm');
        $token = session('checkoutToken');
        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));

        return ShopOrder::where('checkout_token', $token)->firstOrFail();
    }

    /**
     * @param ShopProduct $product
     * @return ShopOrder
     */
    private function orderThroughTheApi(ShopProduct $product): ShopOrder
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $product->id, 'qty' => 2])->assertStatus(201);
        $this->cartCall('PUT', '/checkout/address', $token, null, ['first_name' => 'ZZ Buyer', 'email' => 'zz-event@example.com', 'address1' => '1 ZZ street'])->assertOk();
        $this->cartCall('GET', '/checkout/totals', $token)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $id = $this->withHeader('Authorization', 'Bearer ' . $token)->withHeader('Idempotency-Key', 'zz-event-' . uniqid())
            ->postJson($this->v2Front() . '/checkout/place')->assertStatus(201)->json('data.order.id');

        return ShopOrder::findOrFail($id);
    }

    /**
     * @param string      $token
     * @param ShopProduct $product
     * @param array       $body
     * @param string      $key
     * @return \Illuminate\Testing\TestResponse
     */
    private function stock(string $token, ShopProduct $product, array $body, string $key)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Idempotency-Key' => $key])
            ->postJson($this->v2Core() . '/product/' . $product->id . '/stock', $body);
    }
}
