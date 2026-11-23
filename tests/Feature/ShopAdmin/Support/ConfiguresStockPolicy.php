<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Core\Models\AdminConfig;

/**
 * Toggle the two Shop Config keys that drive order stock policy
 * (US-SADM-order-stock-parity) within a test's
 * DatabaseTransactions scope: `product_buy_out_of_stock` (order_config — allow
 * ordering beyond stock) and `product_stock` (product_config_attribute — whether
 * the shop tracks stock at all). Uses updateOrCreate + a static-cache reset so
 * gp247_config() reflects the change immediately, mirroring ConfiguresQtyDecimal.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-stock-parity
 * @aidlc-adr shop-admin_order-stock-parity
 */
trait ConfiguresStockPolicy
{
    /**
     * Set `product_buy_out_of_stock` (1 = allow ordering beyond stock).
     *
     * @param bool $allow
     * @return void
     */
    private function setBuyOutOfStock(bool $allow): void
    {
        $this->putStockConfig('order_config', 'product_buy_out_of_stock', $allow ? '1' : '0');
    }

    /**
     * Set `product_stock` (1 = the shop tracks stock; 0 = stock not managed).
     *
     * @param bool $enabled
     * @return void
     */
    private function setProductStock(bool $enabled): void
    {
        $this->putStockConfig('product_config_attribute', 'product_stock', $enabled ? '1' : '0');
    }

    /**
     * Upsert a global Shop Config row and reset the static config cache.
     *
     * @param string $code  Config code group (order_config|product_config_attribute).
     * @param string $key   Config key.
     * @param string $value Config value.
     * @return void
     */
    private function putStockConfig(string $code, string $key, string $value): void
    {
        AdminConfig::updateOrCreate(
            [
                'key' => $key,
                'store_id' => defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0,
            ],
            [
                'group' => 'gp247_cart',
                'code' => $code,
                'value' => $value,
                'sort' => '0',
                'detail' => '',
            ]
        );

        $this->resetStockConfigCache();
    }

    /**
     * Reset AdminConfig's in-memory caches so gp247_config() re-reads the DB.
     * WHY: DatabaseTransactions rolls back the row but not the static cache.
     *
     * @return void
     */
    private function resetStockConfigCache(): void
    {
        // WHY: the two caches guard re-init differently — getAllGlobal checks
        // `=== null`, but getAllConfigOfStore does array_key_exists() on the
        // property, so it must stay an array (null would TypeError). Reset each to
        // the "empty" that forces a fresh DB read.
        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }
}
