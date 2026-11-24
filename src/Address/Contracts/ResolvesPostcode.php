<?php

namespace GP247\Shop\Address\Contracts;

use GP247\Shop\Address\Data\AddressSuggestion;

/**
 * Capability: the provider can turn a postcode into address components (prefecture,
 * city, town…). Declared as AddressProvider::CAP_POSTCODE.
 *
 * Experimental until a country plugin ships with it: the signature may gain optional
 * parameters in a minor release.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-postcode-lookup
 * @aidlc-adr shop-address_provider-capability-contract
 */
interface ResolvesPostcode
{
    /**
     * @param string $country  ISO alpha-2 code, upper case.
     * @param string $postcode Postcode as typed by the shopper.
     * @param string $locale   Language of the names.
     * @return array<int, AddressSuggestion> Zero, one or several matching addresses.
     */
    public function lookup(string $country, string $postcode, string $locale): array;
}
