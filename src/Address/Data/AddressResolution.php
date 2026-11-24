<?php

namespace GP247\Shop\Address\Data;

/**
 * Outcome of normalising a submitted address: the values to store, the codes that go
 * with them and the fields that were refused.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 */
final readonly class AddressResolution
{
    /**
     * @param array<string, string>     $values Field name => canonical name to store instead of the submitted one.
     * @param array<string, mixed>|null $meta   AddressMeta::toArray(), or null when nothing matched.
     * @param array<string, string>     $errors Field name => rendered message.
     */
    public function __construct(
        public array $values = [],
        public ?array $meta = null,
        public array $errors = [],
    ) {
    }

    /**
     * @return bool True when no field was refused.
     */
    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * Applies the outcome to the data about to be stored: canonical names replace the
     * submitted ones and `address_meta` is set (or cleared).
     *
     * @param array<string, mixed> $data Address data, already validated.
     * @return array<string, mixed> A copy with the canonical values.
     */
    public function applyTo(array $data): array
    {
        foreach ($this->values as $field => $value) {
            $data[$field] = $value;
        }
        $data['address_meta'] = $this->meta;

        return $data;
    }
}
