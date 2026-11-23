<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Support\Facades\Schema;
use GP247\Shop\Tests\TestCase;

/**
 * Schema guarantees for the store 1-1 standardization (ADR multi-store_one-to-one-store-ownership):
 *   - a scalar `store_id` column exists on every store-owned table across
 *     gp247/shop and gp247/front (invariant S1);
 *   - all five retired N-N store pivots are gone (invariant S2);
 *   - the upgrade migrations are idempotent (their hasColumn / hasTable guards
 *     let up() run again on an already-migrated DB without error).
 *
 * These assertions describe the state AFTER the upgrade migrations have run
 * (fresh installs reach the same shape from the create-tables migration). They
 * do NOT use DatabaseTransactions: the schema is inspected read-only and the
 * idempotency check re-runs guarded DDL, which MySQL auto-commits anyway.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-store-1to1-schema
 * @aidlc-adr multi-store_one-to-one-store-ownership
 */
class StoreOneToOneSchemaTest extends TestCase
{
    /**
     * Every store-owned table (unprefixed) that must carry a scalar store_id.
     * Six shop tables + five front tables = the full 1-1 ownership surface.
     *
     * @var string[]
     */
    private array $ownedTables = [
        // shop
        'shop_product',
        'shop_category',
        'shop_brand',
        'shop_product_tag',
        'shop_attribute_group',
        'shop_tax',
        // front
        'front_banner',
        'front_banner_type',
        'front_link',
        'front_link_group',
        'front_page',
    ];

    /**
     * The five retired N-N store pivots (unprefixed) that must no longer exist.
     *
     * @var string[]
     */
    private array $retiredPivots = [
        'shop_product_store',
        'shop_category_store',
        'front_banner_store',
        'front_link_store',
        'front_page_store',
    ];

    /**
     * Absolute paths to the two idempotent upgrade migrations under test.
     *
     * @return string[]
     */
    private function upgradeMigrationPaths(): array
    {
        return [
            base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_08_28_090000_store_id_one_to_one_shop.php'),
            base_path('vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_08_28_090000_store_id_one_to_one_front.php'),
        ];
    }

    public function test_store_id_column_exists_on_every_owned_table(): void
    {
        foreach ($this->ownedTables as $table) {
            $full = GP247_DB_PREFIX . $table;
            if (!Schema::hasTable($full)) {
                // Partial install (shop OR front only) — the missing package's
                // tables are legitimately absent; skip rather than fail (NFR-MAINT-001).
                continue;
            }
            $this->assertTrue(
                Schema::hasColumn($full, 'store_id'),
                "{$full}.store_id must exist (invariant S1)"
            );
        }
    }

    public function test_retired_store_pivots_are_gone(): void
    {
        foreach ($this->retiredPivots as $pivot) {
            $full = GP247_DB_PREFIX . $pivot;
            $this->assertFalse(
                Schema::hasTable($full),
                "Pivot {$full} must be dropped (invariant S2 — no N-N store relation)"
            );
        }
    }

    public function test_upgrade_migrations_are_idempotent(): void
    {
        // The DB already carries store_id and has dropped the pivots. Running the
        // upgrade up() again must be a guarded no-op — WITHOUT the hasColumn guard
        // it would throw "duplicate column".
        foreach ($this->upgradeMigrationPaths() as $path) {
            $migration = require $path;
            $migration->up();
            $migration->up();
        }

        foreach ($this->ownedTables as $table) {
            $full = GP247_DB_PREFIX . $table;
            if (!Schema::hasTable($full)) {
                continue;
            }
            $this->assertTrue(
                Schema::hasColumn($full, 'store_id'),
                "{$full}.store_id must remain after a repeated up() (idempotency)"
            );
        }

        foreach ($this->retiredPivots as $pivot) {
            $full = GP247_DB_PREFIX . $pivot;
            $this->assertFalse(
                Schema::hasTable($full),
                "Pivot {$full} must stay dropped after a repeated up() (idempotency)"
            );
        }
    }
}
