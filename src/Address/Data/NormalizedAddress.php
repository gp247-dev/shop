<?php

namespace GP247\Shop\Address\Data;

/**
 * Result of AddressProvider::normalize(): the components that matched the provider's
 * data, with their canonical code and name.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 */
final readonly class NormalizedAddress
{
    /**
     * @param array<string, array{code: string, name: string}> $components Matched components.
     * @param array<string, string>                            $errors     Component key => language key of the reason it did not match.
     */
    public function __construct(
        public array $components = [],
        public array $errors = [],
    ) {
    }

    /**
     * @param string $component Component key.
     * @return bool True when the component matched.
     */
    public function has(string $component): bool
    {
        return isset($this->components[$component]['code'], $this->components[$component]['name']);
    }
}
