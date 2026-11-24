<?php

namespace GP247\Shop\Address;

use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Contracts\ListsAddressOptions;
use GP247\Shop\Address\Contracts\ResolvesPostcode;

/**
 * Resolves address providers: which plugins registered a driver
 * (`gp247-config.shop.address_providers`), which of them are enabled for a store,
 * which countries each serves and what it can really do (declared capabilities ∩
 * implemented interfaces). A broken driver is skipped and reported once per request.
 *
 * Resolved from the container, so tests can bind a registry with fake providers.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-provider-contract
 * @aidlc-adr shop-address_provider-capability-contract
 */
class AddressProviderRegistry
{
    /** @var array<string, class-string> Capability => interface it requires. */
    private const CAPABILITY_INTERFACES = [
        AddressProvider::CAP_OPTIONS => ListsAddressOptions::class,
        AddressProvider::CAP_POSTCODE => ResolvesPostcode::class,
    ];

    /** @var array<string, AddressProvider|null> */
    private array $instances = [];

    /** @var array<string, array<int, string>> Store id => active plugin keys. */
    private array $active = [];

    /** @var array<string, bool> */
    private array $reported = [];

    /**
     * @return AddressProviderRegistry The container-bound registry.
     */
    public static function instance(): self
    {
        return app(self::class);
    }

    /**
     * Registry entries, keyed by plugin key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return array_filter((array) config('gp247-config.shop.address_providers', []), 'is_array');
    }

    /**
     * Registered plugin keys whose plugin is installed, switched on and enabled for
     * the store.
     *
     * @param string|null $storeId Store to check; null = the effective store.
     * @return array<int, string>
     */
    public function activeKeys(?string $storeId = null): array
    {
        if ($this->definitions() === [] || !function_exists('gp247_extension_check_active')) {
            return [];
        }
        $storeId = $storeId ?? (function_exists('gp247_plugin_store_id') ? (string) gp247_plugin_store_id() : '');
        if (!isset($this->active[$storeId])) {
            $keys = [];
            foreach (array_keys($this->definitions()) as $key) {
                $key = (string) $key;
                if (!gp247_extension_check_active('Plugins', $key)) {
                    continue;
                }
                if (function_exists('gp247_plugin_store_enabled') && !gp247_plugin_store_enabled($key, $storeId)) {
                    continue;
                }
                $keys[] = $key;
            }
            $this->active[$storeId] = $keys;
        }

        return $this->active[$storeId];
    }

    /**
     * The driver of a plugin key.
     *
     * @param string $key Plugin key.
     * @return AddressProvider|null Null when the driver is missing or not an AddressProvider.
     */
    public function provider(string $key): ?AddressProvider
    {
        if (array_key_exists($key, $this->instances)) {
            return $this->instances[$key];
        }
        $driver = (string) ($this->definitions()[$key]['driver'] ?? '');
        $provider = null;
        try {
            $candidate = ($driver !== '' && class_exists($driver)) ? app($driver) : null;
            $provider = $candidate instanceof AddressProvider ? $candidate : null;
        } catch (\Throwable $e) {
            $provider = null;
        }
        if ($provider === null) {
            $this->reportOnce($key, '[GP247 address] provider "' . $key . '" registers driver "' . $driver . '" that is missing or not an AddressProvider — skipped.');
        }

        return $this->instances[$key] = $provider;
    }

    /**
     * Effective capabilities: declared in the registry AND backed by the interface.
     *
     * @param string $key Plugin key.
     * @return array<int, string> Subset of AddressProvider::CAP_*.
     */
    public function capabilities(string $key): array
    {
        $provider = $this->provider($key);
        if ($provider === null) {
            return [];
        }
        $effective = [];
        foreach (array_unique((array) ($this->definitions()[$key]['capabilities'] ?? [])) as $capability) {
            $interface = self::CAPABILITY_INTERFACES[$capability] ?? null;
            if ($interface !== null && $provider instanceof $interface) {
                $effective[] = (string) $capability;
            } elseif ($interface !== null) {
                $this->reportOnce($key . ':' . $capability, '[GP247 address] provider "' . $key . '" declares "' . $capability . '" but does not implement ' . $interface . ' — capability ignored.');
            }
        }

        return $effective;
    }

    /**
     * @param string $key        Plugin key.
     * @param string $capability AddressProvider::CAP_*.
     * @return bool
     */
    public function supports(string $key, string $capability): bool
    {
        return in_array($capability, $this->capabilities($key), true);
    }

    /**
     * The plugin serving a country for a store. When several do, the preferred one
     * wins if it is among them; otherwise the first key in alphabetical order.
     *
     * @param string      $country   ISO alpha-2 code.
     * @param string|null $storeId   Store; null = the effective store.
     * @param string|null $preferred Plugin key chosen in the country's mapping.
     * @return string|null
     */
    public function keyForCountry(string $country, ?string $storeId = null, ?string $preferred = null): ?string
    {
        $country = strtoupper(trim($country));
        $keys = [];
        foreach ($this->activeKeys($storeId) as $key) {
            if (in_array($country, $this->countriesOf($key), true) && $this->provider($key) !== null) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            return null;
        }
        if ($preferred !== null && in_array($preferred, $keys, true)) {
            return $preferred;
        }
        sort($keys, SORT_STRING);

        return $keys[0];
    }

    /**
     * Countries served by at least one working provider enabled for the store.
     *
     * @param string|null $storeId Store; null = the effective store.
     * @return array<int, string> ISO alpha-2 codes, sorted.
     */
    public function countries(?string $storeId = null): array
    {
        $countries = [];
        foreach ($this->activeKeys($storeId) as $key) {
            if ($this->provider($key) !== null) {
                $countries = array_merge($countries, $this->countriesOf($key));
            }
        }
        $countries = array_values(array_unique($countries));
        sort($countries, SORT_STRING);

        return $countries;
    }

    /**
     * @param string $key Plugin key.
     * @return array<int, string> Upper-case ISO alpha-2 codes the plugin declares.
     */
    public function countriesOf(string $key): array
    {
        $countries = [];
        foreach ((array) ($this->definitions()[$key]['countries'] ?? []) as $country) {
            if (is_string($country) && trim($country) !== '') {
                $countries[] = strtoupper(trim($country));
            }
        }

        return $countries;
    }

    /**
     * Reports a provider problem once per request.
     *
     * @param string $key     De-duplication key.
     * @param string $message Report text.
     * @return void
     */
    public function reportOnce(string $key, string $message): void
    {
        if (isset($this->reported[$key])) {
            return;
        }
        $this->reported[$key] = true;
        if (function_exists('gp247_report')) {
            gp247_report($message);
        }
    }
}
