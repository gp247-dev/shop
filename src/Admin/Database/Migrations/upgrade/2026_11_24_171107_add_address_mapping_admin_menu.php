<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data-only upgrade: add the "Address lists" link to the shop settings block of the admin
 * sidebar, so the screen that decides which address field each country's address plugin
 * fills is found next to the other shop settings, not only as a Configuration tab or by its
 * URL. A fresh install gets the same row from DataShopInitializeSeeder.
 *
 * The menu title label comes from the shop language seeder (gp247:shop-update runs it), not
 * from here. Access follows the screen's URI like every menu row, so the link only shows to
 * an admin who may open it.
 *
 * Idempotent and non-destructive: inserted only when no row has this uri; skipped when the
 * shop settings block is missing (unexpected), rather than leaving an orphan row.
 *
 * No cron/queue (NFR-AVAIL-001).
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-field-mapping
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $menu = GP247_DB_PREFIX . 'admin_menu';
        $db = DB::connection(GP247_DB_CONNECTION);
        $uri = 'admin::address_mapping';

        $parentId = $db->table($menu)->where('key', 'ADMIN_SHOP_SETTING')->value('id');
        if ($parentId === null || $db->table($menu)->where('uri', $uri)->exists()) {
            return;
        }
        $db->table($menu)->insert([
            'parent_id' => $parentId,
            // WHY 30: the sidebar lists a block's items by descending sort, so 30 shows right
            // after Tax (40), as in the seeder.
            'sort' => 30,
            'title' => 'admin.menu_titles.address_mapping',
            'icon' => 'fas fa-map-marker-alt',
            'uri' => $uri,
            'type' => 0,
            'hidden' => 0,
            'key' => null,
        ]);
    }

    /**
     * No rollback: removing the link would only hide a working screen (data-preserving, like
     * the other upgrade migrations).
     *
     * @return void
     */
    public function down()
    {
        // Intentionally empty — see method doc.
    }
};
