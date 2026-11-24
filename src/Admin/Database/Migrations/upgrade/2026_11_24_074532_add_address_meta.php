<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `address_meta` on the three address blocks (shop_order, shop_customer,
 * shop_customer_address): the codes of an address chosen from an address plugin's
 * data, stored as JSON text next to the name columns (same storage as
 * shop_order.shipping_rate). Stays NULL on a shop without an address plugin.
 *
 * Idempotent and add-only: a nullable column, guarded by hasColumn; no existing row is
 * read, changed or removed. A fresh install already has the column from the
 * create-tables migration. On a very large shop_order table run it out of peak hours.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-address-meta-schema
 * @aidlc-adr shop-address_names-plus-meta-storage
 */
return new class extends Migration
{
    /** @var string[] Tables carrying the denormalized customer address block. */
    private const TABLES = ['shop_order', 'shop_customer', 'shop_customer_address'];

    /**
     * Add the column where it is missing.
     *
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        foreach (self::TABLES as $name) {
            $table = GP247_DB_PREFIX . $name;
            if ($schema->hasTable($table) && !$schema->hasColumn($table, 'address_meta')) {
                $schema->table($table, function (Blueprint $blueprint) {
                    $blueprint->text('address_meta')->nullable()->comment('JSON AddressMeta: codes of the address components');
                });
            }
        }
    }

    /**
     * WARNING — data loss: dropping the column discards the address codes saved since
     * up() ran (the address names in the other columns are untouched). gp247 never
     * calls this on update or uninstall.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        foreach (self::TABLES as $name) {
            $table = GP247_DB_PREFIX . $name;
            if ($schema->hasTable($table) && $schema->hasColumn($table, 'address_meta')) {
                $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('address_meta'));
            }
        }
    }
};
