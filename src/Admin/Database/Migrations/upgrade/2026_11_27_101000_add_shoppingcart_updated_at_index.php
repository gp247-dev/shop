<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index on `shop_shoppingcart.updated_at`: carts, wishlists and compare lists past their
 * retention period are deleted by age a slice at a time, which without it reads the whole table
 * on every slice.
 *
 * Idempotent and add-only: guarded by hasIndex; no row is read, changed or removed. A fresh
 * install already has the index from the create-tables migration.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-personal-data-retention-policies
 * @aidlc-adr personal-data-retention_sweep-runner
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_shoppingcart';
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'updated_at') && !$schema->hasIndex($table, ['updated_at'])) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->index('updated_at'));
        }
    }

    /**
     * Drops only the index (no data is lost).
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_shoppingcart';
        if ($schema->hasTable($table) && $schema->hasIndex($table, ['updated_at'])) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex(['updated_at']));
        }
    }
};
