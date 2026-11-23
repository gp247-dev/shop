<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Core\Models\AdminConfig;

/**
 * Toggle the `product_qty_decimal` Shop Config (ADR-016) within a test's DatabaseTransactions scope. Uses updateOrCreate
 * because the real database may not have been seeded with this new key yet
 * (unlike product_kind, which predates it). Resets AdminConfig's
 * static cache so gp247_config() reflects the change immediately — same caveat
 * as product_kind.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-005
 * @aidlc-adr ADR-016
 */
trait ConfiguresQtyDecimal
{
    /**
     * Reset AdminConfig's static cache before each test. WHY: DatabaseTransactions
     * rolls back the DB row but NOT this in-memory cache, so a prior test (in the
     * same PHPUnit process) that left it populated with a stale value would
     * otherwise leak into a later test that never calls setQtyDecimalEnabled()
     * itself (e.g. asserting the "not configured yet" default).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetQtyDecimalCache();
    }

    /**
     * Set `product_qty_decimal` to the given value and reset the static config cache.
     *
     * @param bool $enabled
     * @return void
     */
    private function setQtyDecimalEnabled(bool $enabled): void
    {
        AdminConfig::updateOrCreate(
            [
                'key' => 'product_qty_decimal',
                'store_id' => defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0,
            ],
            [
                'group' => 'gp247_cart',
                'code' => 'product_config',
                'value' => $enabled ? '1' : '0',
                'sort' => '25',
                'detail' => 'admin.product.config_manager.product_qty_decimal',
            ]
        );

        $this->resetQtyDecimalCache();
    }

    /**
     * @return void
     */
    private function resetQtyDecimalCache(): void
    {
        // WHY: the two caches guard re-init differently — getAllGlobal checks
        // `=== null`, but getAllConfigOfStore does array_key_exists() on the
        // property, so it must stay an array (null would TypeError at render).
        // Reset each to the "empty" that forces a fresh DB read.
        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }
}
