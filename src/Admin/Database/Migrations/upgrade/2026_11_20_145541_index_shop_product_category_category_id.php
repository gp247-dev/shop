<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index on (category_id, product_id) of the product–category join table. Listing the products of
 * a category filters this table by category_id, which its primary key (product_id, category_id)
 * cannot serve, so large catalogs read the whole table. Idempotent and add-only: no data is
 * touched, a missing table or an index already there is left as it is. MySQL / MariaDB build it
 * online (InnoDB); run it out of peak hours on a very large shop.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-product-category-lookup-index
 * @aidlc-adr compat-foundation_public-release-migration-policy
 */
return new class extends Migration
{
    /** @var string[] Columns of the lookup index, in order. */
    private const COLUMNS = ['category_id', 'product_id'];

    /**
     * Add the lookup index when the table exists and does not have it yet.
     *
     * @return void
     */
    public function up()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_product_category';
        if ($schema->hasTable($table) && !$schema->hasIndex($table, self::COLUMNS)) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->index(self::COLUMNS));
        }
    }

    /**
     * Drops only the index added here (no data is touched; the primary key stays).
     *
     * @return void
     */
    public function down()
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'shop_product_category';
        if ($schema->hasTable($table) && $schema->hasIndex($table, self::COLUMNS)) {
            $schema->table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex(self::COLUMNS));
        }
    }
};
