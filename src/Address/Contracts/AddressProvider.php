<?php

namespace GP247\Shop\Address\Contracts;

use GP247\Shop\Address\Data\AddressSchema;
use GP247\Shop\Address\Data\NormalizedAddress;

/**
 * Address data source of one or more countries, supplied by a plugin.
 *
 * A plugin registers its driver in Provider.php:
 *
 *   config(['gp247-config.shop.address_providers.<PluginKey>' => [
 *       'driver' => Driver::class,
 *       'countries' => ['VN'],
 *       'capabilities' => [AddressProvider::CAP_OPTIONS],
 *   ]]);
 *
 * The shop owns the form, the validation, the storage and the fallback to free text;
 * the provider only describes its components and answers lookups. It never receives
 * the shopper's name, phone or email. A provider that calls a remote service must use
 * a short HTTP timeout (config `gp247-config.shop.address.timeout`, seconds): every
 * call is wrapped by the shop, and a failure turns the field into a text input.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-provider-contract
 * @aidlc-adr shop-address_provider-capability-contract
 */
interface AddressProvider
{
    /** Capability: dependent selects (requires ListsAddressOptions). */
    public const CAP_OPTIONS = 'options';

    /** Capability: postcode lookup (requires ResolvesPostcode). */
    public const CAP_POSTCODE = 'postcode';

    /**
     * Components this provider offers for a country, their parent links and the
     * default mapping onto the shop's address fields.
     *
     * @param string $country ISO 3166-1 alpha-2 code, upper case.
     * @return AddressSchema
     */
    public function schema(string $country): AddressSchema;

    /**
     * Checks submitted components against the provider's data: each code exists,
     * belongs to its parent and to the country. A component given by name only is
     * matched by name. Components that cannot be matched are left out of the result
     * (optionally with an entry in `errors`).
     *
     * @param string                                                  $country    ISO alpha-2 code, upper case.
     * @param array<string, array{code: ?string, name: ?string}>     $components Component key => submitted value.
     * @param string                                                  $locale     Language of the names to return.
     * @return NormalizedAddress Matched components with their canonical code and name.
     */
    public function normalize(string $country, array $components, string $locale): NormalizedAddress;
}
