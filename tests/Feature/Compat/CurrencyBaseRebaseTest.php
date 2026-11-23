<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCurrency;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use GP247\Shop\Tests\TestCase;

/**
 * Domain + upgrade-migration guarantees for the explicit base currency and the
 * value-preserving rebase (US-CMP-base-currency-explicit, US-SADM-currency-rebase-ui):
 *   - getBaseCode() is the single source of truth reading the is_base flag (not
 *     the old implicit exchange_rate=1 scan);
 *   - the base currency is edit/delete-locked at the MODEL layer (deleteBlockReason
 *     'base', the deleting boot guard, and the saving boot guard that keeps the
 *     base invariant exchange_rate=1 & status=1);
 *   - ShopCurrency::rebase() rescales the four base-denominated price columns and
 *     every currency rate so each product's displayed value in every currency is
 *     unchanged, then pins the new base to rate 1 and swaps the flag;
 *   - rebase() rejects an invalid target/rate WITHOUT mutating any price
 *     (pre-transaction validation), and does so atomically;
 *   - the upgrade migration is idempotent and only backfills the base when exactly
 *     one candidate exists (never guesses on 0 or >= 2).
 *
 * Tier-3 (needs a real DB connection): the local CLI PHP has no pdo driver, so
 * this runs in CI regression, not the TDD inner loop.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-base-currency-explicit
 * @aidlc-story US-SADM-currency-rebase-ui
 * @aidlc-adr currency-base-system-scope
 * @aidlc-adr currency-rebase-value-preserving
 */
class CurrencyBaseRebaseTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Clear any seeded base flag so a test builds its own unambiguous base.
     * Query builder (not Eloquent) so the saving boot guard never intervenes.
     *
     * @return void
     */
    private function clearBaseFlag(): void
    {
        ShopCurrency::query()->update(['is_base' => 0]);
        ShopCurrency::resetStaticCache();
    }

    /**
     * Create a currency row (defaults to an active, non-base secondary).
     *
     * @param array<string, mixed> $overrides Column overrides.
     * @return ShopCurrency
     */
    private function makeCurrency(array $overrides = []): ShopCurrency
    {
        return ShopCurrency::create(array_merge([
            'name' => 'ZZ Currency',
            'code' => 'ZC' . substr(uniqid(), -4),
            'symbol' => '$',
            'exchange_rate' => 1,
            'precision' => 2,
            'symbol_first' => 0,
            'thousands' => ',',
            'status' => 1,
            'is_base' => 0,
            'sort' => 0,
        ], $overrides));
    }

    /**
     * Path to the idempotent is_base upgrade migration under test.
     *
     * @return string
     */
    private function upgradeMigrationPath(): string
    {
        return base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/'
            . '2026_08_22_181309_add_is_base_to_currency.php'
        );
    }

    public function test_get_base_code_reads_is_base_flag(): void
    {
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBASE', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Other', 'code' => 'ZOTHR', 'exchange_rate' => 5]);

        $this->assertSame('ZBASE', ShopCurrency::getBaseCode());
    }

    public function test_get_base_code_is_null_when_no_base_flagged(): void
    {
        $this->clearBaseFlag();
        // A rate=1 currency must NOT be mistaken for the base without the flag
        // (that was exactly the fragile implicit convention this replaces).
        $this->makeCurrency(['name' => 'ZZ Rate One', 'code' => 'ZRON', 'exchange_rate' => 1]);

        $this->assertNull(ShopCurrency::getBaseCode());
    }

    public function test_delete_block_reason_flags_base(): void
    {
        $this->clearBaseFlag();
        $base = $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBDEL', 'exchange_rate' => 1, 'is_base' => 1]);

        $this->assertSame('base', $base->deleteBlockReason());
    }

    public function test_model_boot_prevents_deleting_base(): void
    {
        $this->clearBaseFlag();
        $base = $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBDL2', 'exchange_rate' => 1, 'is_base' => 1]);

        $base->delete();

        $this->assertNotNull(ShopCurrency::find($base->id));
    }

    public function test_model_boot_prevents_breaking_base_invariant_on_save(): void
    {
        $this->clearBaseFlag();
        $base = $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBINV', 'exchange_rate' => 1, 'is_base' => 1]);

        // Try to give the base a non-1 rate and to deactivate it: both are rejected
        // by the saving guard, so the persisted row keeps the base invariant.
        $base->exchange_rate = 2;
        $base->save();
        $base->status = 0;
        $base->save();

        $fresh = ShopCurrency::find($base->id);
        $this->assertEqualsWithDelta(1.0, (float) $fresh->exchange_rate, 1e-9);
        $this->assertSame(1, (int) $fresh->status);
    }

    public function test_rebase_preserves_displayed_value_in_every_currency(): void
    {
        $this->clearBaseFlag();
        // OLD base (rate 1), the NEW target (25000 per 1 old base), and a third
        // currency (2 per 1 old base). Rates chosen so every rescaled rate is
        // exact at 6 decimals.
        $old = $this->makeCurrency(['name' => 'ZZ Old Base', 'code' => 'ZOB', 'exchange_rate' => 1, 'is_base' => 1]);
        $new = $this->makeCurrency(['name' => 'ZZ New Base', 'code' => 'ZNB', 'exchange_rate' => 25000]);
        $third = $this->makeCurrency(['name' => 'ZZ Third', 'code' => 'ZTH', 'exchange_rate' => 2]);

        $oldRates = [
            $old->code => 1.0,
            $new->code => 25000.0,
            $third->code => 2.0,
        ];

        // A product with all four base-denominated price columns populated.
        $product = ShopProduct::create([
            'sku' => 'ZZ-REBASE-' . uniqid(),
            'alias' => 'zz-rebase-' . uniqid(),
            'price' => 10,
            'cost' => 4,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
        ]);
        $prefix = GP247_DB_PREFIX;
        DB::connection(GP247_DB_CONNECTION)->table($prefix . 'shop_product_attribute')->insert([
            'name' => 'ZZ Attr',
            'attribute_group_id' => 0,
            'product_id' => $product->id,
            'add_price' => 2,
            'sort' => 0,
            'status' => 1,
        ]);
        DB::connection(GP247_DB_CONNECTION)->table($prefix . 'shop_product_promotion')->insert([
            'product_id' => $product->id,
            'price_promotion' => 8,
            'status_promotion' => 1,
        ]);

        $r = 25000.0;
        // Suggested value-preserving rate for the outgoing base = 1 / r.
        ShopCurrency::rebase('ZNB', 1 / $r);

        // 1. All four stored columns were scaled by r (now expressed in the new base).
        $freshProduct = ShopProduct::find($product->id);
        $this->assertEqualsWithDelta(10 * $r, (float) $freshProduct->price, 1e-6);
        $this->assertEqualsWithDelta(4 * $r, (float) $freshProduct->cost, 1e-6);
        $this->assertEqualsWithDelta(2 * $r, (float) DB::connection(GP247_DB_CONNECTION)
            ->table($prefix . 'shop_product_attribute')->where('product_id', $product->id)->value('add_price'), 1e-6);
        $this->assertEqualsWithDelta(8 * $r, (float) DB::connection(GP247_DB_CONNECTION)
            ->table($prefix . 'shop_product_promotion')->where('product_id', $product->id)->value('price_promotion'), 1e-6);

        // 2. The displayed value in EVERY currency is unchanged: newPrice * newRate(C)
        //    equals the original price * oldRate(C).
        $newPrice = (float) $freshProduct->price;
        foreach ([$old->code, $new->code, $third->code] as $code) {
            $newRate = (float) ShopCurrency::where('code', $code)->value('exchange_rate');
            $this->assertEqualsWithDelta(
                10 * $oldRates[$code],
                ShopCurrency::getValue($newPrice, $newRate),
                1e-6,
                "Displayed value in {$code} must be preserved across the rebase"
            );
        }
    }

    public function test_rebase_pins_new_base_and_sets_outgoing_rate(): void
    {
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Old Base', 'code' => 'ZOB2', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ New Base', 'code' => 'ZNB2', 'exchange_rate' => 25000]);

        ShopCurrency::rebase('ZNB2', 0.00004);

        $new = ShopCurrency::where('code', 'ZNB2')->first();
        $old = ShopCurrency::where('code', 'ZOB2')->first();
        $this->assertSame('ZNB2', ShopCurrency::getBaseCode());
        $this->assertEqualsWithDelta(1.0, (float) $new->exchange_rate, 1e-9);
        $this->assertTrue((bool) $new->is_base);
        $this->assertEqualsWithDelta(0.00004, (float) $old->exchange_rate, 1e-9);
        $this->assertFalse((bool) $old->is_base);
    }

    public function test_rebase_rejects_rate_of_one(): void
    {
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Old Base', 'code' => 'ZOB3', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ New Base', 'code' => 'ZNB3', 'exchange_rate' => 25000]);

        $this->expectException(\InvalidArgumentException::class);
        ShopCurrency::rebase('ZNB3', 1.0);
    }

    public function test_rebase_rejects_inactive_or_missing_target_without_touching_prices(): void
    {
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Old Base', 'code' => 'ZOB4', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Inactive', 'code' => 'ZINA', 'exchange_rate' => 25000, 'status' => 0]);
        $product = ShopProduct::create([
            'sku' => 'ZZ-NOOP-' . uniqid(),
            'alias' => 'zz-noop-' . uniqid(),
            'price' => 10,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
        ]);

        // Inactive target: rejected before the transaction, so the price is intact.
        try {
            ShopCurrency::rebase('ZINA', 0.00004);
            $this->fail('Expected an InvalidArgumentException for an inactive target.');
        } catch (\InvalidArgumentException $e) {
            // expected
        }
        $this->assertEqualsWithDelta(10.0, (float) ShopProduct::find($product->id)->price, 1e-9);

        // Missing target: likewise rejected and price untouched.
        try {
            ShopCurrency::rebase('ZNONE', 0.00004);
            $this->fail('Expected an InvalidArgumentException for a missing target.');
        } catch (\InvalidArgumentException $e) {
            // expected
        }
        $this->assertEqualsWithDelta(10.0, (float) ShopProduct::find($product->id)->price, 1e-9);
    }

    public function test_upgrade_migration_is_idempotent_and_keeps_the_column(): void
    {
        // A fresh test DB already ships is_base from create-tables, so a guarded
        // up() must be a safe no-op when run again.
        $migration = require $this->upgradeMigrationPath();

        $migration->up();
        $migration->up();

        $schema = Schema::connection(GP247_DB_CONNECTION);
        $this->assertTrue($schema->hasColumn(GP247_DB_PREFIX . 'shop_currency', 'is_base'));
    }

    public function test_upgrade_migration_backfills_single_candidate(): void
    {
        $this->clearBaseFlag();
        // Exactly one active exchange_rate=1 currency → derivable base.
        $this->makeCurrency(['name' => 'ZZ Sole One', 'code' => 'ZSOLE', 'exchange_rate' => 1, 'status' => 1]);
        // Distinct rate so it is not a second candidate.
        $this->makeCurrency(['name' => 'ZZ Other', 'code' => 'ZOTH2', 'exchange_rate' => 7, 'status' => 1]);
        // Deactivate every seeded rate=1 currency so ZSOLE is the ONLY candidate.
        ShopCurrency::where('exchange_rate', 1)->where('code', '!=', 'ZSOLE')->update(['status' => 0]);

        $migration = require $this->upgradeMigrationPath();
        $migration->up();

        $this->assertSame('ZSOLE', ShopCurrency::getBaseCode());
    }

    public function test_upgrade_migration_does_not_guess_when_ambiguous(): void
    {
        $this->clearBaseFlag();
        // Two active exchange_rate=1 currencies and NO base flagged → ambiguous.
        $this->makeCurrency(['name' => 'ZZ Cand A', 'code' => 'ZCA', 'exchange_rate' => 1, 'status' => 1]);
        $this->makeCurrency(['name' => 'ZZ Cand B', 'code' => 'ZCB', 'exchange_rate' => 1, 'status' => 1]);

        $migration = require $this->upgradeMigrationPath();
        $migration->up();

        // It must NOT pick a base — the admin decides via "Change base".
        $this->assertNull(ShopCurrency::getBaseCode());
    }
}
