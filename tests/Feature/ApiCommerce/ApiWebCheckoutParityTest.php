<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce;

use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiCommerce\Support\ApiCommerceFixtures;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The same cart ordered on the website and through API v2 gives the same order: same
 * subtotal, discount, shipping, tax, total, same order lines (effective unit price with
 * the attribute surcharge) and the same stock taken — because the API runs the website's
 * checkout code, and has no order-writing or total-computing path of its own.
 *
 * @aidlc-unit api-commerce
 * @aidlc-story US-api-commerce-web-parity
 */
#[Group('api-commerce')]
class ApiWebCheckoutParityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use ApiCommerceFixtures, MakesShopProducts {
        MakesShopProducts::makeProduct insteadof ApiCommerceFixtures;
        ApiCommerceFixtures::makeProduct as makeApiProduct;
    }

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

    public function test_the_same_cart_ordered_on_the_website_and_through_the_api_gives_the_same_order(): void
    {
        $product = $this->makeProduct('ZZ Parity ' . uniqid(), ['price' => 25, 'stock' => 20, 'tax_id' => 0]);
        $group = $this->addAttributeGroup($product, ['Red' => 5, 'Blue' => 8]);

        $web = $this->orderOnTheWebsite($product, (string) $group->id);
        $afterWeb = (float) ShopProduct::find($product->id)->stock;
        $api = $this->orderThroughTheApi($product, (string) $group->id);
        $afterApi = (float) ShopProduct::find($product->id)->stock;

        foreach (['subtotal', 'discount', 'shipping', 'tax', 'other_fee', 'total', 'balance', 'currency', 'payment_status', 'status'] as $field) {
            $this->assertEquals($web->{$field}, $api->{$field}, "order {$field} differs between the website and the API");
        }
        $line = fn (ShopOrder $o) => $o->details->map(fn ($d) => [(string) $d->product_id, (float) $d->price, (float) $d->qty, (float) $d->total_price])->all();
        $this->assertSame($line($web), $line($api));
        $this->assertEquals(60, (float) $api->total, '2 × (25 + Red 5)');
        $this->assertEquals(20 - $afterWeb, $afterWeb - $afterApi, 'the same stock is taken');
    }

    public function test_the_api_has_no_order_writing_or_total_computing_path_of_its_own(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('vendor/gp247/shop/src/Api'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            // WHY tokens: a docblock may name these calls; only code counts.
            $code = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t,
                token_get_all(file_get_contents($file->getPathname()))));
            foreach (['createOrder(', 'processDataTotal(', 'sumValueTotal(', 'updateStock('] as $call) {
                if (str_contains($code, $call)) {
                    $offenders[] = basename($file->getPathname()) . ': ' . $call;
                }
            }
        }

        $this->assertSame([], $offenders, 'the API must go through CheckoutService, not around it');
    }

    /**
     * @param ShopProduct $product
     * @param string      $groupId
     * @return ShopOrder
     */
    private function orderOnTheWebsite(ShopProduct $product, string $groupId): ShopOrder
    {
        $customer = ShopCustomer::create(['first_name' => 'ZZ', 'email' => 'zz-parity-web-' . uniqid() . '@example.com',
            'password' => bcrypt('Secret#123'), 'status' => 1, 'store_id' => (string) $this->storeId()]);
        $this->actingAs($customer, 'customer');
        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 2, 'form_attr' => [$groupId => 'Red']])->assertStatus(302);
        $rowId = (string) (new \GP247\Shop\Services\CartService())->instance('default')->content()->first()->rowId;
        $this->post(route('checkout.prepare'), ['store_id' => (string) $this->storeId(), "qty-{$rowId}" => 2])->assertRedirect(route('checkout'));
        $this->get(route('checkout'))->assertOk();
        Livewire::test(CheckoutWizard::class)->set('first_name', 'ZZ Buyer')->set('email', 'zz-parity@example.com')->set('address1', '1 ZZ street')
            ->call('nextStep')->assertSet('step', 'confirm');
        $token = session('checkoutToken');
        $this->post(route('order.add'), ['_token' => csrf_token()])->assertRedirect(route('order.success'));
        $this->app['auth']->guard('customer')->logout();

        return ShopOrder::with('details')->where('checkout_token', $token)->firstOrFail();
    }

    /**
     * @param ShopProduct $product
     * @param string      $groupId
     * @return ShopOrder
     */
    private function orderThroughTheApi(ShopProduct $product, string $groupId): ShopOrder
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);
        $this->cartCall('POST', '/cart/items', $token, null, ['product_id' => (string) $product->id, 'qty' => 2, 'options' => [$groupId => 'Red']])->assertStatus(201);
        $this->cartCall('PUT', '/checkout/address', $token, null, ['first_name' => 'ZZ Buyer', 'email' => 'zz-parity@example.com', 'address1' => '1 ZZ street'])->assertOk();
        $this->cartCall('GET', '/checkout/totals', $token)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $id = $this->withHeader('Authorization', 'Bearer ' . $token)->withHeader('Idempotency-Key', 'zz-parity-' . uniqid())
            ->postJson($this->v2Front() . '/checkout/place')->assertStatus(201)->json('data.order.id');

        return ShopOrder::with('details')->findOrFail($id);
    }
}
