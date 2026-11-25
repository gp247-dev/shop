<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `shop_customer.last_login_at`: when the customer last signed in (website or app), so an account
 * unused for the retention period can be found. Stays NULL until the next sign-in; the retention
 * item counts such accounts from the day it was turned on, never from before.
 *
 * Idempotent and add-only: a nullable column, guarded by hasColumn; no existing row is read,
 * changed or removed. A fresh install already has the column from the create-tables migration.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_inactive-accounts
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_customer';
        if ($schema->hasTable($table) && !$schema->hasColumn($table, 'last_login_at')) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->timestamp('last_login_at')->nullable());
        }
    }

    /**
     * WARNING — data loss: dropping the column discards the sign-in times recorded since up() ran;
     * unused accounts are then counted from their creation again. gp247 never calls this on update
     * or uninstall.
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_customer';
        if ($schema->hasTable($table) && $schema->hasColumn($table, 'last_login_at')) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('last_login_at'));
        }
    }
};
