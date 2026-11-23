<?php

namespace GP247\Shop\Tests\Feature\ShopShipping\Support;

use GP247\Shop\Shipping\Contracts\ShippingProvider;
use GP247\Shop\Shipping\ShippingProviderRegistry;

/**
 * Registry whose enabled providers are given by the test, so the shipping contract
 * can be exercised without installing plugins on disk. Capabilities still come from
 * `gp247-config.shop.shipping_providers` ∩ implemented interfaces (real logic).
 */
class FakeShippingRegistry extends ShippingProviderRegistry
{
    /**
     * @param array<string, ShippingProvider> $providers pluginKey => provider instance.
     */
    public function __construct(private array $providers)
    {
    }

    /**
     * @param array<string, ShippingProvider> $providers pluginKey => provider instance.
     * @return self The registry, bound in the container.
     */
    public static function bind(array $providers): self
    {
        $registry = new self($providers);
        app()->instance(ShippingProviderRegistry::class, $registry);
        foreach ($providers as $key => $provider) {
            if (config('gp247-config.shop.shipping_providers.' . $key) === null) {
                config(['gp247-config.shop.shipping_providers.' . $key => ['driver' => get_class($provider), 'capabilities' => ['rates']]]);
            }
        }

        return $registry;
    }

    /**
     * @return array<int, string>
     */
    public function enabledKeys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @param string $key Plugin key.
     * @return ShippingProvider|null
     */
    public function provider(string $key): ?ShippingProvider
    {
        return $this->providers[$key] ?? null;
    }
}
