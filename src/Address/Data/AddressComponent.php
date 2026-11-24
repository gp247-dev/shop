<?php

namespace GP247\Shop\Address\Data;

/**
 * One level of address data a provider defines for a country (province, ward,
 * prefecture, postcode…).
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-provider-contract
 */
final readonly class AddressComponent
{
    /** The component has a list of choices (rendered as a select). */
    public const KIND_LIST = 'list';

    /** The component is a lookup key the shopper types (a postcode). */
    public const KIND_LOOKUP = 'lookup';

    /** The component only comes out of a lookup result (rendered as a prefilled input). */
    public const KIND_DERIVED = 'derived';

    /**
     * @param string      $key      Stable key, unique in the schema (e.g. "province").
     * @param string      $labelKey Language key of the field label.
     * @param string      $kind     One of the KIND_* constants.
     * @param string|null $parent   Key of the parent component, or null for a root one.
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $kind = self::KIND_LIST,
        public ?string $parent = null,
    ) {
    }
}
