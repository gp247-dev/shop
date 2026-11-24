<?php

namespace GP247\Shop\Address\View;

use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\Data\AddressField;
use GP247\Shop\Address\Data\AddressFieldSet;

/**
 * Data of one shared address block (storefront common/shop_address_fields, admin
 * partials/address-fields): the fields to print, their first values and the configuration
 * handed to the Alpine factory window.gp247Address(). Keeps the Blade views free of logic,
 * so a template overriding the block only deals with markup.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-dependent-select
 * @aidlc-story US-TPL-address-block-contract
 * @aidlc-adr shop-address_shared-form-block
 */
final class AddressBlock
{
    public const MODE_FORM = 'form';
    public const MODE_LIVEWIRE = 'livewire';
    /** The block reads / writes the state of an enclosing Alpine component (property named by $wire['model']). */
    public const MODE_ALPINE = 'alpine';

    /**
     * @param AddressFieldSet       $set       Fields of the form (AddressForm::resolve()).
     * @param string                $mode      MODE_FORM (inputs carry name / <field>_code) or MODE_LIVEWIRE.
     * @param array<string, mixed>  $values    Form mode: first values, field => value (old input wins).
     * @param array<string, string> $codes     Form mode: first codes, field => code.
     * @param array<string, string> $wire      Livewire mode: field => component property path (e.g. 'form.city').
     * @param string|null           $codesPath Livewire mode: property holding the codes array (e.g. 'addressCodes').
     */
    public function __construct(
        public readonly AddressFieldSet $set,
        public readonly string $mode = self::MODE_FORM,
        private array $values = [],
        private array $codes = [],
        private array $wire = [],
        private ?string $codesPath = null,
    ) {
    }

    /**
     * Builds the block for a store / country / context in one call.
     *
     * @param string|null           $country   Country of the address (null = the shop's default rules).
     * @param string                $context   AddressForm::CONTEXT_*.
     * @param string                $mode      MODE_FORM or MODE_LIVEWIRE.
     * @param array<string, mixed>  $values    Form mode first values.
     * @param array<string, string> $codes     Form mode first codes.
     * @param array<string, string> $wire      Livewire property paths.
     * @param string|null           $codesPath Livewire codes property.
     * @param string|null           $storeId   Store; null = the current store.
     * @param mixed                 $meta      Stored `address_meta` of the address being edited: its codes
     *                                         seed the fields it feeds (form mode, when $codes is empty).
     * @return self
     */
    public static function for(
        ?string $country,
        string $context,
        string $mode = self::MODE_FORM,
        array $values = [],
        array $codes = [],
        array $wire = [],
        ?string $codesPath = null,
        ?string $storeId = null,
        mixed $meta = null,
    ): self {
        $set = AddressForm::make()->resolve($storeId, $country, $context);
        if ($codes === [] && $meta !== null) {
            $codes = self::codesFromMeta($set, $meta);
        }

        return new self($set, $mode, $values, $codes, $wire, $codesPath);
    }

    /**
     * Codes of the fields a stored `address_meta` feeds, for the provider and country of the set.
     *
     * @param AddressFieldSet $set  Fields of the form.
     * @param mixed           $meta Stored value (array or null).
     * @return array<string, string> Field => code.
     */
    public static function codesFromMeta(AddressFieldSet $set, mixed $meta): array
    {
        $meta = \GP247\Shop\Address\Data\AddressMeta::fromArray($meta);
        if ($meta === null || $meta->provider !== $set->providerKey || $meta->country !== $set->country) {
            return [];
        }
        $codes = [];
        foreach ($set->mapped() as $name => $field) {
            if (isset($meta->components[(string) $field->component])) {
                $codes[$name] = $meta->components[(string) $field->component]['code'];
            }
        }

        return $codes;
    }

    /**
     * @return array<string, AddressField> Fields to print, in display order (country included when shown).
     */
    public function fields(): array
    {
        return $this->set->visible();
    }

    /**
     * @param string $name Field name.
     * @return string First value of a field (form mode; old input wins).
     */
    public function value(string $name): string
    {
        $value = old($name, $this->values[$name] ?? '');

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param string $name Field name.
     * @return string First code of a field (form mode; old input wins).
     */
    public function code(string $name): string
    {
        $code = old($name . '_code', $this->codes[$name] ?? '');

        return is_scalar($code) ? (string) $code : '';
    }

    /**
     * @return bool True in Livewire mode.
     */
    public function livewire(): bool
    {
        return $this->mode === self::MODE_LIVEWIRE;
    }

    /**
     * Configuration of window.gp247Address(): only public data (field names, inputs, URLs,
     * the values already printed in the page).
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $fields = [];
        $values = [];
        $codes = [];
        foreach ($this->fields() as $name => $field) {
            if ($name === 'country') {
                continue;
            }
            $fields[] = $field->toArray() + ['default_label' => $this->defaultLabel($name)];
            if (!$this->livewire()) {
                $values[$name] = $this->value($name);
                $codes[$name] = $this->code($name);
            }
        }
        if (!$this->livewire() && $this->set->shows('country')) {
            $values['country'] = $this->value('country');
        }

        return [
            'mode' => $this->mode,
            'context' => $this->set->context,
            'country' => (string) ($this->set->country ?? ''),
            'countryField' => $this->set->shows('country'),
            'provider' => $this->set->providerKey,
            'fields' => $fields,
            'values' => $values,
            'codes' => $codes,
            'wire' => $this->mode === self::MODE_LIVEWIRE ? $this->wire : [],
            'model' => $this->mode === self::MODE_ALPINE ? (string) ($this->wire['model'] ?? '') : null,
            'codesPath' => $this->codesPath,
            // Language of the page: the endpoints answer in it (their URLs carry no language prefix).
            'locale' => app()->getLocale(),
            'urls' => [
                'schema' => self::url('address.schema'),
                'options' => self::url('address.options'),
                'lookup' => self::url('address.lookup'),
            ],
        ];
    }

    /**
     * @param string $name Field name.
     * @return string Label of the field when no plugin feeds it (restored after a country change).
     */
    private function defaultLabel(string $name): string
    {
        $group = in_array($this->set->context, [AddressForm::CONTEXT_CHECKOUT, AddressForm::CONTEXT_API], true)
            ? 'cart'
            : ($this->set->context === AddressForm::CONTEXT_ADMIN_ORDER ? 'order' : 'customer');

        return (string) gp247_language_render($group . '.' . $name);
    }

    /**
     * @param string $route Route name.
     * @return string Relative URL of an address endpoint ('' when the route is not registered).
     */
    private static function url(string $route): string
    {
        return \Illuminate\Support\Facades\Route::has($route) ? route($route, [], false) : '';
    }
}
