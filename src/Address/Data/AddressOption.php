<?php

namespace GP247\Shop\Address\Data;

/**
 * One choice of a list component.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-dependent-select
 */
final readonly class AddressOption
{
    /**
     * @param string $code Stable code, the same in every language.
     * @param string $name Display name in the requested language.
     */
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }

    /**
     * @return array{code: string, name: string}
     */
    public function toArray(): array
    {
        return ['code' => $this->code, 'name' => $this->name];
    }
}
