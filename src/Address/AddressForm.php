<?php

namespace GP247\Shop\Address;

use GP247\Core\Models\AdminCountry;
use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Data\AddressComponent;
use GP247\Shop\Address\Data\AddressField;
use GP247\Shop\Address\Data\AddressFieldSet;
use GP247\Shop\Address\Data\AddressSchema;

/**
 * Single source of the address fields of every form (storefront, admin, API): for a
 * store, a country and a context it says which fields show, which are required, in
 * what order, how each is entered and which validation rule applies.
 *
 * Shown / required come from the `customer_<field>` switches. When a plugin serves
 * the country, the country's mapping turns fields into dependent selects, a postcode
 * lookup or prefilled inputs. Without a plugin — or when the plugin fails — every
 * field is a text input with the rules the forms always had.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-unified-field-rules
 * @aidlc-story US-shop-address-free-text-fallback
 * @aidlc-adr shop-address_provider-capability-contract
 */
class AddressForm
{
    public const CONTEXT_CHECKOUT = 'checkout';
    public const CONTEXT_CUSTOMER_INSERT = 'customer_insert';
    public const CONTEXT_CUSTOMER_EDIT = 'customer_edit';
    public const CONTEXT_ADDRESS_BOOK = 'address_book';
    public const CONTEXT_ADMIN_CUSTOMER = 'admin_customer';
    public const CONTEXT_ADMIN_ORDER = 'admin_order';
    public const CONTEXT_API = 'api';

    /** Canonical display order when no plugin serves the country. */
    public const FIELD_ORDER = ['city', 'district', 'ward', 'address1', 'address2', 'address3', 'country', 'postcode'];

    /** Contexts whose form always carries the main address line. */
    private const ALWAYS_SHOW_ADDRESS1 = [
        self::CONTEXT_CUSTOMER_INSERT, self::CONTEXT_CUSTOMER_EDIT, self::CONTEXT_ADDRESS_BOOK, self::CONTEXT_ADMIN_CUSTOMER,
    ];

    /** Language group of the default labels, per context. */
    private const LABEL_GROUP = [
        self::CONTEXT_CHECKOUT => 'cart', self::CONTEXT_API => 'cart', self::CONTEXT_ADMIN_ORDER => 'order',
    ];

    /** @var array<string, AddressSchema|null> "<providerKey>|<country>" => schema. */
    private array $schemas = [];

    /** @var array<int, string>|null */
    private ?array $countryCodes = null;

    /**
     * @return AddressForm The container-bound resolver.
     */
    public static function make(): self
    {
        return app(self::class);
    }

    /**
     * @param string|null $storeId Store whose settings apply; null = the current store.
     * @param string|null $country ISO alpha-2 code of the address being entered.
     * @param string      $context One of the CONTEXT_* constants.
     * @return AddressFieldSet
     */
    public function resolve(?string $storeId, ?string $country, string $context): AddressFieldSet
    {
        $country = $this->cleanCountry($country);
        $switches = $this->switches($storeId, $context);
        $registry = AddressProviderRegistry::instance();
        $served = $registry->countries($storeId);

        // WHY: a shop that hides the country field sells to one country; when exactly one
        // country has address data, that is the country of every address. A country sent
        // anyway is ignored — otherwise posting an unserved one would switch the checks off.
        if (!$switches['country']['show']) {
            $country = count($served) === 1 ? $served[0] : null;
        }

        [$providerKey, $schema] = $country === null ? [null, null] : $this->providerFor($storeId, $country);
        $mapping = $schema === null
            ? ['strict' => false, 'fields' => []]
            : (new AddressMapping())->effective($storeId, $schema, (string) $providerKey);
        $componentOf = $schema === null ? [] : $this->usableComponents($mapping['fields'], $switches, $schema);
        $fieldOf = array_flip($componentOf);

        $postcodeFirst = $switches['postcode']['show'] && $this->looksUpPostcodes($storeId, $served, $registry);
        $fields = [];
        $rules = [];
        foreach (self::FIELD_ORDER as $position => $name) {
            $component = isset($componentOf[$name]) ? $schema->component($componentOf[$name]) : null;
            $order = match (true) {
                $name === 'country' && $served !== [] => -2,
                $name === 'postcode' && $postcodeFirst => -1,
                default => $position,
            };
            $fields[] = new AddressField(
                name: $name,
                show: $switches[$name]['show'],
                required: $switches[$name]['required'],
                label: $this->label($name, $component, $context),
                input: $this->inputOf($component, $providerKey, $registry),
                component: $component?->key,
                parentField: ($component !== null && $component->parent !== null) ? ($fieldOf[$component->parent] ?? null) : null,
                // WHY lookup always: the shopper types the postcode; "editable" only says whether a
                // field the lookup filled may be changed afterwards.
                editable: $component === null
                    || $component->kind === AddressComponent::KIND_LOOKUP
                    || ($component->kind !== AddressComponent::KIND_LIST && $mapping['fields'][$name]['editable']),
                order: $order,
            );
            if ($switches[$name]['show']) {
                $rules[$name] = $this->rule($name, $switches[$name]['required']);
            }
        }

        return new AddressFieldSet(
            storeId: $storeId,
            country: $country,
            context: $context,
            providerKey: $schema === null ? null : $providerKey,
            strict: (bool) $mapping['strict'],
            dataVersion: $schema?->dataVersion ?? '',
            fields: $fields,
            rules: $rules,
        );
    }

    /**
     * The provider and schema serving a country for a store.
     *
     * @param string|null $storeId Store; null = the current store.
     * @param string      $country ISO alpha-2 code.
     * @return array{0: string|null, 1: AddressSchema|null} Both null when no working provider serves the country.
     */
    public function providerFor(?string $storeId, string $country): array
    {
        $country = (string) $this->cleanCountry($country);
        $registry = AddressProviderRegistry::instance();
        if ($country === '' || $registry->countries($storeId) === []) {
            return [null, null];
        }
        $preferred = (new AddressMapping())->stored($storeId, $country)['provider'] ?? null;
        $key = $registry->keyForCountry($country, $storeId, is_string($preferred) ? $preferred : null);
        if ($key === null) {
            return [null, null];
        }
        $memo = $key . '|' . $country;
        if (!array_key_exists($memo, $this->schemas)) {
            $this->schemas[$memo] = $this->loadSchema($registry, $key, $country);
        }

        return $this->schemas[$memo] === null ? [null, null] : [$key, $this->schemas[$memo]];
    }

    /**
     * @param AddressProviderRegistry $registry Registry.
     * @param string                  $key      Plugin key.
     * @param string                  $country  ISO alpha-2 code.
     * @return AddressSchema|null Null when the provider fails or answers for another country.
     */
    private function loadSchema(AddressProviderRegistry $registry, string $key, string $country): ?AddressSchema
    {
        try {
            $schema = $registry->provider($key)?->schema($country);
        } catch (\Throwable $e) {
            $registry->reportOnce($key . ':schema', '[GP247 address] provider "' . $key . '" failed to describe "' . $country . '" (' . $e->getMessage() . ') — the form falls back to free text.');

            return null;
        }

        return ($schema instanceof AddressSchema && $schema->components !== []) ? $schema : null;
    }

    /**
     * Shown / required state of every address field.
     *
     * @param string|null $storeId Store; null = the current store.
     * @param string      $context One of the CONTEXT_* constants.
     * @return array<string, array{show: bool, required: bool}>
     */
    private function switches(?string $storeId, string $context): array
    {
        $out = [];
        foreach (self::FIELD_ORDER as $name) {
            $show = (bool) gp247_config('customer_' . $name, $storeId);
            $required = (bool) gp247_config('customer_' . $name . '_required', $storeId);
            if ($name === 'address1') {
                $show = $show || in_array($context, self::ALWAYS_SHOW_ADDRESS1, true);
                // WHY: an address-book entry without its main line is not an address.
                $required = $required || $context === self::CONTEXT_ADDRESS_BOOK;
            }
            $out[$name] = ['show' => $show, 'required' => $show && $required];
        }

        return $out;
    }

    /**
     * Mapping entries that can be honoured: the field is shown, and a child component
     * is kept only while its parent component is fed by a shown field too.
     *
     * @param array<string, array{component: string, editable: bool}> $mapped   Field => mapping row.
     * @param array<string, array{show: bool, required: bool}>        $switches Field switches.
     * @param AddressSchema                                           $schema   Provider schema.
     * @return array<string, string> Field => component key.
     */
    private function usableComponents(array $mapped, array $switches, AddressSchema $schema): array
    {
        $out = [];
        foreach ($mapped as $field => $row) {
            if (!empty($switches[$field]['show'])) {
                $out[$field] = $row['component'];
            }
        }
        do {
            $changed = false;
            foreach ($out as $field => $key) {
                $parent = $schema->component($key)?->parent;
                if ($parent !== null && !in_array($parent, $out, true)) {
                    unset($out[$field]);
                    $changed = true;
                }
            }
        } while ($changed);

        return $out;
    }

    /**
     * @param AddressComponent|null   $component   Component feeding the field.
     * @param string|null             $providerKey Plugin key.
     * @param AddressProviderRegistry $registry    Registry.
     * @return string AddressField::INPUT_*.
     */
    /**
     * Whether a country the store serves fills its address from the postcode — then the
     * postcode goes right after the country, so the shopper types it before the fields it fills.
     * Decided for the store, not for the country picked: the form is printed once and the
     * browser cannot move a field when the shopper changes country.
     *
     * @param string|null             $storeId  Store whose settings apply.
     * @param array<int, string>      $served   Countries an address plugin serves for the store.
     * @param AddressProviderRegistry $registry Provider registry.
     * @return bool
     */
    private function looksUpPostcodes(?string $storeId, array $served, AddressProviderRegistry $registry): bool
    {
        foreach ($served as $country) {
            [$key, $schema] = $this->providerFor($storeId, (string) $country);
            if ($key === null || $schema === null || !$registry->supports((string) $key, AddressProvider::CAP_POSTCODE)) {
                continue;
            }
            $component = (new AddressMapping())->effective($storeId, $schema, (string) $key)['fields']['postcode']['component'] ?? null;
            if (is_string($component) && $schema->component($component)?->kind === AddressComponent::KIND_LOOKUP) {
                return true;
            }
        }

        return false;
    }

    private function inputOf(?AddressComponent $component, ?string $providerKey, AddressProviderRegistry $registry): string
    {
        if ($component === null || $providerKey === null) {
            return AddressField::INPUT_TEXT;
        }
        if ($component->kind === AddressComponent::KIND_LIST && $registry->supports($providerKey, AddressProvider::CAP_OPTIONS)) {
            return AddressField::INPUT_SELECT;
        }
        if ($component->kind === AddressComponent::KIND_LOOKUP && $registry->supports($providerKey, AddressProvider::CAP_POSTCODE)) {
            return AddressField::INPUT_LOOKUP;
        }

        return AddressField::INPUT_TEXT;
    }

    /**
     * @param string                $name      Field name.
     * @param AddressComponent|null $component Component feeding the field.
     * @param string                $context   Context.
     * @return string Rendered label.
     */
    private function label(string $name, ?AddressComponent $component, string $context): string
    {
        if ($component !== null) {
            $label = (string) gp247_language_render($component->labelKey);
            if ($label !== '' && $label !== $component->labelKey) {
                return $label;
            }
        }

        return (string) gp247_language_render((self::LABEL_GROUP[$context] ?? 'customer') . '.' . $name);
    }

    /**
     * Validation rule of one field — the rule strings the forms always used, still
     * overridable through `config('validation.customer.*')`.
     *
     * @param string $name     Field name.
     * @param bool   $required Whether a value is required.
     * @return string
     */
    private function rule(string $name, bool $required): string
    {
        $suffix = $required ? '_required' : '_null';
        if ($name === 'country') {
            $base = (string) config('validation.customer.country' . $suffix, ($required ? 'required' : 'nullable') . '|string|min:2');

            return $base . '|in:' . implode(',', $this->countryCodes());
        }
        if ($name === 'postcode') {
            return (string) config('validation.customer.postcode' . $suffix, ($required ? 'required' : 'nullable') . '|min:5');
        }

        return (string) config('validation.customer.' . $name . $suffix, ($required ? 'required' : 'nullable') . '|string|max:100');
    }

    /**
     * @return array<int, string> Country codes of the shop.
     */
    private function countryCodes(): array
    {
        if ($this->countryCodes === null) {
            $this->countryCodes = (new AdminCountry())->pluck('code')->toArray();
        }

        return $this->countryCodes;
    }

    /**
     * @param string|null $country Raw country value.
     * @return string|null Upper-case code, or null when empty.
     */
    private function cleanCountry(?string $country): ?string
    {
        $country = strtoupper(trim((string) $country));

        return $country === '' ? null : $country;
    }
}
