<?php

namespace GP247\Shop\Admin\Livewire;

use GP247\Core\AdminShell\Infrastructure\GP247AdminComponent;
use GP247\Shop\Address\AddressForm;
use GP247\Shop\Address\AddressMapping;
use GP247\Shop\Address\AddressProviderRegistry;
use GP247\Shop\Address\AddressSettings;
use GP247\Shop\Address\Data\AddressComponent;
use GP247\Shop\Address\Data\AddressSchema;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Address settings tab: for each country an address plugin serves, which shop address
 * field each of the plugin's components (province, ward, postcode…) fills, whether a
 * value outside the plugin's list is refused, what customers will see, and what is still
 * missing.
 *
 * The screen is laid out per component (component → shop field) because that is how an
 * owner thinks about it; the stored mapping keeps its shape (field ← component), so rows()
 * turns one into the other and AddressMapping stays the single place of the rules.
 *
 * Same permission as the shop configuration (it is a tab of the Configuration hub, and has a
 * page of its own for the plugin's settings button). Scope: the root admin edits the GLOBAL
 * base; a store-admin edits their store's override, inherited from GLOBAL until saved.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-field-mapping
 * @aidlc-story US-shop-address-readiness-check
 */
class AddressMappingForm extends GP247AdminComponent
{
    protected ?string $permission = 'admin_shop_config';

    protected ?string $screenUri = 'gp247_admin/shop_config';

    /** @var string Country being configured (ISO alpha-2). */
    public string $country = '';

    /** @var array<string, string> Component key => shop field it fills ('' = not used). */
    public array $targets = [];

    /** @var array<string, bool> Component key => the shopper may edit the value a lookup filled. */
    public array $targetEditable = [];

    /** @var bool Refuse a value outside the plugin's list. */
    public bool $strict = true;

    /** @var string Scope written by save(): GLOBAL for the root admin, the store for a store-admin. */
    #[Locked]
    public string $scopeStoreId = '';

    /**
     * @return void
     */
    public function mount(): void
    {
        $prefix = defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
        $this->screenUri = $prefix . '/shop_config';
        parent::mount();

        $root = (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
        $session = (string) session('adminStoreId', $root);
        $this->scopeStoreId = ($session === '' || $session === $root)
            ? (string) (defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : '0')
            : $session;

        $countries = $this->countries();
        $asked = strtoupper((string) request()->query('country', ''));
        $this->country = in_array($asked, $countries, true) ? $asked : ($countries[0] ?? '');
        $this->loadMapping();
    }

    /**
     * Switches to another country.
     *
     * @param string $country ISO alpha-2 code.
     * @return void
     */
    public function selectCountry(string $country): void
    {
        $country = strtoupper($country);
        if (!in_array($country, $this->countries(), true)) {
            return;
        }
        $this->country = $country;
        $this->resetErrorBag();
        $this->loadMapping();
    }

    /**
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function save(): void
    {
        $this->authorizeAction('update');
        if (!in_array($this->country, $this->countries(), true)) {
            return;
        }
        [$providerKey, $schema] = AddressForm::make()->providerFor($this->scopeStoreId, $this->country);
        if ($providerKey === null || $schema === null) {
            $this->notify('error', (string) gp247_language_render('admin.address_mapping.no_provider', ['country' => $this->country]));

            return;
        }
        $this->resetErrorBag();
        [$rows, $rowErrors] = $this->rows($schema);
        foreach ($rowErrors as $component => $key) {
            $this->addError('targets.' . $component, (string) gp247_language_render($key));
        }
        if ($rowErrors !== []) {
            return;
        }
        $mapping = new AddressMapping();
        $problems = $mapping->problems($schema, $rows);
        if ($problems !== []) {
            foreach ($problems as $field => $key) {
                $component = (string) ($rows[$field]['component'] ?? '');
                $this->addError('targets.' . ($component !== '' ? $component : $field), (string) gp247_language_render($key));
            }

            return;
        }
        $mapping->save($this->scopeStoreId, $schema, $providerKey, $rows, $this->strict);
        $this->notify('success', (string) gp247_language_render('action.update_success'));
    }

    /**
     * Drops the saved mapping of this scope (back to the inherited one / the plugin's default).
     *
     * @return void
     * @throws \GP247\Core\AdminShell\Domain\AuthorizationException When denied.
     */
    public function resetToDefault(): void
    {
        $this->authorizeAction('update');
        // WHY: the country is a client-side property — only a country a plugin serves may be reset.
        if (!in_array($this->country, $this->countries(), true)) {
            return;
        }
        (new AddressMapping())->forget($this->scopeStoreId, $this->country);
        $this->resetErrorBag();
        $this->loadMapping();
        $this->notify('success', (string) gp247_language_render('action.update_success'));
    }

    /**
     * @return View
     */
    public function render(): View
    {
        [, $schema] = $this->country === '' ? [null, null] : AddressForm::make()->providerFor($this->scopeStoreId, $this->country);
        $components = [];
        if ($schema !== null) {
            foreach ($schema->components as $key => $component) {
                $components[$key] = [
                    'label' => (string) gp247_language_render($component->labelKey),
                    'kind' => $component->kind,
                    'parent' => $component->parent !== null && $schema->component($component->parent) !== null
                        ? (string) gp247_language_render($schema->component($component->parent)->labelKey)
                        : null,
                ];
            }
        }
        $fields = [];
        foreach (AddressMapping::FIELDS as $field) {
            $fields[$field] = [
                'label' => (string) gp247_language_render('customer.' . $field),
                'on' => (bool) gp247_config('customer_' . $field, $this->scopeStoreId),
            ];
        }

        return view('gp247-shop-admin::address-mapping', [
            'countryTabs' => $this->countryTabs(),
            'schemaComponents' => $components,
            'fields' => $fields,
            'preview' => $this->preview($fields, $components),
            'readiness' => $this->country === '' ? [] : AddressSettings::readiness($this->scopeStoreId, $this->country),
        ])->layout('gp247-admin::layouts.admin', [
            'title' => gp247_language_render('admin.address_mapping.title'),
        ]);
    }

    /**
     * @return array<int, string> Countries an address plugin serves for this scope.
     */
    private function countries(): array
    {
        return AddressProviderRegistry::instance()->countries($this->scopeStoreId === '' ? null : $this->scopeStoreId);
    }

    /**
     * @return array<int, array{code: string, name: string, plugin: string}> One button per country a plugin serves.
     */
    private function countryTabs(): array
    {
        $names = (new \GP247\Core\Models\AdminCountry())->pluck('name', 'code')->all();
        $tabs = [];
        foreach ($this->countries() as $code) {
            [$providerKey] = AddressForm::make()->providerFor($this->scopeStoreId, $code);
            $tabs[] = ['code' => $code, 'name' => (string) ($names[$code] ?? $code), 'plugin' => self::pluginTitle((string) $providerKey)];
        }

        return $tabs;
    }

    /**
     * @param string $providerKey Plugin key the provider is registered under.
     * @return string The plugin's display name, or its key when it has none.
     */
    private static function pluginTitle(string $providerKey): string
    {
        if ($providerKey === '') {
            return '';
        }
        $key = 'Plugins/' . $providerKey . '::lang.title';
        $title = trans($key);

        return is_string($title) && $title !== $key ? $title : $providerKey;
    }

    /**
     * What customers see: the switched-on fields (country first) and how each is filled.
     *
     * @param array<string, array{label: string, on: bool}>                        $fields     Shop fields.
     * @param array<string, array{label: string, kind: string, parent: ?string}>   $components Plugin components.
     * @return array<string, array{label: string, mode: string}> mode: list | lookup | filled | typed.
     */
    private function preview(array $fields, array $components): array
    {
        $preview = [];
        if ((bool) gp247_config('customer_country', $this->scopeStoreId)) {
            $preview['country'] = ['label' => (string) gp247_language_render('customer.country'), 'mode' => 'list'];
        }
        $byField = [];
        foreach ($this->targets as $component => $field) {
            if (is_string($field) && $field !== '' && isset($components[$component])) {
                $byField[$field] = $components[$component]['kind'];
            }
        }
        $modes = [AddressComponent::KIND_LIST => 'list', AddressComponent::KIND_LOOKUP => 'lookup', AddressComponent::KIND_DERIVED => 'filled'];
        foreach ($fields as $field => $info) {
            if ($info['on']) {
                $preview[$field] = ['label' => $info['label'], 'mode' => $modes[$byField[$field] ?? ''] ?? 'typed'];
            }
        }

        return $preview;
    }

    /**
     * @return void
     */
    private function loadMapping(): void
    {
        $this->targets = [];
        $this->targetEditable = [];
        $this->strict = true;
        if ($this->country === '') {
            return;
        }
        [$providerKey, $schema] = AddressForm::make()->providerFor($this->scopeStoreId, $this->country);
        if ($providerKey === null || $schema === null) {
            return;
        }
        foreach (array_keys($schema->components) as $component) {
            $this->targets[(string) $component] = '';
            $this->targetEditable[(string) $component] = false;
        }
        $mapping = (new AddressMapping())->effective($this->scopeStoreId, $schema, $providerKey);
        $this->strict = $mapping['strict'];
        foreach ($mapping['fields'] as $field => $row) {
            $this->targets[$row['component']] = (string) $field;
            $this->targetEditable[$row['component']] = (bool) $row['editable'];
        }
    }

    /**
     * Turns the per-component choices into the stored shape (field ← component).
     *
     * @param AddressSchema $schema Schema of the provider in use.
     * @return array{0: array<string, array{component: string, editable: bool}>, 1: array<string, string>}
     *         Rows by field, and errors by component (language keys).
     */
    private function rows(AddressSchema $schema): array
    {
        $rows = [];
        $errors = [];
        foreach (array_keys($schema->components) as $component) {
            $field = $this->targets[$component] ?? '';
            if (!is_string($field) || $field === '') {
                continue;
            }
            if (!in_array($field, AddressMapping::FIELDS, true)) {
                $errors[(string) $component] = 'admin.address_mapping.unknown_field';
                continue;
            }
            if (isset($rows[$field])) {
                $errors[(string) $component] = 'admin.address_mapping.field_used_twice';
                continue;
            }
            $rows[$field] = ['component' => (string) $component, 'editable' => (bool) ($this->targetEditable[$component] ?? false)];
        }

        return [$rows, $errors];
    }
}
