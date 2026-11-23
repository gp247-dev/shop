<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use GP247\Shop\Tests\TestCase;

/**
 * Backfill rule for the store 1-1 upgrade migration (ADR multi-store_one-to-one-store-ownership):
 *   - an entity present in EXACTLY ONE store adopts that store (N=1);
 *   - an entity shared across MORE THAN ONE store collapses to the ROOT default
 *     (N>1 — left untouched, so the column default(1) stands);
 *   - an entity with NO pivot rows keeps the ROOT default.
 *
 * The real private backfillFromPivot() is invoked by reflection against a pair of
 * disposable fixture tables so the algorithm (MIN + COUNT(DISTINCT)=1 + chunked
 * UPDATE) is exercised exactly as the migration runs it, without depending on the
 * already-retired production pivots or touching real catalog data. DDL is
 * auto-committed by MySQL, so this test manages its own fixtures instead of
 * relying on a transaction rollback.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-store-1to1-schema
 * @aidlc-adr multi-store_one-to-one-store-ownership
 */
class StoreOneToOneBackfillTest extends TestCase
{
    /**
     * Unprefixed fixture entity table.
     *
     * @var string
     */
    private string $entity = 'test_store1to1_entity';

    /**
     * Unprefixed fixture pivot table.
     *
     * @var string
     */
    private string $pivot = 'test_store1to1_entity_store';

    /**
     * Create the fixture entity + pivot tables fresh.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $schema = Schema::connection(GP247_DB_CONNECTION);
        $schema->dropIfExists(GP247_DB_PREFIX . $this->pivot);
        $schema->dropIfExists(GP247_DB_PREFIX . $this->entity);

        $schema->create(GP247_DB_PREFIX . $this->entity, function (Blueprint $t) {
            $t->string('id', 36)->primary();
            // Mirrors the real owned column: uuid, default ROOT (1).
            $t->uuid('store_id')->default(1);
        });

        $schema->create(GP247_DB_PREFIX . $this->pivot, function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('entity_id', 36);
            $t->uuid('store_id');
        });
    }

    /**
     * Drop the fixture tables.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $schema->dropIfExists(GP247_DB_PREFIX . $this->pivot);
        $schema->dropIfExists(GP247_DB_PREFIX . $this->entity);

        parent::tearDown();
    }

    /**
     * Invoke the migration's real private backfillFromPivot() against the fixtures.
     *
     * @return void
     */
    private function runBackfill(): void
    {
        $path = base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_08_28_090000_store_id_one_to_one_shop.php'
        );
        $migration = require $path;

        $method = new \ReflectionMethod($migration, 'backfillFromPivot');
        $method->setAccessible(true);
        $method->invoke(
            $migration,
            Schema::connection(GP247_DB_CONNECTION),
            $this->entity,
            $this->pivot,
            'entity_id'
        );
    }

    public function test_backfill_assigns_single_store_and_collapses_shared_to_root(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $entityFull = GP247_DB_PREFIX . $this->entity;
        $pivotFull = GP247_DB_PREFIX . $this->pivot;

        // Every entity starts on the ROOT default (1), exactly like a freshly
        // added store_id column.
        $db->table($entityFull)->insert([
            ['id' => 'ent-single', 'store_id' => '1'],
            ['id' => 'ent-shared', 'store_id' => '1'],
            ['id' => 'ent-orphan', 'store_id' => '1'],
        ]);

        // ent-single belongs to exactly one store (7) -> N=1 -> adopts 7.
        // ent-shared belongs to two stores (7, 9) -> N>1 -> stays ROOT.
        // ent-orphan has no pivot rows -> stays ROOT.
        $db->table($pivotFull)->insert([
            ['entity_id' => 'ent-single', 'store_id' => '7'],
            ['entity_id' => 'ent-shared', 'store_id' => '7'],
            ['entity_id' => 'ent-shared', 'store_id' => '9'],
        ]);

        $this->runBackfill();

        $this->assertSame(
            '7',
            (string) $db->table($entityFull)->where('id', 'ent-single')->value('store_id'),
            'A single-store entity (N=1) must adopt that store.'
        );
        $this->assertSame(
            '1',
            (string) $db->table($entityFull)->where('id', 'ent-shared')->value('store_id'),
            'A multi-store entity (N>1) must collapse to the ROOT default.'
        );
        $this->assertSame(
            '1',
            (string) $db->table($entityFull)->where('id', 'ent-orphan')->value('store_id'),
            'An entity with no pivot rows must keep the ROOT default.'
        );
    }

    public function test_backfill_is_a_noop_when_pivot_is_empty(): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $entityFull = GP247_DB_PREFIX . $this->entity;

        $db->table($entityFull)->insert([
            ['id' => 'ent-a', 'store_id' => '1'],
            ['id' => 'ent-b', 'store_id' => '3'],
        ]);

        // Empty pivot -> the guard returns early, leaving every store_id untouched.
        $this->runBackfill();

        $this->assertSame('1', (string) $db->table($entityFull)->where('id', 'ent-a')->value('store_id'));
        $this->assertSame('3', (string) $db->table($entityFull)->where('id', 'ent-b')->value('store_id'));
    }
}
