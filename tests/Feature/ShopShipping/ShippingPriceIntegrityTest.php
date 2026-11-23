<?php

/**
 * @aidlc-story US-shop-shipping-rate-snapshot
 * @aidlc-story US-shop-shipping-checkout-rates
 */

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Shipping\RateService;
use GP247\Shop\Shipping\ShippingCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * The shopper can never decide the shipping price: rates live on the server, a price is
 * only billed for the address and cart it was quoted for, and a carrier that cannot quote
 * never turns into free shipping.
 */
#[Group('shop-shipping')]
class ShippingPriceIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private FakeCarrier $carrier;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $global = (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0');
        $root = (string) config('app.storeId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
        AdminConfig::whereIn('key', ['use_shipping', 'customer_ward'])->whereIn('store_id', [$global, $root])->update(['value' => '1']);
        $this->resetConfigCache();
        session()->forget(['shippingMethod', 'shippingAddress', ShippingCheckout::SESSION_RATE, ShippingCheckout::SESSION_REQUEST, RateService::SESSION_QUOTES, RateService::SESSION_DOWN]);
        $this->carrier = FakeCarrier::withServices(['road' => 12], 'PriceFake', (string) (gp247_currency_code() ?: 'USD'));
        FakeShippingRegistry::bind(['PriceFake' => $this->carrier]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    public function test_the_shopper_cannot_overwrite_the_quoted_rates_from_the_browser(): void
    {
        $component = $this->wizard('Đà Nẵng')->call('nextStep');
        $tampered = $component->get('shippingRates');
        $tampered['PriceFake']['display_amount'] = 0;

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $component->set('shippingRates', $tampered);
    }

    public function test_a_price_quoted_for_another_address_is_quoted_again_before_billing(): void
    {
        $this->wizard('Đà Nẵng')->call('nextStep')->call('selectShipping', 'PriceFake');
        session(['shippingAddress' => ['first_name' => 'An', 'phone' => '0905', 'city' => 'Cần Thơ', 'ward' => 'Phường 2', 'address1' => '9 Hai Bà Trưng', 'country' => 'VN']]);

        $line = ShopOrderTotal::getShippingMethod();

        $this->assertNotEmpty($line);
        $this->assertSame('Cần Thơ', $this->carrier->lastRequest->destination->province, 'billing re-quoted the committed address');
        $this->assertSame(2, $this->carrier->rateCalls);
    }

    public function test_a_carrier_that_cannot_quote_never_bills_free_shipping(): void
    {
        $this->carrier->rates = [];
        session([
            'shippingMethod' => 'PriceFake',
            'shippingAddress' => ['first_name' => 'An', 'phone' => '0905', 'city' => 'Cần Thơ', 'ward' => 'Phường 2', 'address1' => '9 Hai Bà Trưng', 'country' => 'VN'],
        ]);

        $this->assertNull(ShippingCheckout::resolveForTotals('PriceFake'));
        $this->assertTrue(ShippingCheckout::isUnquotable('PriceFake'), 'checkout must refuse the order');
        $this->assertSame([], ShopOrderTotal::getShippingMethod(), 'never the getInfo() value of a contract provider');
    }

    /**
     * @param string $city Province typed by the shopper.
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function wizard(string $city)
    {
        return Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'An')->set('last_name', 'Nguyen')->set('first_name_kana', 'An')->set('last_name_kana', 'Nguyen')
            ->set('email', 'an@example.com')->set('phone', '0905000000')
            ->set('city', $city)->set('ward', 'Phường 1')->set('address1', '12 Bạch Đằng')->set('address2', 'A')->set('address3', 'B')
            ->set('country', 'VN')->set('postcode', '50000')->set('company', 'Co');
    }

    /**
     * @return void
     */
    private function resetConfigCache(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }
}
