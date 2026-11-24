<?php

namespace GP247\Shop\Address;

use GP247\Shop\Address\Data\AddressSchema;

/**
 * The "shop field ← provider component" table of a country: what the shop owner saved
 * for the store (GLOBAL row inherited by stores), or the provider's default.
 *
 * Whether a field is shown or required is NOT stored here — that stays with the
 * `customer_<field>` switches; the mapping only says where a field's value comes from.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-field-mapping
 * @aidlc-adr shop-address_names-plus-meta-storage
 */
class AddressMapping
{
    /** Fields a component may feed. `country` is not one: it selects the provider. */
    public const FIELDS = ['postcode', 'city', 'district', 'ward', 'address1', 'address2', 'address3'];

    /**
     * @param string $country ISO alpha-2 code.
     * @return string The admin_config key holding the country's mapping.
     */
    public static function configKey(string $country): string
    {
        return 'address_mapping_' . strtoupper(trim($country));
    }

    /**
     * The mapping saved for a store and a country.
     *
     * @param string|null $storeId Store; null = the current store.
     * @param string      $country ISO alpha-2 code.
     * @return array<string, mixed>|null Null when nothing valid is saved.
     */
    public function stored(?string $storeId, string $country): ?array
    {
        $raw = gp247_config(self::configKey($country), $storeId);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Checks a mapping the shop owner is about to save against the provider's schema.
     *
     * @param AddressSchema                                           $schema Schema of the provider in use.
     * @param array<string, array{component?: string, editable?: bool}> $fields Field => chosen component ('' = typed by hand).
     * @return array<string, string> Field => language key of the problem (empty = valid).
     */
    public function problems(AddressSchema $schema, array $fields): array
    {
        $problems = [];
        $used = [];
        $chosen = [];
        foreach ($fields as $field => $row) {
            $component = (string) ($row['component'] ?? '');
            if ($component === '') {
                continue;
            }
            if (!in_array($field, self::FIELDS, true) || $schema->component($component) === null) {
                $problems[(string) $field] = 'admin.address_mapping.unknown_component';
                continue;
            }
            if (isset($used[$component])) {
                $problems[(string) $field] = 'admin.address_mapping.component_used_twice';
                continue;
            }
            $used[$component] = (string) $field;
            $chosen[(string) $field] = $component;
        }
        foreach ($chosen as $field => $component) {
            $parent = $schema->component($component)->parent;
            if ($parent !== null && !isset($used[$parent])) {
                $problems[$field] = 'admin.address_mapping.parent_not_mapped';
            }
        }

        return $problems;
    }

    /**
     * Saves the mapping of a country for a store scope (GLOBAL or a store override).
     *
     * @param string                                                  $storeId     Scope to write.
     * @param AddressSchema                                           $schema      Schema of the provider in use.
     * @param string                                                  $providerKey Plugin key of the provider in use.
     * @param array<string, array{component?: string, editable?: bool}> $fields      Field => row.
     * @param bool                                                    $strict      Refuse values outside the list.
     * @return void
     * @throws \InvalidArgumentException When problems() is not empty.
     */
    public function save(string $storeId, AddressSchema $schema, string $providerKey, array $fields, bool $strict): void
    {
        if ($this->problems($schema, $fields) !== []) {
            throw new \InvalidArgumentException('Invalid address mapping.');
        }
        $rows = [];
        foreach (self::FIELDS as $field) {
            $component = (string) ($fields[$field]['component'] ?? '');
            if ($component !== '') {
                $rows[$field] = ['component' => $component, 'editable' => (bool) ($fields[$field]['editable'] ?? false)];
            }
        }
        \GP247\Core\Models\AdminConfig::setConfigValue(
            'gp247_address',
            self::configKey($schema->country),
            $storeId,
            (string) json_encode(['provider' => $providerKey, 'strict' => $strict, 'fields' => $rows], JSON_UNESCAPED_UNICODE)
        );
        \GP247\Core\Models\AdminConfig::forgetMemo();
    }

    /**
     * Removes the saved mapping of a country for a store scope: the scope then inherits
     * (a store: the GLOBAL mapping; GLOBAL: the provider's default).
     *
     * @param string $storeId Scope.
     * @param string $country ISO alpha-2 code.
     * @return void
     */
    public function forget(string $storeId, string $country): void
    {
        \GP247\Core\Models\AdminConfig::where('key', self::configKey($country))->where('store_id', $storeId)->delete();
        \GP247\Core\Models\AdminConfig::forgetMemo();
    }

    /**
     * The mapping in force: the saved one when it belongs to the provider in use,
     * otherwise the provider's default. Entries naming an unknown field, an unknown
     * component or a component already used are dropped.
     *
     * @param string|null   $storeId     Store; null = the current store.
     * @param AddressSchema $schema      Schema of the provider in use.
     * @param string        $providerKey Plugin key of the provider in use.
     * @return array{provider: string, strict: bool, fields: array<string, array{component: string, editable: bool}>}
     */
    public function effective(?string $storeId, AddressSchema $schema, string $providerKey): array
    {
        $stored = $this->stored($storeId, $schema->country);
        $usable = $stored !== null
            && is_array($stored['fields'] ?? null)
            && in_array((string) ($stored['provider'] ?? $providerKey), ['', $providerKey], true);

        $rows = [];
        if ($usable) {
            foreach ($stored['fields'] as $field => $row) {
                $rows[(string) $field] = [
                    'component' => (string) (is_array($row) ? ($row['component'] ?? '') : $row),
                    'editable' => is_array($row) && array_key_exists('editable', $row)
                        ? (bool) $row['editable']
                        : in_array($field, $schema->defaultEditable, true),
                ];
            }
        } else {
            foreach ($schema->defaultMapping as $field => $component) {
                $rows[(string) $field] = [
                    'component' => (string) $component,
                    'editable' => in_array($field, $schema->defaultEditable, true),
                ];
            }
        }

        $fields = [];
        $used = [];
        foreach ($rows as $field => $row) {
            if (!in_array($field, self::FIELDS, true) || $schema->component($row['component']) === null || isset($used[$row['component']])) {
                continue;
            }
            $used[$row['component']] = true;
            $fields[$field] = $row;
        }

        return [
            'provider' => $providerKey,
            'strict' => $usable ? (bool) ($stored['strict'] ?? true) : true,
            'fields' => $fields,
        ];
    }
}
