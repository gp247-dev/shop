<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * Japanese Yen is part of the default currency set: a new install has it at
 * 150 JPY per USD, an installed site receives it through gp247:update with the
 * rate expressed against its own base currency, and nothing is guessed when the
 * rate cannot be derived.
 *
 * DML only inside DatabaseTransactions (no DDL).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-jpy-default-currency
 * @aidlc-adr compat-foundation_locale-data-files
 */
class DefaultCurrencyJpyTest extends TestCase
{
    use DatabaseTransactions;

    private const SHOP_MIGRATION = 'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_22_201531_add_jpy_and_japanese_strings.php';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('GP247\\Shop\\Admin\\Database\\Seeders\\DataShopInitializeSeeder')) {
            $this->markTestSkipped('gp247/shop is not installed.');
        }
    }

    /**
     * @return ConnectionInterface
     */
    private function db(): ConnectionInterface
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return GP247_DB_PREFIX.'shop_currency';
    }

    /**
     * Run the shop upgrade migration.
     */
    private function upgrade(): void
    {
        (require base_path(self::SHOP_MIGRATION))->up();
    }

    /**
     * @return object|null
     */
    private function jpy()
    {
        return $this->db()->table($this->table())->where('code', 'JPY')->first();
    }

    public function test_new_install_has_yen_at_150_per_dollar_with_no_decimals(): void
    {
        $class = 'GP247\\Shop\\Admin\\Database\\Seeders\\DataShopInitializeSeeder';
        $jpy = collect((new $class())->dataCurrency())->firstWhere('code', 'JPY');

        $this->assertNotNull($jpy);
        $this->assertSame('¥', $jpy['symbol']);
        $this->assertEquals(150, $jpy['exchange_rate']);
        $this->assertSame('0', (string) $jpy['precision']);
        $this->assertSame('1', (string) $jpy['symbol_first']);
        $this->assertSame('1', (string) $jpy['status']);
        $this->assertSame('0', (string) $jpy['is_base']);
    }

    public function test_installed_site_with_dollar_base_receives_yen_at_150(): void
    {
        $base = $this->db()->table($this->table())->where('is_base', 1)->value('code');
        if ($base !== 'USD') {
            $this->markTestSkipped("Base currency is {$base}, not USD.");
        }
        $this->db()->table($this->table())->where('code', 'JPY')->delete();

        $this->upgrade();

        $jpy = $this->jpy();
        $this->assertEquals(150, (float) $jpy->exchange_rate);
        $this->assertSame(1, (int) $jpy->status);
        $this->assertSame(0, (int) $jpy->is_base);
        $this->assertSame(0, (int) $jpy->precision);
    }

    public function test_installed_site_with_another_base_gets_the_rate_through_the_dollar(): void
    {
        $t = $this->table();
        $this->db()->table($t)->where('code', 'JPY')->delete();
        $this->db()->table($t)->update(['is_base' => 0]);
        $this->db()->table($t)->where('code', 'USD')->update(['exchange_rate' => 0.00004]);
        $this->db()->table($t)->where('code', 'VND')->update(['is_base' => 1, 'exchange_rate' => 1, 'status' => 1]);

        $this->upgrade();

        $jpy = $this->jpy();
        $this->assertEqualsWithDelta(0.006, (float) $jpy->exchange_rate, 0.0000001);
        $this->assertSame(1, (int) $jpy->status);
    }

    public function test_installed_site_without_dollar_gets_yen_switched_off_instead_of_a_guess(): void
    {
        $t = $this->table();
        if (!$this->db()->table($t)->where('code', '<>', 'USD')->exists()) {
            $this->markTestSkipped('Needs another currency to stay as base.');
        }
        $this->db()->table($t)->where('code', 'JPY')->delete();
        $this->db()->table($t)->where('code', 'USD')->delete();

        $this->upgrade();

        $jpy = $this->jpy();
        $this->assertNotNull($jpy);
        $this->assertSame(0, (int) $jpy->status);
        $this->assertSame(0, (int) $jpy->is_base);
    }

    public function test_yen_the_owner_already_set_up_is_left_alone(): void
    {
        $t = $this->table();
        $this->db()->table($t)->where('code', 'JPY')->delete();
        $this->db()->table($t)->insert([
            'name' => 'Yen', 'code' => 'JPY', 'symbol' => 'JP¥', 'exchange_rate' => 140, 'precision' => 0,
            'symbol_first' => 1, 'thousands' => ',', 'status' => 0, 'is_base' => 0, 'sort' => 5,
        ]);

        $this->upgrade();
        $this->upgrade();

        $jpy = $this->jpy();
        $this->assertEquals(140, (float) $jpy->exchange_rate);
        $this->assertSame(0, (int) $jpy->status);
        $this->assertSame(1, $this->db()->table($t)->where('code', 'JPY')->count());
    }

    public function test_upgrade_adds_no_currency_before_the_shop_is_seeded(): void
    {
        $this->db()->table($this->table())->delete();

        $this->upgrade();

        $this->assertSame(0, $this->db()->table($this->table())->count());
    }
}
