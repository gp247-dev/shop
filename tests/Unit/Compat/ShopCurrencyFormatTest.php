<?php

namespace GP247\Shop\Tests\Unit\Compat;

use GP247\Shop\Models\ShopCurrency;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for ShopCurrency money formatting.
 *
 * Verifies that display precision follows the CURRENCY being rendered (its
 * `precision` column) consistently for every value — dropping the legacy
 * value-conditional branch that showed whole numbers with 0 decimals and
 * fractional numbers with full precision (inconsistent within one currency,
 * against the ecommerce minor-unit convention). Also verifies the merged
 * cross-currency fix: render()/onlyRender() format a non-active target
 * currency with the TARGET currency's precision/separators, not the active
 * currency's static props.
 *
 * Group A2 additionally covers the admin Order-detail path: admin never runs
 * CurrencyMiddleware, so the active static props stay at the class default
 * (precision 2). onlyRender() must still honor the ORDER currency's precision
 * (its `currency` snapshot) and must NOT re-convert by exchange_rate (stored
 * order amounts are already in the order currency).
 *
 * The test sets the protected static props (and seeds $checkListCurrency)
 * via reflection so the formatting logic is exercised in isolation, without
 * the DB-querying full app boot.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-storefront-currency-display-precision, US-SADM-order-currency-display
 * @aidlc-adr shop_currency-display-precision
 */
class ShopCurrencyFormatTest extends TestCase
{
    /**
     * Define the global constants that ShopCurrency's property defaults
     * (`$connection`, `$table`) reference, which the GP247 core bootstrap
     * normally provides. Must run before the model class autoloads (first
     * reflection call), so it lives in setUpBeforeClass. Values are arbitrary
     * because this test never issues a query.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!defined('GP247_DB_CONNECTION')) {
            define('GP247_DB_CONNECTION', 'mysql');
        }
        if (!defined('GP247_DB_PREFIX')) {
            define('GP247_DB_PREFIX', 'sc_');
        }
    }

    /**
     * Set a protected static property of ShopCurrency by name.
     *
     * @param string $prop  Static property name.
     * @param mixed  $value Value to assign.
     */
    private function setStatic(string $prop, $value): void
    {
        $r = new \ReflectionProperty(ShopCurrency::class, $prop);
        $r->setAccessible(true);
        $r->setValue(null, $value);
    }

    /**
     * Configure the active currency static props (as CurrencyMiddleware/setCode would).
     *
     * @param int    $precision   Number of decimals.
     * @param string $thousands   Thousands separator (',' or '.').
     * @param string $symbol      Currency symbol.
     * @param int    $symbolFirst Whether the symbol is prefixed.
     * @param float  $rate        Exchange rate.
     */
    private function setActiveCurrency(int $precision, string $thousands, string $symbol = '$', int $symbolFirst = 0, float $rate = 1.0): void
    {
        $this->setStatic('precision', $precision);
        $this->setStatic('thousands', $thousands);
        // decimal is always derived from thousands, mirroring setCode()
        $this->setStatic('decimal', ($thousands === '.') ? ',' : '.');
        $this->setStatic('symbol', $symbol);
        $this->setStatic('symbol_first', $symbolFirst);
        $this->setStatic('exchange_rate', $rate);
    }

    /**
     * Seed the static currency cache so render()/onlyRender() resolve a target
     * currency without hitting the database. A plain array is enough because the
     * methods access it via array offsets (an Eloquent model would too at runtime).
     *
     * @param string $code    Currency code key.
     * @param array  $props   symbol/symbol_first/precision/thousands.
     */
    private function seedCurrency(string $code, array $props): void
    {
        $r = new \ReflectionProperty(ShopCurrency::class, 'checkListCurrency');
        $r->setAccessible(true);
        $list = $r->getValue();
        $list[$code] = $props;
        $r->setValue(null, $list);
    }

    /**
     * Reset the currency cache so seeded stubs do not leak between tests.
     */
    protected function tearDown(): void
    {
        $this->setStatic('checkListCurrency', []);
        parent::tearDown();
    }

    /**
     * A precision=2 currency shows 2 decimals for a whole-number value
     * (legacy behaviour showed "10", inconsistent with fractional values).
     */
    public function test_whole_number_keeps_currency_precision(): void
    {
        $this->setActiveCurrency(2, ',');
        $this->assertSame('10.00', ShopCurrency::format(10.0));
    }

    /**
     * A precision=2 currency shows exactly 2 decimals for a fractional value
     * (rounded), consistent with the whole-number case above.
     */
    public function test_fractional_number_uses_currency_precision(): void
    {
        $this->setActiveCurrency(2, ',');
        $this->assertSame('10.00', ShopCurrency::format(9.999));
        $this->assertSame('9.99', ShopCurrency::format(9.99));
    }

    /**
     * A precision=0 currency (e.g. VND/JPY) never shows a fractional part,
     * for whole or fractional values alike; decimal derives from thousands.
     */
    public function test_zero_precision_currency_has_no_decimals(): void
    {
        $this->setActiveCurrency(0, '.');
        $this->assertSame('10.000', ShopCurrency::format(10000.0));
        $this->assertSame('10.000', ShopCurrency::format(9999.6));
    }

    /**
     * format() with no extra args keeps the active-currency behaviour
     * (backward compatibility for every existing caller).
     */
    public function test_format_defaults_to_active_currency(): void
    {
        $this->setActiveCurrency(2, ',');
        $this->assertSame('1,234.50', ShopCurrency::format(1234.5));
    }

    /**
     * Explicit precision/thousands args override the active currency —
     * the mechanism render()/onlyRender() use to honour the target currency.
     */
    public function test_format_honours_explicit_precision_and_thousands(): void
    {
        $this->setActiveCurrency(0, '.'); // active = VND-like
        // Ask for USD-like formatting explicitly.
        $this->assertSame('1,234.50', ShopCurrency::format(1234.5, 2, ','));
    }

    /**
     * render() of a non-active target currency uses the TARGET currency's
     * precision/thousands, not the active currency's. Active is VND-like
     * (precision 0); rendering a USD value must still show 2 decimals.
     */
    public function test_render_uses_target_currency_precision(): void
    {
        $this->setActiveCurrency(0, '.', '₫', 0); // active VND: 0 decimals
        $this->seedCurrency('USD', [
            'symbol'       => '$',
            'symbol_first' => 1,
            'precision'    => 2,
            'thousands'    => ',',
        ]);
        // rate passed explicitly (=1) so getValue does not use active exchange_rate
        $this->assertSame('$10.00', ShopCurrency::render(9.999, 'USD', 1.0));
    }

    /**
     * onlyRender() likewise formats with the passed currency's precision.
     * Negative value with symbol_first keeps the leading '-' then symbol.
     */
    public function test_only_render_uses_target_currency_precision(): void
    {
        $this->setActiveCurrency(0, '.', '₫', 0); // active VND
        $this->seedCurrency('USD', [
            'symbol'       => '$',
            'symbol_first' => 1,
            'precision'    => 2,
            'thousands'    => ',',
        ]);
        $this->assertSame('$1,234.50', ShopCurrency::onlyRender(1234.5, 'USD'));
        $this->assertSame('-$1,234.50', ShopCurrency::onlyRender(-1234.5, 'USD'));
    }

    /**
     * render() without an explicit currency formats with the active currency's
     * precision (drawn from getCurrency()), still consistent per-value.
     */
    public function test_render_active_currency_is_consistent(): void
    {
        $this->setActiveCurrency(2, ',', '$', 1);
        $this->assertSame('$10.00', ShopCurrency::render(10.0, null, 1.0));
    }

    /**
     * [Group A2 — MC case (e)] The admin Order-detail screen renders amounts in
     * the ORDER's own currency snapshot, honoring that currency's precision even
     * though admin never runs CurrencyMiddleware (active props stay at the class
     * default precision 2). Reproduces the reported bug: a VND order (precision 0)
     * showed "200.00". onlyRender() must (1) drop the ".00" per the VND precision
     * and (2) NOT re-convert by exchange_rate — stored order amounts are already
     * in the order currency, so an active rate of 2.0 must not double the value.
     * The blade calls with includeSymbol=false, so the plain number is asserted.
     */
    public function test_only_render_honors_order_currency_at_admin_default(): void
    {
        // Admin context: active currency untouched → class-default precision 2,
        // plus an exchange_rate that would corrupt the amount if wrongly applied.
        $this->setActiveCurrency(2, ',', '$', 0, 2.0);
        $this->seedCurrency('vnd', [
            'symbol'       => '₫',
            'symbol_first' => 0,
            'precision'    => 0,
            'thousands'    => '.',
        ]);
        // Exact admin blade call (includeSymbol = false): "200", not "200.00" / "400".
        $this->assertSame('200', ShopCurrency::onlyRender(200.0, 'vnd', false, false));
        $this->assertSame('222', ShopCurrency::onlyRender(222.0, 'vnd', false, false));
        // VND thousands separator is '.', and magnitude is unchanged (no rate).
        $this->assertSame('1.234.567', ShopCurrency::onlyRender(1234567.0, 'vnd', false, false));
    }

    /**
     * [Group A2] A legacy order with an empty currency code degrades safely:
     * onlyRender() falls back to the active currency's precision (the `?? null`
     * guard feeding format()'s null-default) instead of crashing. Here the
     * active default is precision 2, so the amount keeps two decimals.
     *
     * A truthy stub without a `precision` key stands in for the "currency row not
     * found" case, so the pure-unit test issues no DB query while still exercising
     * the same `?? null → format(null) → active precision` fallback path.
     */
    public function test_only_render_missing_order_currency_falls_back_to_active(): void
    {
        $this->setActiveCurrency(2, ',', '$', 0, 1.0);
        // Non-empty stub lacking precision/thousands → skips DB, forces `?? null`.
        $this->seedCurrency('', ['symbol' => '']);
        $this->assertSame('200.00', ShopCurrency::onlyRender(200.0, '', false, false));
    }

    /**
     * [Group A4 — MC case (f)] The admin Order-LIST screen renders each row's
     * total in that order's own currency snapshot, honoring its precision like
     * A2, but WITH the currency symbol (includeSymbol=true): the list mixes
     * currencies in a single column with no separate currency column, so the
     * symbol is what distinguishes a VND row from a USD row. onlyRender() must
     * still drop the ".00" per the VND precision and NOT re-convert by
     * exchange_rate (an active rate of 2.0 must not double the value). VND uses
     * a suffix symbol (symbol_first=0) and '.' thousands.
     */
    public function test_only_render_with_symbol_honors_order_currency_at_admin_default(): void
    {
        // Admin context: active precision 2 + a rate that would corrupt the amount.
        $this->setActiveCurrency(2, ',', '$', 0, 2.0);
        $this->seedCurrency('vnd', [
            'symbol'       => '₫',
            'symbol_first' => 0,
            'precision'    => 0,
            'thousands'    => '.',
        ]);
        // Exact list blade call (includeSymbol = true): symbol present, no ".00", no rate applied.
        $this->assertSame('200₫', ShopCurrency::onlyRender(200.0, 'vnd', false, true));
        $this->assertSame('4.250.000₫', ShopCurrency::onlyRender(4250000.0, 'vnd', false, true));
    }
}
