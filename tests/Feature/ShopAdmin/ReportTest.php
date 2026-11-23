<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ReportManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin Report screen (group G, US-SADM-006): a read-only
 * dashboard of KPIs + SVG charts + top products, reusing the existing stat
 * sources. Domain unchanged. Gated by `admin_report`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-006
 * @aidlc-adr ADR-001, ADR-004, ADR-006, ADR-007
 */
class ReportTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;
    use MakesShopProducts {
        MakesShopProducts::storeId insteadof MakesShopOrders;
        MakesShopProducts::makeProduct insteadof MakesShopOrders;
    }
    use ConfiguresQtyDecimal;

    public function test_renders_kpis(): void
    {
        $this->asUser(slugs: ['admin_report']);
        $this->useAdminStore();
        $this->makeOrder();

        $component = Livewire::test(ReportManager::class);
        $this->assertGreaterThanOrEqual(1, $component->instance()->totalOrders());
    }

    public function test_top_products_ordered_by_sold(): void
    {
        $this->asUser(slugs: ['admin_report']);
        $this->useAdminStore();
        $this->makeProduct('Low Sold', ['sku' => 'ZZ-RPT-LOW', 'sold' => 1]);
        $this->makeProduct('High Sold', ['sku' => 'ZZ-RPT-HIGH', 'sold' => 999]);

        $top = $component = Livewire::test(ReportManager::class)->instance()->topProducts();
        $skus = collect($top)->pluck('sku')->all();

        $this->assertContains('ZZ-RPT-HIGH', $skus);
        // The highest-sold product appears before the low-sold one.
        $this->assertLessThan(
            array_search('ZZ-RPT-LOW', $skus, true) === false ? PHP_INT_MAX : array_search('ZZ-RPT-LOW', $skus, true),
            array_search('ZZ-RPT-HIGH', $skus, true),
        );
    }

    /**
     * `sold` display follows product_qty_decimal (ADR-016) via gp247_qty_format() — whole number by default, 2dp when enabled.
     */
    public function test_top_products_sold_column_formatted_as_whole_number_by_default(): void
    {
        $this->asUser(slugs: ['admin_report']);
        $this->useAdminStore();
        $this->setQtyDecimalEnabled(false);
        $this->makeProduct('Fmt Sold', ['sku' => 'ZZ-RPT-FMT', 'sold' => 12.7]);

        Livewire::test(ReportManager::class)->assertSee('13')->assertDontSee('12.70');
    }

    public function test_top_products_sold_column_formatted_with_decimals_when_enabled(): void
    {
        $this->asUser(slugs: ['admin_report']);
        $this->useAdminStore();
        $this->setQtyDecimalEnabled(true);
        $this->makeProduct('Fmt Sold Dec', ['sku' => 'ZZ-RPT-FMT-DEC', 'sold' => 12.7]);

        Livewire::test(ReportManager::class)->assertSee('12.70');
    }

    public function test_report_denied_without_permission(): void
    {
        $this->asUser();

        $this->assertDenied(fn () => Livewire::test(ReportManager::class));
    }
}
