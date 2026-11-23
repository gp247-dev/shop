<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Core\Models\AdminConfig;

/**
 * Toggle a `product_config_attribute` gate (e.g. product_stock, product_brand)
 * within a test's DatabaseTransactions scope, mirroring ConfiguresQtyDecimal.
 * Uses updateOrCreate because the real database may not have seeded the key, and
 * resets AdminConfig's static caches so gp247_config() reflects the change
 * immediately.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-field-config-scope
 * @aidlc-adr shop-admin_product-field-config-scope
 */
trait ConfiguresProductGate
{
    /**
     * Set a product_config_attribute gate to on/off and reset the config cache.
     *
     * @param string $key     product_config_attribute key (e.g. 'product_stock').
     * @param bool   $enabled Whether the gated field is enabled (shown/validated/persisted).
     * @return void
     */
    private function setProductGate(string $key, bool $enabled): void
    {
        AdminConfig::updateOrCreate(
            [
                'key' => $key,
                'store_id' => defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0,
            ],
            [
                'group' => 'gp247_cart',
                'code' => 'product_config',
                'value' => $enabled ? '1' : '0',
                'sort' => '25',
                'detail' => 'admin.product.config_manager.' . $key,
            ]
        );

        $this->resetProductGateCache();
    }

    /**
     * Reset AdminConfig's static caches so a fresh DB read occurs.
     *
     * @return void
     */
    private function resetProductGateCache(): void
    {
        // WHY: getAllConfigOfStore uses array_key_exists() so must stay an array;
        // getAllGlobal checks `=== null`. Reset each to its cache-miss sentinel.
        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }
}
