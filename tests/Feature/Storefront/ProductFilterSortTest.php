<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Controllers\ShopProductController;
use GP247\Shop\Front\Livewire\ProductFilter;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductPromotion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for the storefront sort dropdown of the product grid (US-LW-005).
 *
 * Shopper-facing orders only: Featured (default — the admin `sort` column, then newest),
 * Newest, Best selling, Price low→high / high→low (on the price actually paid, group
 * products last) and Name A–Z. The admin `sort` column is no longer a shopper choice, and
 * unknown keys fall back to the default order.
 *
 * Each test isolates its products with a unique name token and the grid's keyword filter,
 * so the rest of the catalogue never interferes with the asserted order.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-005
 */
class ProductFilterSortTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;

    /** @var string Unique token carried by every product name of the current test. */
    private string $token;

    /**
     * Give each test its own product-name token.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = 'ZSORT' . strtoupper(uniqid());
    }

    /**
     * Create a product whose name carries the test token.
     *
     * @param string $label Suffix that names the product in assertions.
     * @param array<string, mixed> $overrides Column overrides.
     * @return string Product id.
     */
    private function product(string $label, array $overrides = []): string
    {
        return (string) $this->makeProduct($this->token . ' ' . $label, $overrides)->id;
    }

    /**
     * Attach a promotion price to a product.
     *
     * @param string $productId
     * @param float $price
     * @param string|null $start Start date-time, null = open.
     * @param string|null $end End date-time, null = open.
     * @param int $status
     * @return void
     */
    private function promote(string $productId, float $price, ?string $start = null, ?string $end = null, int $status = 1): void
    {
        ShopProductPromotion::insert([
            'product_id'       => $productId,
            'price_promotion'  => $price,
            'date_start'       => $start,
            'date_end'         => $end,
            'status_promotion' => $status,
        ]);
    }

    /**
     * Product ids the grid renders for this test's products, in display order.
     *
     * @param string $sort Value of `filter_sort` the shopper picked ('' = none).
     * @return array<int, string>
     */
    private function gridIds(string $sort = ''): array
    {
        $component = Livewire::test(ProductFilter::class, ['initialKeyword' => $this->token]);
        if ($sort !== '') {
            $component->set('sort', $sort);
        }

        return $component->viewData('products')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    // -------------------------------------------------------------------------
    // Dropdown content
    // -------------------------------------------------------------------------

    /**
     * The dropdown offers exactly the shopper-facing orders, with Featured as the default.
     */
    public function test_dropdown_offers_only_shopper_facing_orders(): void
    {
        $html = Livewire::test(ProductFilter::class)->html();

        preg_match('/<select[^>]*data-testid="shop-product-filter-sort".*?<\/select>/s', $html, $select);
        $this->assertNotEmpty($select, 'The sort dropdown must render');
        preg_match_all('/<option value="([^"]*)"/', $select[0], $values);

        $this->assertSame(
            ['', 'id_desc', 'sold_desc', 'price_asc', 'price_desc', 'name_asc'],
            $values[1],
            'Featured (default), Newest, Best selling, price both ways and Name A–Z — nothing based on the admin sort column'
        );
        $this->assertStringContainsString(gp247_language_render('filter_sort.featured'), $select[0]);
        $this->assertStringContainsString(gp247_language_render('filter_sort.best_selling'), $select[0]);
    }

    // -------------------------------------------------------------------------
    // Default order and the allowlist
    // -------------------------------------------------------------------------

    /**
     * With no choice, the admin's own arrangement leads and newest breaks ties.
     */
    public function test_default_order_follows_admin_sort_then_newest(): void
    {
        $older  = $this->product('older', ['sort' => 0]);
        $newer  = $this->product('newer', ['sort' => 0]);
        $pinned = $this->product('pinned', ['sort' => -1]);
        DB::table((new ShopProduct)->getTable())->where('id', $older)->update(['created_at' => now()->subDays(2)]);
        DB::table((new ShopProduct)->getTable())->where('id', $newer)->update(['created_at' => now()->subDay()]);

        $this->assertSame([$pinned, $newer, $older], $this->gridIds());
    }

    /**
     * Sort keys on the admin `sort` column are no longer accepted: they fall back to the default.
     */
    public function test_admin_sort_column_keys_fall_back_to_the_default_order(): void
    {
        $this->product('a', ['sort' => 1]);
        $this->product('b', ['sort' => 2]);
        $default = $this->gridIds();

        foreach (['sort_asc', 'sort_desc'] as $legacy) {
            $this->assertNull(ShopProduct::storefrontSort($legacy), "{$legacy} must not be a storefront sort");

            Livewire::withQueryParams(['filter_sort' => $legacy])
                ->test(ProductFilter::class)
                ->assertSet('sort', '');

            $this->assertSame($default, $this->gridIds($legacy), "{$legacy} must keep the default order");
        }
    }

    /**
     * An unknown key from the URL is dropped on mount, so "clear filters" does not show for nothing.
     */
    public function test_unknown_sort_from_url_is_reset_and_hides_clear_button(): void
    {
        Livewire::withQueryParams(['filter_sort' => "price_desc' OR 1=1 --"])
            ->test(ProductFilter::class)
            ->assertSet('sort', '')
            ->assertDontSeeHtml('data-testid="shop-product-filter-clear"');
    }

    /**
     * A value set from the client that is not on the allowlist is reset as well.
     */
    public function test_unknown_sort_set_from_client_is_reset(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('sort', 'stock_desc')
            ->assertSet('sort', '');
    }

    /**
     * Oldest first is no longer offered but old links keep working.
     */
    public function test_oldest_first_key_still_works_for_old_links(): void
    {
        $older = $this->product('older');
        $newer = $this->product('newer');
        DB::table((new ShopProduct)->getTable())->where('id', $older)->update(['created_at' => now()->subDays(2)]);

        $this->assertSame([$older, $newer], $this->gridIds('id_asc'));
        $this->assertSame([$newer, $older], $this->gridIds('id_desc'));
    }

    // -------------------------------------------------------------------------
    // Price: the price actually paid
    // -------------------------------------------------------------------------

    /**
     * A running promotion price decides the place; expired, future and disabled ones do not.
     */
    public function test_price_sort_uses_the_running_promotion_price(): void
    {
        $plain    = $this->product('plain', ['price' => 100]);
        $onSale   = $this->product('on-sale', ['price' => 300]);
        $expired  = $this->product('expired', ['price' => 200]);
        $future   = $this->product('future', ['price' => 250]);
        $disabled = $this->product('disabled', ['price' => 260]);
        $this->promote($onSale, 50, now()->subDay()->format('Y-m-d H:i:s'), now()->addDay()->format('Y-m-d H:i:s'));
        $this->promote($expired, 10, now()->subDays(5)->format('Y-m-d H:i:s'), now()->subDays(2)->format('Y-m-d H:i:s'));
        $this->promote($future, 20, now()->addDays(2)->format('Y-m-d H:i:s'), null);
        $this->promote($disabled, 30, null, null, 0);

        $this->assertSame([$onSale, $plain, $expired, $future, $disabled], $this->gridIds('price_asc'));
        $this->assertSame([$disabled, $future, $expired, $plain, $onSale], $this->gridIds('price_desc'));
    }

    /**
     * Group products have no price of their own, so they go last in both directions.
     */
    public function test_price_sort_puts_group_products_last(): void
    {
        $cheap = $this->product('cheap', ['price' => 5]);
        $dear  = $this->product('dear', ['price' => 500]);
        $group = $this->product('group', ['price' => 0, 'kind' => GP247_PRODUCT_GROUP]);

        $this->assertSame([$cheap, $dear, $group], $this->gridIds('price_asc'));
        $this->assertSame([$dear, $cheap, $group], $this->gridIds('price_desc'));
    }

    // -------------------------------------------------------------------------
    // Best selling and name
    // -------------------------------------------------------------------------

    /**
     * Best selling puts the most sold product first.
     */
    public function test_best_selling_orders_by_units_sold(): void
    {
        $few  = $this->product('few', ['sold' => 3]);
        $many = $this->product('many', ['sold' => 40]);
        $none = $this->product('none', ['sold' => 0]);

        $this->assertSame([$many, $few, $none], $this->gridIds('sold_desc'));
    }

    /**
     * Name A–Z orders by the product name in the shopper's language.
     */
    public function test_name_sort_orders_alphabetically(): void
    {
        $c = $this->product('Cherry');
        $a = $this->product('Apple');
        $b = $this->product('Banana');

        $this->assertSame([$a, $b, $c], $this->gridIds('name_asc'));
    }

    // -------------------------------------------------------------------------
    // Sort survives the other filters; controller listings share the allowlist
    // -------------------------------------------------------------------------

    /**
     * Applying a price range or a brand keeps the chosen order.
     */
    public function test_sort_survives_price_and_brand_filters(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('sort', 'price_desc')
            ->set('priceMin', '1')
            ->set('priceMax', '999')
            ->call('applyPrice')
            ->call('toggleBrand', 'some-brand')
            ->assertSet('sort', 'price_desc');
    }

    /**
     * The controller-rendered listings accept exactly the same keys as the grid.
     */
    public function test_controller_filter_uses_the_same_allowlist(): void
    {
        $controller = new ShopProductController();

        request()->merge(['filter_sort' => 'price_asc']);
        $this->assertSame(['price_final', 'asc'], $controller->processFilter(['sort'])['sort'] ?? null);

        request()->merge(['filter_sort' => 'sort_desc']);
        $this->assertArrayNotHasKey('sort', $controller->processFilter(['sort']));
    }

    // -------------------------------------------------------------------------
    // Labels
    // -------------------------------------------------------------------------

    /**
     * The upgrade migration seeds the two new labels in en, vi and ja without touching edited text.
     */
    public function test_upgrade_migration_seeds_new_labels_in_three_languages(): void
    {
        $table = GP247_DB_PREFIX . 'languages';
        DB::connection(GP247_DB_CONNECTION)->table($table)
            ->whereIn('code', ['filter_sort.featured', 'filter_sort.best_selling'])->delete();
        DB::connection(GP247_DB_CONNECTION)->table($table)->insert(
            ['code' => 'filter_sort.featured', 'text' => 'Owner text', 'position' => 'filter_sort', 'location' => 'en']
        );

        $migration = require base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_11_14_150000_seed_storefront_sort_labels.php');
        $migration->up();
        $migration->up();

        $rows = DB::connection(GP247_DB_CONNECTION)->table($table)
            ->whereIn('code', ['filter_sort.featured', 'filter_sort.best_selling'])
            ->get()->groupBy('code');

        foreach (['filter_sort.featured', 'filter_sort.best_selling'] as $code) {
            $this->assertEqualsCanonicalizing(['en', 'vi', 'ja'], $rows[$code]->pluck('location')->all(), $code);
        }
        $this->assertSame('Owner text', $rows['filter_sort.featured']->firstWhere('location', 'en')->text);
        $this->assertSame('売れ筋順', $rows['filter_sort.best_selling']->firstWhere('location', 'ja')->text);
    }
}
