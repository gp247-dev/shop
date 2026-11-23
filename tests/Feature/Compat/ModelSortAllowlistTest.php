<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Front\Models\FrontBanner;
use GP247\Front\Models\FrontPage;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCurrency;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopSupplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Shop\Tests\TestCase;

/**
 * The public sort entry points of the storefront/admin models — setSort() (ModelTrait,
 * applied by buildQuery()) and the sort() query scope — only accept a column from the
 * model's own list and a direction of asc/desc. A plugin or template that hands request
 * input straight to them must not be able to put an arbitrary identifier into ORDER BY
 * (nor crash the page with an SQL error): a foreign column is ignored, a foreign
 * direction falls back to asc, a known column keeps sorting as before.
 *
 * Only builds SQL (toSql); no row is written.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-dynamic-sql-guard
 * @aidlc-adr compat-foundation_static-security-guards
 */
class ModelSortAllowlistTest extends TestCase
{
    use DatabaseTransactions;

    private const FOREIGN_COLUMN = 'x; DROP TABLE gp247_shop_order; --';

    /**
     * Models that expose setSort() + buildQuery(), with a column the model really has.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function buildQueryModels(): array
    {
        return [
            'ShopProduct' => [ShopProduct::class, 'sort'],
            'ShopBrand' => [ShopBrand::class, 'sort'],
            'ShopCategory' => [ShopCategory::class, 'sort'],
            'ShopSupplier' => [ShopSupplier::class, 'sort'],
            'ShopOrder' => [ShopOrder::class, 'total'],
            'FrontBanner' => [FrontBanner::class, 'sort'],
            'FrontPage' => [FrontPage::class, 'id'],
        ];
    }

    /**
     * Models that expose the sort() query scope.
     *
     * @return array<string, array{class-string}>
     */
    public static function scopeModels(): array
    {
        return [
            'ShopProduct' => [ShopProduct::class],
            'ShopBrand' => [ShopBrand::class],
            'ShopCategory' => [ShopCategory::class],
            'ShopSupplier' => [ShopSupplier::class],
            'ShopOrder' => [ShopOrder::class],
            'ShopCurrency' => [ShopCurrency::class],
            'FrontBanner' => [FrontBanner::class],
            'FrontPage' => [FrontPage::class],
        ];
    }

    /**
     * SQL of $class::buildQuery() after setSort($sort), or the error it raised.
     *
     * @param class-string $class
     * @param array $sort
     * @return string
     */
    private function sqlAfterSetSort(string $class, array $sort): string
    {
        try {
            return (new $class)->setSort($sort)->buildQuery()->toSql();
        } catch (\Throwable $e) {
            return 'ERROR ' . get_class($e) . ': ' . $e->getMessage();
        }
    }

    /**
     * SQL of $class::query()->sort($column, $direction), or the error it raised.
     *
     * @param class-string $class
     * @param string $column
     * @param string $direction
     * @return string
     */
    private function sqlAfterScope(string $class, string $column, string $direction): string
    {
        try {
            return $class::query()->sort($column, $direction)->toSql();
        } catch (\Throwable $e) {
            return 'ERROR ' . get_class($e) . ': ' . $e->getMessage();
        }
    }

    #[DataProvider('buildQueryModels')]
    public function test_a_foreign_column_given_to_set_sort_never_reaches_the_order_by(string $class, string $known): void
    {
        $sql = $this->sqlAfterSetSort($class, [self::FOREIGN_COLUMN, 'asc']);

        $this->assertStringNotContainsString('ERROR', $sql, 'a foreign sort column must be ignored, not crash');
        $this->assertStringNotContainsString('DROP', $sql);
    }

    #[DataProvider('buildQueryModels')]
    public function test_a_known_column_given_to_set_sort_still_orders_the_list(string $class, string $known): void
    {
        $sql = $this->sqlAfterSetSort($class, [$known, 'desc']);

        $this->assertStringNotContainsString('ERROR', $sql);
        $this->assertMatchesRegularExpression('/order by .*desc/i', $sql);
    }

    #[DataProvider('scopeModels')]
    public function test_a_foreign_column_given_to_the_sort_scope_never_reaches_the_order_by(string $class): void
    {
        $sql = $this->sqlAfterScope($class, self::FOREIGN_COLUMN, 'asc');

        $this->assertStringNotContainsString('ERROR', $sql, 'a foreign sort column must be ignored, not crash');
        $this->assertStringNotContainsString('DROP', $sql);
    }

    #[DataProvider('scopeModels')]
    public function test_a_foreign_direction_given_to_the_sort_scope_falls_back_to_ascending(string $class): void
    {
        $sql = $this->sqlAfterScope($class, 'id', 'asc; DELETE FROM gp247_shop_order');

        $this->assertStringNotContainsString('ERROR', $sql, 'a foreign direction must fall back, not crash');
        $this->assertStringNotContainsString('DELETE', $sql);
    }

    #[DataProvider('scopeModels')]
    public function test_a_known_column_given_to_the_sort_scope_still_orders_the_query(string $class): void
    {
        $sql = $this->sqlAfterScope($class, 'id', 'desc');

        $this->assertStringNotContainsString('ERROR', $sql);
        $this->assertMatchesRegularExpression('/order by `?(\w+`?\.`?)?id`? desc/i', $sql);
    }
}
