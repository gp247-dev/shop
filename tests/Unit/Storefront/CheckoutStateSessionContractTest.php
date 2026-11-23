<?php

namespace GP247\Shop\Tests\Unit\Storefront;

use GP247\Shop\Services\Checkout\CheckoutState;
use GP247\Shop\Services\Checkout\SessionCheckoutState;
use GP247\Shop\Tests\TestCase;

/**
 * The web CheckoutState writes exactly the session keys plugins and the totals/shipping
 * classes read (storeCheckout, dataCheckout, shippingAddress, shippingMethod,
 * paymentMethod, totalMethod, dataTotal, checkoutToken, step, orderID…), and clear()
 * forgets the same keys the controller always cleared — shippingAddress, step and
 * address_process survive, as before.
 */
class CheckoutStateSessionContractTest extends TestCase
{
    private function state(): CheckoutState
    {
        return app(CheckoutState::class);
    }

    public function test_the_web_state_is_the_session(): void
    {
        $this->assertInstanceOf(SessionCheckoutState::class, $this->state());
    }

    public function test_setters_write_the_legacy_session_keys(): void
    {
        $s = $this->state();
        $s->setStoreId('1');
        $s->setLines(['row' => 'line']);
        $s->setShippingAddress(['first_name' => 'ZZ']);
        $s->setAddressProcess('new');
        $s->setShippingMethod('ShippingStandard');
        $s->setPaymentMethod('Cash');
        $s->setTotals([['code' => 'total', 'value' => 5]]);
        $s->setOrderId('ORD-1');
        $s->setOrderSnapshot(['a' => 1], ['b' => 2]);
        $s->markConfirmStep();

        $this->assertSame('1', session('storeCheckout'));
        $this->assertSame(['row' => 'line'], session('dataCheckout'));
        $this->assertSame(['first_name' => 'ZZ'], session('shippingAddress'));
        $this->assertSame('new', session('address_process'));
        $this->assertSame('ShippingStandard', session('shippingMethod'));
        $this->assertSame('Cash', session('paymentMethod'));
        $this->assertSame([['code' => 'total', 'value' => 5]], session('dataTotal'));
        $this->assertSame('ORD-1', session('orderID'));
        $this->assertSame(['a' => 1], session('dataOrder'));
        $this->assertSame(['b' => 2], session('arrCartDetail'));
        $this->assertSame('checkout.confirm', session('step'));
        $this->assertSame('checkout.confirm', $s->step());
    }

    public function test_getters_read_the_legacy_session_keys_and_return_null_when_absent(): void
    {
        $s = $this->state();
        $this->assertNull($s->lines());
        $this->assertSame([], $s->totalMethods());
        $this->assertSame('', $s->step());

        session(['totalMethod' => ['ShopDiscount' => 'CODE'], 'paymentMethod' => 'Cash', 'shippingAddress' => ['x' => 1]]);
        $this->assertSame(['ShopDiscount' => 'CODE'], $s->totalMethods());
        $this->assertSame('Cash', $s->paymentMethod());
        $this->assertSame(['x' => 1], $s->shippingAddress());
    }

    public function test_the_token_is_stamped_once_and_rotated_on_demand(): void
    {
        $s = $this->state();
        $first = $s->ensureToken();
        $this->assertSame(32, strlen($first));
        $this->assertSame($first, $s->ensureToken());
        $this->assertSame($first, session('checkoutToken'));

        $s->forgetToken();
        $this->assertNull(session('checkoutToken'));
    }

    public function test_clear_forgets_the_checkout_keys_but_keeps_address_step_and_address_process(): void
    {
        session([
            'paymentMethod' => 'Cash', 'shippingMethod' => 'S', 'totalMethod' => ['x'], 'otherMethod' => 'o', 'dataTotal' => [1],
            'dataCheckout' => [1], 'storeCheckout' => '1', 'dataOrder' => [1], 'arrCartDetail' => [1], 'orderID' => 'O',
            'checkoutToken' => 't', 'shippingAddress' => ['k' => 'v'], 'step' => 'checkout.confirm', 'address_process' => 'new',
        ]);

        $this->state()->clear();

        foreach (['paymentMethod', 'shippingMethod', 'totalMethod', 'otherMethod', 'dataTotal', 'dataCheckout', 'storeCheckout',
            'dataOrder', 'arrCartDetail', 'orderID', 'checkoutToken'] as $key) {
            $this->assertFalse(session()->has($key), "clear() forgets {$key}");
        }
        $this->assertSame(['k' => 'v'], session('shippingAddress'));
        $this->assertSame('checkout.confirm', session('step'));
        $this->assertSame('new', session('address_process'));
    }
}
