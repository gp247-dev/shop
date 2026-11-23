<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An order placed through API v2 reaches plugins through the same hooks as a website
 * order — the order "created" model event once its lines are written, the order source
 * on the order, the order-success step — so a plugin that splits orders by vendor or
 * books commissions needs nothing API-specific, and the shop's API code never depends
 * on a plugin. (Payment / shipping / total plugins finish through the same
 * CheckoutService::complete() call: ApiWebCheckoutParityTest.)
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-vendor-split-seam
 */
#[Group('api-commerce')]
class ApiOrderPluginSeamTest extends TestCase
{
    use DatabaseTransactions;
    use ApiCommerceFixtures;

    private string $token;
    private ShopProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSellableShop();
        $this->useMinimalCustomerForm();
        $this->setShopConfig(['use_shipping' => '0', 'use_payment' => '0']);
        $this->setApiSwitch('api_v2_front_checkout', true);
        $this->token = $this->tokenFor($this->makeCustomer(), ['user']);
        $this->product = $this->makeProduct(['price' => 25, 'tax_id' => 0, 'stock' => 10]);
    }

    /**
     * Put 2 × product in the cart, set the address and place the order.
     *
     * @return ShopOrder
     */
    private function placeOrder(): ShopOrder
    {
        $this->cartCall('POST', '/cart/items', $this->token, null, ['product_id' => (string) $this->product->id, 'qty' => 2])->assertStatus(201);
        $this->cartCall('PUT', '/checkout/address', $this->token, null,
            ['first_name' => 'ZZ Buyer', 'email' => 'zz-seam@example.com', 'address1' => '1 ZZ street'])->assertOk();
        $this->cartCall('GET', '/checkout/totals', $this->token)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $id = $this->withHeader('Authorization', 'Bearer ' . $this->token)->withHeader('Idempotency-Key', 'zz-seam-' . uniqid())
            ->postJson($this->v2Front() . '/checkout/place')->assertStatus(201)->json('data.order.id');

        return ShopOrder::with('details')->findOrFail($id);
    }

    public function test_a_plugin_listening_for_new_orders_sees_the_api_order_with_its_lines(): void
    {
        $seen = [];
        // WHY afterCommit: how a vendor plugin reads a new order — the lines are written after the order row, in the same transaction.
        ShopOrder::created(function (ShopOrder $order) use (&$seen) {
            DB::connection(GP247_DB_CONNECTION)->afterCommit(function () use ($order, &$seen) {
                $seen[(string) $order->id] = ShopOrder::with('details')->find($order->id)->details->count();
            });
        });

        $order = $this->placeOrder();

        $this->assertSame([(string) $order->id => 1], $seen, 'one created event, after the line is written');
        if (Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . 'shop_order', 'channel')) {
            $this->assertSame('api', ShopOrder::find($order->id)->channel, 'a plugin can tell an app order from a website order');
        }
    }

    public function test_the_order_success_event_runs_for_an_api_order(): void
    {
        $calls = [];
        // WHY the mail: the order-success event and the order mail run back to back in complete(); the mail is the step a
        // test can observe without a plugin defining gp247_listen_order_success.
        $this->app['config']->set('mail.default', 'array');
        $this->setShopConfig(['order_success_to_customer' => '1', 'order_success_to_admin' => '0', 'email_action_mode' => '1', 'email_action_queue' => '0']);

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event) use (&$calls) {
            $calls[] = implode(',', array_map(fn ($a) => $a->getAddress(), $event->message->getTo()));
        });

        $this->placeOrder();

        $this->assertContains('zz-seam@example.com', $calls, 'the customer gets the same order mail as a website order');
    }

    public function test_the_shop_api_code_never_depends_on_a_plugin_or_template(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('vendor/gp247/shop/src/Api'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            // WHY tokens: a docblock may name a plugin; only code counts.
            $code = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all(file_get_contents($file->getPathname()))));
            if (preg_match('/App\\\\+GP247\\\\+(Plugins|Templates)\\\\+\w/', $code)) {
                $offenders[] = basename($file->getPathname());
            }
        }

        $this->assertSame([], $offenders, 'plugins plug into the shop, never the other way round');
    }
}
