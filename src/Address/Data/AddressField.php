<?php

namespace GP247\Shop\Address\Data;

/**
 * One address field as a form must show it for a store, a country and a context.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-unified-field-rules
 */
final readonly class AddressField
{
    public const INPUT_TEXT = 'text';
    public const INPUT_SELECT = 'select';
    public const INPUT_LOOKUP = 'lookup';

    /**
     * @param string      $name        Column / input name (city, ward, address1…).
     * @param bool        $show        Whether the field is enabled for the store.
     * @param bool        $required    Whether a value is required.
     * @param string      $label       Rendered label.
     * @param string      $input       One of the INPUT_* constants.
     * @param string|null $component   Provider component feeding the field; null = typed by hand.
     * @param string|null $parentField Field whose value limits this field's choices.
     * @param bool        $editable    Whether a prefilled value may be edited.
     * @param int         $order       Display position.
     */
    public function __construct(
        public string $name,
        public bool $show,
        public bool $required,
        public string $label,
        public string $input = self::INPUT_TEXT,
        public ?string $component = null,
        public ?string $parentField = null,
        public bool $editable = true,
        public int $order = 0,
    ) {
    }

    /**
     * @return bool True when the value comes from a provider component.
     */
    public function isMapped(): bool
    {
        return $this->component !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'show' => $this->show,
            'required' => $this->required,
            'label' => $this->label,
            'input' => $this->input,
            'component' => $this->component,
            'parent_field' => $this->parentField,
            'editable' => $this->editable,
        ];
    }
}
