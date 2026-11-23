<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\TestCase;

/**
 * Server contract behind the admin create-order client-side over-stock warning
 * (US-SADM-order-create-stock-feedback, QĐ-2/PA-Yes).
 *
 * The Alpine warning compares qty against a RAW numeric stock, so the product
 * picker endpoint (admin_order.product_search) must expose `stock_raw` alongside
 * the display-only formatted `stock`. The client-side warning + submit gating
 * themselves live in Blade+Alpine and are covered by E2E
 * (E2E-admin-order-create); this test locks the data contract they depend on.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-create-stock-feedback
 */
class OrderCreateStockWarningTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Root store id (shop tests run against the root store).
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1);
    }

    /**
     * Create + authenticate an administrator (short-circuits URI permission).
     *
     * @return void
     */
    private function loginAdministrator(): void
    {
        $user = AdminUser::create([
            'id' => 'AU-STK-' . substr(uniqid(), -6),
            'username' => 'stock_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Stock Admin',
            'email' => 'stock' . substr(uniqid(), -6) . '@test.local',
            'status' => 1,
        ]);
        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);
        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->storeId()]);
    }

    /**
     * A searchable product with a known SKU and on-hand stock.
     *
     * @param string $sku
     * @param float $stock
     * @return ShopProduct
     */
    private function makeSearchableProduct(string $sku, float $stock): ShopProduct
    {
        $product = ShopProduct::create([
            'sku' => $sku,
            'alias' => strtolower($sku),
            'price' => 50,
            'stock' => $stock,
            'status' => 1,
            'kind' => 0,
        ]);

        foreach (array_keys(AdminLanguage::getListActive()->all()) as $code) {
            ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => 'Stock Item ' . $sku,
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }

        return $product;
    }

    /**
     * Find one row for the given product id in the picker JSON payload.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param int|string $productId
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, $productId): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row['id'] ?? '') === (string) $productId) {
                return $row;
            }
        }

        return null;
    }

    public function test_product_search_exposes_raw_numeric_stock(): void
    {
        $this->loginAdministrator();
        $sku = 'ZZ-STK-' . substr(uniqid(), -6);
        $product = $this->makeSearchableProduct($sku, 7);

        $rows = $this->get(route('admin_order.product_search', ['term' => $sku]))
            ->assertOk()
            ->json();

        $row = $this->rowFor($rows, $product->id);
        $this->assertNotNull($row, 'Picker must return the created product.');
        // Raw numeric stock is what the client compares qty against.
        $this->assertArrayHasKey('stock_raw', $row);
        $this->assertSame(7.0, (float) $row['stock_raw']);
        // Display-only formatted stock is kept as-is for the dropdown label.
        $this->assertArrayHasKey('stock', $row);
    }

    public function test_product_search_stock_raw_reflects_zero_stock(): void
    {
        $this->loginAdministrator();
        $sku = 'ZZ-STK0-' . substr(uniqid(), -6);
        $product = $this->makeSearchableProduct($sku, 0);

        $rows = $this->get(route('admin_order.product_search', ['term' => $sku]))
            ->assertOk()
            ->json();

        $row = $this->rowFor($rows, $product->id);
        $this->assertNotNull($row);
        // A zero-stock product must report 0 (not null/absent) so any qty >= 1
        // trips the client warning when overselling is forbidden.
        $this->assertSame(0.0, (float) $row['stock_raw']);
    }
}
