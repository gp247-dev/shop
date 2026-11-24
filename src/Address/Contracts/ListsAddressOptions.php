<?php

namespace GP247\Shop\Address\Contracts;

use GP247\Shop\Address\Data\AddressOption;

/**
 * Capability: the provider can list the choices of a component, optionally limited
 * to one parent (province → wards). Declared as AddressProvider::CAP_OPTIONS.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-dependent-select
 * @aidlc-adr shop-address_provider-capability-contract
 */
interface ListsAddressOptions
{
    /**
     * @param string      $country    ISO alpha-2 code, upper case.
     * @param string      $component  Component key from the provider's schema.
     * @param string|null $parentCode Code of the selected parent; null for a root component.
     * @param string      $locale     Language of the names.
     * @return array<int, AddressOption> Choices in display order.
     */
    public function options(string $country, string $component, ?string $parentCode, string $locale): array;
}
