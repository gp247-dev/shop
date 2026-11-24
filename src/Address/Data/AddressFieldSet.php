<?php

namespace GP247\Shop\Address\Data;

/**
 * The address fields of one form: which are shown, in what order, how each is entered
 * and the validation rules that go with them.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-unified-field-rules
 */
final readonly class AddressFieldSet
{
    /** Longest component code a form may submit. */
    public const CODE_MAX = 64;

    /** @var array<string, AddressField> Fields keyed by name, in display order. */
    public array $fields;

    /**
     * @param string|null                  $storeId     Store the set was resolved for (null = current store).
     * @param string|null                  $country     ISO alpha-2 code the set was resolved for.
     * @param string                       $context     AddressForm::CONTEXT_*.
     * @param string|null                  $providerKey Plugin key serving the country; null = free text.
     * @param bool                         $strict      Whether a value outside the provider's list is refused.
     * @param string                       $dataVersion Version of the provider's data set ('' without a provider).
     * @param array<int, AddressField>     $fields      Fields.
     * @param array<string, string>        $rules       Validation rules of the shown fields.
     */
    public function __construct(
        public ?string $storeId,
        public ?string $country,
        public string $context,
        public ?string $providerKey,
        public bool $strict,
        public string $dataVersion,
        array $fields,
        private array $rules,
    ) {
        usort($fields, static fn (AddressField $a, AddressField $b): int => $a->order <=> $b->order);
        $byName = [];
        foreach ($fields as $field) {
            $byName[$field->name] = $field;
        }
        $this->fields = $byName;
    }

    /**
     * @return bool True when a provider serves the country.
     */
    public function hasProvider(): bool
    {
        return $this->providerKey !== null;
    }

    /**
     * @param string $name Field name.
     * @return AddressField|null
     */
    public function field(string $name): ?AddressField
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * @param string $name Field name.
     * @return bool True when the field is enabled for the store.
     */
    public function shows(string $name): bool
    {
        return isset($this->fields[$name]) && $this->fields[$name]->show;
    }

    /**
     * @return array<string, AddressField> Enabled fields, in display order.
     */
    public function visible(): array
    {
        return array_filter($this->fields, static fn (AddressField $field): bool => $field->show);
    }

    /**
     * @return array<string, AddressField> Enabled fields fed by a provider component.
     */
    public function mapped(): array
    {
        return array_filter($this->fields, static fn (AddressField $field): bool => $field->show && $field->isMapped());
    }

    /**
     * Validation rules of the enabled address fields, keyed by field name.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Rules of some fields, in the order asked — fields that are not shown are left out.
     *
     * @param array<int, string> $names Field names.
     * @return array<string, string>
     */
    public function rulesFor(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (isset($this->rules[$name])) {
                $out[$name] = $this->rules[$name];
            }
        }

        return $out;
    }

    /**
     * Rules of the `<field>_code` companions a form may send for fields fed by a
     * provider component. Kept apart from rules(): the codes are not columns, so a
     * caller that stores "everything validated" must not receive them.
     *
     * @return array<string, string>
     */
    public function codeRules(): array
    {
        $out = [];
        foreach ($this->mapped() as $field) {
            if ($field->input !== AddressField::INPUT_LOOKUP) {
                $out[$field->name . '_code'] = 'nullable|string|max:' . self::CODE_MAX;
            }
        }

        return $out;
    }

    /**
     * @param string $name Field name.
     * @return string|null The rule of one field, or null when it is not shown.
     */
    public function rule(string $name): ?string
    {
        return $this->rules[$name] ?? null;
    }

    /**
     * @return array<string, mixed> Shape served by the address/schema endpoint.
     */
    public function toArray(): array
    {
        return [
            'country' => $this->country,
            'provider' => $this->providerKey,
            'strict' => $this->strict,
            'fields' => array_values(array_map(static fn (AddressField $field): array => $field->toArray(), $this->visible())),
        ];
    }
}
