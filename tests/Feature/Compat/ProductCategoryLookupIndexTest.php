<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use GP247\Shop\Tests\TestCase;

/**
 * The product–category join table can be searched by category: it carries an index on
 * (category_id, product_id) next to its primary key (product_id, category_id), fresh installs
 * and upgraded sites alike, and the upgrade migration that adds it is safe to run again.
 *
 * The upgrade cases change indexes (DDL commits implicitly on MySQL), so this class does not
 * use DatabaseTransactions; every case leaves the table with the index in place.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-product-category-lookup-index
 */
class ProductCategoryLookupIndexTest extends TestCase
{
    /** Columns of the lookup index, in order. */
    private const LOOKUP_COLUMNS = ['category_id', 'product_id'];

    /**
     * Bring the test database to the upgraded state before each case.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->migration()->up();
    }

    /**
     * Leave the index in place whatever a case did to it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->migration()->up();
        parent::tearDown();
    }

    /**
     * The join table has the category lookup index and keeps its primary key.
     *
     * @return void
     */
    public function test_join_table_has_category_lookup_index_and_keeps_its_primary_key(): void
    {
        $indexes = $this->indexes();

        $primary = array_values(array_filter($indexes, fn (array $index) => $index['primary']));
        $this->assertCount(1, $primary);
        $this->assertSame(['product_id', 'category_id'], $primary[0]['columns']);

        $lookup = $this->lookupIndexes();
        $this->assertCount(1, $lookup, 'Exactly one (category_id, product_id) index expected.');
        $this->assertSame($this->table() . '_category_id_product_id_index', $lookup[0]['name']);
        $this->assertFalse($lookup[0]['unique'], 'The primary key already rejects duplicate pairs.');

        $this->assertCount(2, $indexes, 'No index beyond the primary key and the lookup index.');
    }

    /**
     * The upgrade migration adds the index on a site without it, runs again without error or
     * duplicate, and its rollback removes only that index.
     *
     * @return void
     */
    public function test_upgrade_migration_adds_index_once_and_runs_again_safely(): void
    {
        $migration = $this->migration();

        $migration->down();
        $this->assertCount(0, $this->lookupIndexes(), 'down() removes the lookup index.');
        $this->assertCount(1, array_filter($this->indexes(), fn (array $index) => $index['primary']), 'down() keeps the primary key.');

        $migration->up();
        $migration->up();

        $this->assertCount(1, $this->lookupIndexes());
    }

    /**
     * The upgrade migration does nothing when the join table does not exist yet.
     *
     * @return void
     */
    public function test_upgrade_migration_skips_when_join_table_is_missing(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $table = $this->table();
        $parked = $table . '_parked_' . Str::lower(Str::random(6));

        $schema->rename($table, $parked);
        try {
            $this->migration()->up();
            $this->migration()->down();
            $this->assertFalse($schema->hasTable($table), 'The migration must not create the table.');
        } finally {
            $schema->rename($parked, $table);
        }
    }

    /**
     * Filtering products by category reads the join table through the lookup index.
     *
     * @return void
     */
    public function test_filtering_products_by_category_uses_the_lookup_index(): void
    {
        $connection = DB::connection(GP247_DB_CONNECTION);
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('EXPLAIN check is written for MySQL / MariaDB.');
        }

        $category = (string) Str::uuid();
        $connection->beginTransaction();
        try {
            $rows = [];
            for ($i = 0; $i < 20; $i++) {
                $rows[] = ['product_id' => (string) Str::uuid(), 'category_id' => $i < 5 ? $category : (string) Str::uuid()];
            }
            $connection->table($this->table())->insert($rows);

            // Same lookup as ShopProduct::setCategory(): join rows filtered by category_id.
            $plan = $connection->select(
                'EXPLAIN SELECT product_id FROM ' . $this->table() . ' WHERE category_id IN (?)',
                [$category]
            );
        } finally {
            $connection->rollBack();
        }

        $this->assertSame($this->table() . '_category_id_product_id_index', $plan[0]->key ?? null);
        $this->assertNotSame('ALL', $plan[0]->type ?? null, 'The join table must not be scanned in full.');
    }

    /**
     * Prefixed name of the join table.
     *
     * @return string
     */
    private function table(): string
    {
        return GP247_DB_PREFIX . 'shop_product_category';
    }

    /**
     * Indexes of the join table as reported by the schema builder.
     *
     * @return array<int, array{name: string, columns: string[], unique: bool, primary: bool}>
     */
    private function indexes(): array
    {
        return Schema::connection(GP247_DB_CONNECTION)->getIndexes($this->table());
    }

    /**
     * Indexes of the join table whose columns are exactly (category_id, product_id).
     *
     * @return array<int, array{name: string, columns: string[], unique: bool, primary: bool}>
     */
    private function lookupIndexes(): array
    {
        return array_values(array_filter(
            $this->indexes(),
            fn (array $index) => !$index['primary'] && $index['columns'] === self::LOOKUP_COLUMNS
        ));
    }

    /**
     * Fresh instance of the upgrade migration under test.
     *
     * @return object
     */
    private function migration(): object
    {
        return include base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/'
            . '2026_11_20_145541_index_shop_product_category_category_id.php'
        );
    }
}
