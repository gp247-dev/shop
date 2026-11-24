<?php

namespace GP247\Shop\Tests\Feature\ShopAddress\Support;

use GP247\Shop\Address\AddressProviderRegistry;
use GP247\Shop\Address\Contracts\AddressProvider;

/**
 * Registry whose active providers are given by the test, so the address contract can
 * be exercised without installing a plugin on disk. Countries and capabilities still
 * come from `gp247-config.shop.address_providers` ∩ implemented interfaces.
 */
class FakeAddressRegistry extends AddressProviderRegistry
{
    /**
     * @param array<string, AddressProvider> $providers pluginKey => provider instance.
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * @param array<string, AddressProvider>         $providers pluginKey => provider instance.
     * @param array<string, array<int, string>>      $countries pluginKey => countries (default VN + JP).
     * @return self The registry, bound in the container.
     */
    public static function bind(array $providers, array $countries = []): self
    {
        $registry = new self($providers);
        app()->instance(AddressProviderRegistry::class, $registry);
        config(['gp247-config.shop.address_providers' => []]);
        foreach ($providers as $key => $provider) {
            config(['gp247-config.shop.address_providers.' . $key => [
                'driver' => get_class($provider),
                'countries' => $countries[$key] ?? ['VN', 'JP'],
                'capabilities' => [AddressProvider::CAP_OPTIONS, AddressProvider::CAP_POSTCODE],
            ]]);
        }

        return $registry;
    }

    /**
     * {@inheritDoc}
     */
    public function activeKeys(?string $storeId = null): array
    {
        return array_keys($this->providers);
    }

    /**
     * {@inheritDoc}
     */
    public function provider(string $key): ?AddressProvider
    {
        return $this->providers[$key] ?? null;
    }
}
