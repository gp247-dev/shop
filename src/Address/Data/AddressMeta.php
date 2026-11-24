<?php

namespace GP247\Shop\Address\Data;

/**
 * Codes of the address components that matched a provider's data — the value stored
 * in the `address_meta` column next to the name columns.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-adr shop-address_names-plus-meta-storage
 */
final readonly class AddressMeta
{
    /**
     * @param string                                           $provider    Plugin key that matched the address.
     * @param string                                           $country     ISO alpha-2 code.
     * @param string                                           $dataVersion Version of the provider's data set.
     * @param array<string, array{code: string, name: string}> $components  Matched components.
     */
    public function __construct(
        public string $provider,
        public string $country,
        public string $dataVersion,
        public array $components,
    ) {
    }

    /**
     * @return array{provider: string, country: string, data_version: string, components: array<string, array{code: string, name: string}>}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'country' => $this->country,
            'data_version' => $this->dataVersion,
            'components' => $this->components,
        ];
    }

    /**
     * Reads a stored value; tolerates missing keys and non-array input.
     *
     * @param mixed $value Decoded column value.
     * @return self|null Null when the value holds no component.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!is_array($value) || empty($value['components']) || !is_array($value['components'])) {
            return null;
        }
        $components = [];
        foreach ($value['components'] as $key => $component) {
            if (is_array($component) && isset($component['code'])) {
                $components[(string) $key] = [
                    'code' => (string) $component['code'],
                    'name' => (string) ($component['name'] ?? ''),
                ];
            }
        }
        if ($components === []) {
            return null;
        }

        return new self(
            (string) ($value['provider'] ?? ''),
            (string) ($value['country'] ?? ''),
            (string) ($value['data_version'] ?? ''),
            $components,
        );
    }
}
