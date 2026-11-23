<?php

namespace GP247\Shop\Tests\Unit\Storefront;

use GP247\Shop\Services\Checkout\CheckoutContext;
use GP247\Shop\Services\Checkout\CheckoutService;
use GP247\Shop\Services\Checkout\CheckoutState;
use GP247\Shop\Services\Checkout\PlaceOrderResult;
use GP247\Shop\Services\Checkout\SessionCheckoutState;
use GP247\Shop\Tests\TestCase;

/**
 * CheckoutService holds the checkout rules the web controller and wizard used to carry
 * inline. It works on whatever CheckoutState it is given — the session on the web, an
 * in-memory state here — and reports refusals as a PlaceOrderResult instead of a redirect.
 */
class CheckoutServiceTest extends TestCase
{
    public function test_the_container_gives_the_service_the_web_session_state(): void
    {
        $this->assertInstanceOf(SessionCheckoutState::class, app(CheckoutService::class)->state());
    }

    public function test_placing_an_order_without_lines_is_refused_without_touching_the_session(): void
    {
        $service = new CheckoutService(new ZzArrayCheckoutState());

        $result = $service->placeOrder(new CheckoutContext(customer: null, customerId: 0));

        $this->assertSame(PlaceOrderResult::CART_EMPTY, $result->status);
        $this->assertFalse($result->isPlaced());
        $this->assertFalse(session()->has('dataCheckout'));
    }

    public function test_placing_an_order_without_the_confirm_token_is_refused(): void
    {
        $state = new ZzArrayCheckoutState();
        $state->setLines([['id' => 'ZZ-P1', 'name' => 'ZZ', 'qty' => 1, 'storeId' => '1', 'rowId' => 'r1']]);
        $customer = (object) ['id' => 7];

        $result = (new CheckoutService($state))->placeOrder(new CheckoutContext(customer: $customer, customerId: 7));

        $this->assertSame(PlaceOrderResult::TOKEN_MISSING, $result->status);
        $this->assertNull($result->orderId);
    }

    public function test_quantities_are_summed_per_product_across_attribute_lines(): void
    {
        $service = new CheckoutService(new ZzArrayCheckoutState());
        $lines = [
            ['id' => 'P1', 'name' => 'a', 'qty' => 2, 'rowId' => 'r1'],
            ['id' => 'P1', 'name' => 'a', 'qty' => 3, 'rowId' => 'r2', 'options' => ['color' => 'red']],
            ['id' => 'P2', 'name' => 'b', 'qty' => 1, 'rowId' => 'r3'],
        ];

        $this->assertEquals(['P1' => 5, 'P2' => 1], $service->qtyByProduct($lines));
        $this->assertSame([], $service->minimumShortfall([]));
    }

    public function test_a_line_with_a_non_positive_quantity_is_flagged(): void
    {
        $service = new CheckoutService(new ZzArrayCheckoutState());

        $this->assertFalse($service->hasInvalidQuantity([['id' => 'P1', 'name' => 'a', 'qty' => 1]]));
        $this->assertTrue($service->hasInvalidQuantity([['id' => 'P1', 'name' => 'a', 'qty' => 1], ['id' => 'P2', 'name' => 'b', 'qty' => -1]]));
    }

    public function test_a_guest_gets_an_empty_default_address_with_every_field(): void
    {
        $address = (new CheckoutService(new ZzArrayCheckoutState()))->defaultAddress(null);

        $this->assertSame(
            ['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'postcode', 'company', 'email', 'city', 'district', 'ward',
                'address1', 'address2', 'address3', 'country', 'phone', 'comment'],
            array_keys($address)
        );
        $this->assertSame([''], array_values(array_unique($address)));
    }

    public function test_completing_without_an_order_id_reports_no_order(): void
    {
        $this->assertNull((new CheckoutService(new ZzArrayCheckoutState()))->complete());
    }
}

/**
 * In-memory CheckoutState double — the shape the REST API will use.
 */
class ZzArrayCheckoutState implements CheckoutState
{
    private array $data = [];

    public function storeId() { return $this->data['storeCheckout'] ?? null; }
    public function setStoreId($storeId): void { $this->data['storeCheckout'] = $storeId; }
    public function lines() { return $this->data['dataCheckout'] ?? null; }
    public function setLines($lines): void { $this->data['dataCheckout'] = $lines; }
    public function shippingAddress() { return $this->data['shippingAddress'] ?? null; }
    public function setShippingAddress(array $address): void { $this->data['shippingAddress'] = $address; }
    public function addressProcess() { return $this->data['address_process'] ?? null; }
    public function setAddressProcess($value): void { $this->data['address_process'] = $value; }
    public function forgetAddressProcess(): void { unset($this->data['address_process']); }
    public function shippingMethod() { return $this->data['shippingMethod'] ?? null; }
    public function setShippingMethod($key): void { $this->data['shippingMethod'] = $key; }
    public function paymentMethod() { return $this->data['paymentMethod'] ?? null; }
    public function setPaymentMethod($key): void { $this->data['paymentMethod'] = $key; }
    public function totalMethods(): array { return (array) ($this->data['totalMethod'] ?? []); }
    public function totals() { return $this->data['dataTotal'] ?? null; }
    public function setTotals($totals): void { $this->data['dataTotal'] = $totals; }
    public function token() { return $this->data['checkoutToken'] ?? null; }
    public function ensureToken(): string { return $this->data['checkoutToken'] ??= 'zz-token'; }
    public function setToken(string $token): void { $this->data['checkoutToken'] = $token; }
    public function forgetToken(): void { unset($this->data['checkoutToken']); }
    public function step(): string { return (string) ($this->data['step'] ?? ''); }
    public function markConfirmStep(): void { $this->data['step'] = 'checkout.confirm'; }
    public function orderId() { return $this->data['orderID'] ?? null; }
    public function setOrderId($orderId): void { $this->data['orderID'] = $orderId; }
    public function setOrderSnapshot(array $dataOrder, array $cartDetail): void { $this->data['dataOrder'] = $dataOrder; }
    public function clear(): void { $this->data = array_intersect_key($this->data, array_flip(['shippingAddress', 'step', 'address_process'])); }
}
