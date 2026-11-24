<?php

namespace GP247\Shop\Tests\Feature\ShopAddress\Support;

use GP247\Core\Models\AdminConfig;

/**
 * Sets the customer address switches (and address mapping rows) for the test, at the
 * GLOBAL and ROOT scopes, and drops the per-request config memo.
 */
trait ConfiguresAddressFields
{
    /** Every address switch off except address1 — the state of a fresh install. */
    protected const ADDRESS_DEFAULTS = [
        'customer_city' => '0', 'customer_city_required' => '0',
        'customer_district' => '0', 'customer_district_required' => '0',
        'customer_ward' => '0', 'customer_ward_required' => '0',
        'customer_address1' => '1', 'customer_address1_required' => '1',
        'customer_address2' => '0', 'customer_address2_required' => '0',
        'customer_address3' => '0', 'customer_address3_required' => '0',
        'customer_country' => '0', 'customer_country_required' => '0',
        'customer_postcode' => '0', 'customer_postcode_required' => '0',
    ];

    /**
     * @param array<string, string> $values Config key => value, on top of ADDRESS_DEFAULTS.
     * @return void
     */
    protected function configureAddress(array $values = []): void
    {
        foreach ($values + self::ADDRESS_DEFAULTS as $key => $value) {
            $rows = AdminConfig::where('key', $key)->whereIn('store_id', [(string) GP247_STORE_ID_GLOBAL, $this->rootStore()]);
            // WHY exists(): update() counts changed rows, 0 when the value is already set.
            if ((clone $rows)->exists()) {
                $rows->update(['value' => $value]);
            } else {
                AdminConfig::setConfigValue('gp247_address', $key, (string) GP247_STORE_ID_GLOBAL, (string) $value);
            }
        }
        AdminConfig::forgetMemo();
    }

    /**
     * Drops the static config memo after each test: the rows are rolled back with the
     * transaction, the memo is not, and would leak into the next test class.
     *
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\After]
    protected function forgetAddressConfigMemo(): void
    {
        AdminConfig::forgetMemo();
    }

    /**
     * @return string Id of the root store.
     */
    protected function rootStore(): string
    {
        return (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
    }
}
