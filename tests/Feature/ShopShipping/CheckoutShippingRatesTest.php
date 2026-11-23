<?php

/**
 * @aidlc-story US-shop-shipping-checkout-rates
 * @aidlc-story US-shop-shipping-rate-snapshot
 * @aidlc-story US-LW-006
 */

namespace GP247\Shop\Tests\Feature\ShopShipping;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Front\Livewire\CheckoutWizard;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Shipping\Data\Money;
use GP247\Shop\Shipping\Data\RateSnapshot;
use GP247\Shop\Shipping\Data\ShippingRate;
use GP247\Shop\Shipping\RateService;
use GP247\Shop\Shipping\ShippingCheckout;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeCarrier;
use GP247\Shop\Tests\Feature\ShopShipping\Support\FakeShippingRegistry;
use GP247\Shop\Tests\TestCase;

/**
 * At checkout the shopper sees the services of every carrier priced for the address
 * they just typed, and the price they pick is the price billed — even if the carrier
 * would answer differently a minute later.
 */
#[Group('shop-shipping')]
class CheckoutShippingRatesTest extends TestCase
{
    use DatabaseTransactions;

    private FakeCarrier $carrier;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setGlobalConfig('use_shipping', '1');
        $this->setGlobalConfig('customer_ward', '1');
        session()->forget(['shippingMethod', ShippingCheckout::SESSION_RATE, ShippingCheckout::SESSION_REQUEST, RateService::SESSION_QUOTES, RateService::SESSION_DOWN]);
        $code = (string) (gp247_currency_code() ?: 'USD');
        $this->carrier = FakeCarrier::withServices(['road' => 12, 'fly' => 20], 'MultiFake', $code);
        FakeShippingRegistry::bind(['MultiFake' => $this->carrier]);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetConfigCache();
        parent::tearDown();
    }

    public function test_leaving_the_address_step_quotes_every_service_for_the_address_being_typed(): void
    {
        $component = $this->addressFilled()->call('nextStep');

        $component->assertSet('step', 'shipping');
        $this->assertSame(['MultiFake:road', 'MultiFake:fly'], array_keys($component->get('shippingRates')));
        $this->assertSame('Đà Nẵng', $this->carrier->lastRequest->destination->province);
        $this->assertSame('Phường Hải Châu 1', $this->carrier->lastRequest->destination->ward);
        $component->assertSee('data-testid="checkout-shipping-rate-MultiFake:fly"', false);
    }

    public function test_the_chosen_rate_is_billed_even_if_the_carrier_later_quotes_another_price(): void
    {
        $component = $this->addressFilled()->call('nextStep')->call('selectShipping', 'MultiFake:fly');

        $this->assertSame('MultiFake', session('shippingMethod'), 'session keeps the plugin key');
        $component->assertSet('shippingMethod', 'MultiFake:fly');
        $chosen = ShippingCheckout::snapshot();
        $this->assertSame('fly', $chosen->serviceCode);

        $this->carrier->rates = [new ShippingRate('MultiFake', 'fly', 'Fake fly', new Money('99', $chosen->providerAmount->currency))];

        $line = ShopOrderTotal::getShippingMethod();
        $this->assertEqualsWithDelta($chosen->displayAmount, $line['value'], 0.0001);
        $this->assertSame(1, $this->carrier->rateCalls, 'no carrier call between choosing and billing');
    }

    public function test_an_expired_price_is_quoted_again_before_billing(): void
    {
        $this->addressFilled()->call('nextStep')->call('selectShipping', 'MultiFake:road');
        $old = ShippingCheckout::snapshot()->toArray();
        $old['expires_at'] = time() - 1;
        session([ShippingCheckout::SESSION_RATE => $old]);
        $this->carrier->rates = [new ShippingRate('MultiFake', 'road', 'Fake road', new Money('15', $old['provider_amount']['currency']))];
        $this->carrier->rates[] = new ShippingRate('MultiFake', 'fly', 'Fake fly', new Money('20', $old['provider_amount']['currency']));

        $fresh = ShippingCheckout::resolveForTotals('MultiFake');

        $this->assertSame('15', $fresh->providerAmount->amount);
        $this->assertFalse($fresh->isExpired());
    }

    public function test_an_address_no_carrier_serves_shows_a_message_instead_of_an_empty_choice(): void
    {
        $this->carrier->rates = [];

        $this->addressFilled()->call('nextStep')
            ->assertSet('shippingRates', [])
            ->assertSee('data-testid="checkout-shipping-rate-error"', false);
    }

    public function test_a_new_checkout_forgets_the_previous_rate(): void
    {
        $this->addressFilled()->call('nextStep')->call('selectShipping', 'MultiFake:road');
        $this->assertInstanceOf(RateSnapshot::class, ShippingCheckout::snapshot());

        ShippingCheckout::forget();

        $this->assertNull(ShippingCheckout::snapshot());
    }

    /**
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function addressFilled()
    {
        return Livewire::test(CheckoutWizard::class)
            ->set('first_name', 'An')->set('last_name', 'Nguyen')
            ->set('first_name_kana', 'An')->set('last_name_kana', 'Nguyen')
            ->set('email', 'an@example.com')->set('phone', '0905000000')
            ->set('city', 'Đà Nẵng')->set('district', '')->set('ward', 'Phường Hải Châu 1')
            ->set('address1', '12 Bạch Đằng')->set('address2', 'A')->set('address3', 'B')
            ->set('country', 'VN')->set('postcode', '50000')->set('company', 'Co');
    }

    /**
     * @param string $key   Config key.
     * @param string $value Value.
     * @return void
     */
    private function setGlobalConfig(string $key, string $value): void
    {
        $global = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0';
        $root = (string) config('app.storeId', defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
        AdminConfig::where('key', $key)->whereIn('store_id', [$global, $root])->update(['value' => $value]);
        if (!AdminConfig::where('key', $key)->exists()) {
            AdminConfig::insert(['group' => 'gp247_cart', 'code' => 'test', 'key' => $key, 'value' => $value, 'sort' => 0, 'detail' => '', 'store_id' => $global]);
        }
        $this->resetConfigCache();
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
