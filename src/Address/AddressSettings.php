<?php

namespace GP247\Shop\Address;

use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;

/**
 * Single source of what the Address settings tab must tell the shop owner before the
 * address plugin of a country works as configured: fields mapped to a component but
 * switched off, a child component whose parent is not mapped, a hidden country field
 * while several countries have address data, and the active template still printing its
 * own address inputs.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-readiness-check
 * @aidlc-adr admin-shell_config-form-readiness-seam
 */
final class AddressSettings
{
    /** Storefront views that carry an address form; a template copy of one may predate the shared block. */
    private const ADDRESS_VIEWS = [
        'auth/shop_register.blade.php',
        'account/shop_change_infomation.blade.php',
        'account/shop_update_address.blade.php',
        'livewire/shop_checkout-wizard.blade.php',
    ];

    /**
     * @param string $storeId Store whose settings are checked (GLOBAL for the base).
     * @param string $country ISO alpha-2 code.
     * @return array<int, ReadinessCheck>
     */
    public static function readiness(string $storeId, string $country): array
    {
        $checks = [];
        [$providerKey, $schema] = AddressForm::make()->providerFor($storeId, $country);
        if ($providerKey === null || $schema === null) {
            return [ReadinessCheck::warning('provider', (string) gp247_language_render('admin.address_mapping.no_provider', ['country' => $country]))];
        }
        $customerUrl = function_exists('gp247_route_admin') ? gp247_route_admin('admin_config.index') : '';
        $mapping = (new AddressMapping())->effective($storeId, $schema, $providerKey);

        $off = [];
        foreach (array_keys($mapping['fields']) as $field) {
            if (!gp247_config('customer_' . $field, $storeId)) {
                $off[] = (string) gp247_language_render('customer.' . $field);
            }
        }
        $checks[] = $off === []
            ? ReadinessCheck::ok('fields_on', (string) gp247_language_render('admin.address_mapping.fields_on'))
            : new ReadinessCheck('fields_on', ReadinessCheck::ERROR,
                (string) gp247_language_render('admin.address_mapping.fields_off', ['fields' => implode(', ', $off)]),
                $customerUrl, (string) gp247_language_render('admin.address_mapping.fix_customer_tab'));

        $orphans = (new AddressMapping())->problems($schema, $mapping['fields']);
        $checks[] = $orphans === []
            ? ReadinessCheck::ok('parents', (string) gp247_language_render('admin.address_mapping.parents_ok'))
            : new ReadinessCheck('parents', ReadinessCheck::ERROR, (string) gp247_language_render('admin.address_mapping.parent_not_mapped'));

        $countries = AddressProviderRegistry::instance()->countries($storeId);
        if (!gp247_config('customer_country', $storeId) && count($countries) > 1) {
            $checks[] = ReadinessCheck::warning('country_hidden',
                (string) gp247_language_render('admin.address_mapping.country_hidden'), $customerUrl,
                (string) gp247_language_render('admin.address_mapping.fix_customer_tab'));
        }

        $stale = self::staleTemplateViews($storeId);
        if ($stale !== []) {
            $checks[] = ReadinessCheck::warning('template',
                (string) gp247_language_render('admin.address_mapping.template_stale', ['views' => implode(', ', $stale)]));
        }

        return $checks;
    }

    /**
     * Address views the store's template overrides without the shared address block.
     *
     * @param string $storeId Store.
     * @return array<int, string> Relative view paths.
     */
    public static function staleTemplateViews(string $storeId): array
    {
        $template = function_exists('gp247_store_info') ? (string) gp247_store_info('template', null, $storeId) : '';
        // WHY: the name becomes part of a path; a template key is one folder name.
        if ($template === '' || preg_match('/^[A-Za-z0-9_\-]+$/', $template) !== 1) {
            return [];
        }
        $root = app_path('GP247/Templates/' . $template);
        $stale = [];
        foreach (self::ADDRESS_VIEWS as $view) {
            $path = $root . '/' . $view;
            if (is_file($path) && !str_contains((string) file_get_contents($path), 'shop_address_fields')) {
                $stale[] = $view;
            }
        }

        return $stale;
    }
}
