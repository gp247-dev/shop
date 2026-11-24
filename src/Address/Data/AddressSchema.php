<?php

namespace GP247\Shop\Address\Data;

/**
 * What a provider offers for one country: its components and the mapping it suggests
 * onto the shop's address fields.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-provider-contract
 * @aidlc-story US-shop-address-field-mapping
 */
final readonly class AddressSchema
{
    /** @var array<string, AddressComponent> Components keyed by their key. */
    public array $components;

    /**
     * @param string                        $country         ISO alpha-2 code, upper case.
     * @param string                        $dataVersion     Version of the provider's data set (e.g. "2025-07").
     * @param array<int, AddressComponent>  $components      Components, parents first.
     * @param array<string, string>         $defaultMapping  Shop field => component key.
     * @param array<int, string>            $defaultEditable Shop fields the shopper may edit after a lookup filled them.
     */
    public function __construct(
        public string $country,
        public string $dataVersion,
        array $components,
        public array $defaultMapping = [],
        public array $defaultEditable = [],
    ) {
        $byKey = [];
        foreach ($components as $component) {
            if ($component instanceof AddressComponent) {
                $byKey[$component->key] = $component;
            }
        }
        $this->components = $byKey;
    }

    /**
     * @param string $key Component key.
     * @return AddressComponent|null
     */
    public function component(string $key): ?AddressComponent
    {
        return $this->components[$key] ?? null;
    }
}
