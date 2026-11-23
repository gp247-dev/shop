<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCurrency;
use GP247\Shop\Models\ShopOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Precision + type guarantees for exchange_rate across the three tables that
 * carry it (US-CMP-exchange-rate-precision, ADR compat-foundation_exchange-rate-precision):
 *   - a small snapshot rate (a large currency priced against a small-unit base,
 *     e.g. VND base + USD ≈ 0.00004) survives a store/read round-trip instead of
 *     being truncated to 0.00 — the exact bug of the old shop_order.exchange_rate
 *     decimal(15,2) column (RISK-TECH-exchange-rate-truncation);
 *   - all three columns share the unified decimal(16,6) type
 *     (NFR-MAINT-exchange-rate-type-consistency);
 *   - the upgrade migration is idempotent (its type guard lets up() run again on
 *     an already-migrated DB without error — RISK-TECH-exchange-rate-migration);
 *   - the model cast returns a number, not a decimal string, so getValue()
 *     (money × rate) and other consumers do arithmetic on a numeric value.
 *
 * Tier-3 (needs a real DB connection): the local CLI PHP has no pdo driver, so
 * this runs in CI regression, not the TDD inner loop.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-exchange-rate-precision
 * @aidlc-adr compat-foundation_exchange-rate-precision
 */
class ExchangeRatePrecisionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    /**
     * A representative small rate: a large currency (USD) priced against a
     * small-unit base (VND, rate=1). Needs 5 decimal places, so decimal(15,2)
     * truncated it to 0.00; decimal(16,6) keeps it exactly.
     */
    private const SMALL_RATE = 0.00004;

    /**
     * Path to the idempotent upgrade migration under test.
     *
     * @return string
     */
    private function upgradeMigrationPath(): string
    {
        return base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/'
            . '2026_08_22_120000_widen_exchange_rate_precision.php'
        );
    }

    public function test_shop_order_preserves_small_exchange_rate(): void
    {
        $order = $this->makeOrder(['currency' => 'USD', 'exchange_rate' => self::SMALL_RATE]);

        $fresh = ShopOrder::find($order->id);

        // Under the old decimal(15,2) this read back 0.00 (order lost its rate).
        $this->assertEqualsWithDelta(self::SMALL_RATE, (float) $fresh->exchange_rate, 1e-9);
    }

    public function test_order_detail_preserves_small_exchange_rate(): void
    {
        $order = $this->makeOrder(['currency' => 'USD', 'exchange_rate' => self::SMALL_RATE]);
        $item = $this->makeOrderItem($order, ['exchange_rate' => self::SMALL_RATE]);

        $fresh = $item->fresh();

        $this->assertEqualsWithDelta(self::SMALL_RATE, (float) $fresh->exchange_rate, 1e-9);
    }

    public function test_shop_currency_preserves_small_exchange_rate(): void
    {
        $currency = ShopCurrency::create([
            'name' => 'ZZ Dollar',
            'code' => 'ZZ' . substr((string) uniqid(), -2),
            'symbol' => '$',
            'exchange_rate' => self::SMALL_RATE,
            'precision' => 2,
            'status' => 1,
        ]);

        $fresh = ShopCurrency::find($currency->id);

        $this->assertEqualsWithDelta(self::SMALL_RATE, (float) $fresh->exchange_rate, 1e-9);
    }

    public function test_exchange_rate_columns_are_decimal_on_all_three_tables(): void
    {
        $schema = Schema::connection(GP247_DB_CONNECTION);

        foreach (['shop_currency', 'shop_order', 'shop_order_detail'] as $table) {
            $full = GP247_DB_PREFIX . $table;
            $this->assertSame(
                'decimal',
                $schema->getColumnType($full, 'exchange_rate'),
                "{$full}.exchange_rate must be decimal(16,6) for a unified, precise type"
            );
        }
    }

    public function test_exchange_rate_cast_returns_number_not_string(): void
    {
        $order = $this->makeOrder(['currency' => 'USD', 'exchange_rate' => self::SMALL_RATE]);

        // Laravel returns a raw decimal column as a string; the cast must hand
        // consumers a number so `money * rate` never multiplies on a string.
        $this->assertIsNotString(ShopOrder::find($order->id)->exchange_rate);
    }

    public function test_upgrade_migration_is_idempotent(): void
    {
        // A fresh test DB already ships decimal(16,6) from create-tables, so the
        // guarded up() must be a safe no-op when run again (RISK-TECH-exchange-rate-migration).
        $migration = require $this->upgradeMigrationPath();

        $migration->up();
        $migration->up();

        $schema = Schema::connection(GP247_DB_CONNECTION);
        foreach (['shop_currency', 'shop_order', 'shop_order_detail'] as $table) {
            $full = GP247_DB_PREFIX . $table;
            $this->assertSame('decimal', $schema->getColumnType($full, 'exchange_rate'));
        }
    }
}
