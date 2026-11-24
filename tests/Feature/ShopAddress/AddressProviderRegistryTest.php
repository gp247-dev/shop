<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Shop\Address\AddressProviderRegistry;
use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Data\AddressSchema;
use GP247\Shop\Address\Data\NormalizedAddress;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * A country's address data comes from a plugin that registers a driver: the registry
 * knows which countries it serves and what it can really do, and skips a broken one.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-provider-contract
 */
#[Group('shop-address')]
class AddressProviderRegistryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_registered_driver_serves_the_countries_it_declares(): void
    {
        $registry = $this->registry(['AddressVN' => ['driver' => FakeAddressProvider::class, 'countries' => ['vn'], 'capabilities' => ['options']]]);

        $this->assertSame('AddressVN', $registry->keyForCountry('VN'));
        $this->assertInstanceOf(FakeAddressProvider::class, $registry->provider('AddressVN'));
        $this->assertNull($registry->keyForCountry('JP'));
        $this->assertSame(['VN'], $registry->countries());
    }

    public function test_a_capability_counts_only_when_the_driver_implements_it(): void
    {
        $registry = $this->registry([
            'Full' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN'], 'capabilities' => ['options', 'postcode']],
            'Bare' => ['driver' => BareAddressProvider::class, 'countries' => ['US'], 'capabilities' => ['options', 'postcode']],
        ]);

        $this->assertTrue($registry->supports('Full', AddressProvider::CAP_OPTIONS));
        $this->assertTrue($registry->supports('Full', AddressProvider::CAP_POSTCODE));
        $this->assertFalse($registry->supports('Bare', AddressProvider::CAP_OPTIONS));
        $this->assertFalse($registry->supports('Bare', AddressProvider::CAP_POSTCODE));
    }

    public function test_a_capability_that_is_not_declared_is_not_used(): void
    {
        $registry = $this->registry(['Full' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN'], 'capabilities' => ['options']]]);

        $this->assertFalse($registry->supports('Full', AddressProvider::CAP_POSTCODE));
    }

    public function test_a_driver_that_is_missing_or_of_the_wrong_type_is_skipped(): void
    {
        $registry = $this->registry([
            'Ghost' => ['driver' => 'App\\No\\Such\\Driver', 'countries' => ['VN']],
            'Wrong' => ['driver' => \stdClass::class, 'countries' => ['VN']],
            'Good' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN']],
        ]);

        $this->assertNull($registry->provider('Ghost'));
        $this->assertNull($registry->provider('Wrong'));
        $this->assertSame('Good', $registry->keyForCountry('VN'));
    }

    public function test_two_plugins_for_one_country_the_preferred_one_wins_else_the_first_by_key(): void
    {
        $registry = $this->registry([
            'ZetaAddress' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN']],
            'AlphaAddress' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN']],
        ]);

        $this->assertSame('AlphaAddress', $registry->keyForCountry('VN'));
        $this->assertSame('ZetaAddress', $registry->keyForCountry('VN', null, 'ZetaAddress'));
        $this->assertSame('AlphaAddress', $registry->keyForCountry('VN', null, 'NotInstalled'));
    }

    public function test_a_plugin_that_is_not_enabled_serves_no_country(): void
    {
        config(['gp247-config.shop.address_providers' => ['AddressVN' => ['driver' => FakeAddressProvider::class, 'countries' => ['VN']]]]);

        $registry = new AddressProviderRegistry();

        $this->assertNull($registry->keyForCountry('VN'));
        $this->assertSame([], $registry->countries());
    }

    /**
     * @param array<string, array<string, mixed>> $definitions Registry entries, all treated as enabled.
     * @return AddressProviderRegistry
     */
    private function registry(array $definitions): AddressProviderRegistry
    {
        config(['gp247-config.shop.address_providers' => $definitions]);

        return new class extends AddressProviderRegistry {
            /**
             * {@inheritDoc}
             */
            public function activeKeys(?string $storeId = null): array
            {
                return array_keys((array) config('gp247-config.shop.address_providers', []));
            }
        };
    }
}

/**
 * Driver with the mandatory contract only.
 */
class BareAddressProvider implements AddressProvider
{
    /**
     * {@inheritDoc}
     */
    public function schema(string $country): AddressSchema
    {
        return new AddressSchema($country, '1', []);
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(string $country, array $components, string $locale): NormalizedAddress
    {
        return new NormalizedAddress();
    }
}
