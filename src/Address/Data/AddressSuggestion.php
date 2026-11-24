<?php

namespace GP247\Shop\Address\Data;

/**
 * One address found for a postcode.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-postcode-lookup
 */
final readonly class AddressSuggestion
{
    /**
     * @param array<string, array{code?: ?string, name: string}> $components Component key => value.
     */
    public function __construct(
        public array $components,
    ) {
    }

    /**
     * @return array{components: array<string, array{code: ?string, name: string}>}
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this->components as $key => $value) {
            $out[(string) $key] = [
                'code' => isset($value['code']) ? (string) $value['code'] : null,
                'name' => (string) ($value['name'] ?? ''),
            ];
        }

        return ['components' => $out];
    }
}
