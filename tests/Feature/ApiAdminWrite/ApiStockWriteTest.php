<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Goods received / issued in the ERP move the site's stock through the admin API:
 * atomically (an order placed at the same time loses nothing), never below zero, never
 * twice for a retried request, without touching the sold counter. An administrator can
 * also set the stock outright after a stock count.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-stock-delta, US-api-admin-write-stock-absolute
 * @aidlc-adr api-admin-write_stock-commands
 */
#[Group('api-admin-write')]
class ApiStockWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;

    public function test_receiving_and_issuing_goods_moves_the_stock_without_touching_the_sold_counter(): void
    {
        $product = $this->makeProduct(['stock' => 10, 'sold' => 3]);
        $token = $this->writer();

        $this->stock($token, $product, ['delta' => 5], 'zz-stk-00000001')->assertOk()->assertJsonPath('data.stock', 15);
        $this->stock($token, $product, ['delta' => -4], 'zz-stk-00000002')->assertOk()->assertJsonPath('data.stock', 11);

        $fresh = ShopProduct::find($product->id);
        $this->assertEquals(11, (float) $fresh->stock);
        $this->assertEquals(3, (float) $fresh->sold);
    }

    public function test_issuing_more_than_is_in_stock_is_refused_and_the_stock_stays(): void
    {
        $product = $this->makeProduct(['stock' => 2]);

        $this->stock($this->writer(), $product, ['delta' => -3], 'zz-stk-00000003')
            ->assertStatus(422)->assertJsonPath('code', 'stock_would_be_negative');

        $this->assertEquals(2, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_a_retried_request_with_the_same_key_moves_the_stock_once(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $token = $this->writer();

        $this->stock($token, $product, ['delta' => -1], 'zz-stk-00000004')->assertOk();
        $this->stock($token, $product, ['delta' => -1], 'zz-stk-00000004')->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertEquals(9, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_a_stock_write_without_an_idempotency_key_is_refused(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $this->stock($this->writer(), $product, ['delta' => 1], null)->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
        $this->assertEquals(10, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_only_an_administrator_may_set_the_stock_outright(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $manager = $this->tokenFor($this->adminWithUris(['ANY::' . $this->productScreen() . '/*']), ['admin']);

        $this->stock($manager, $product, ['set' => 50], 'zz-stk-00000005')->assertStatus(403);
        $this->stock($this->writer(), $product, ['set' => 50], 'zz-stk-00000006')->assertOk()->assertJsonPath('data.stock', 50);
        $this->stock($this->writer(), $product, ['set' => -1], 'zz-stk-00000007')->assertStatus(422);
        $this->stock($manager, $product, ['delta' => 1], 'zz-stk-00000008')->assertOk()->assertJsonPath('data.stock', 51);
    }

    public function test_sending_both_or_neither_of_delta_and_set_is_refused(): void
    {
        $product = $this->makeProduct(['stock' => 10]);

        $this->stock($this->writer(), $product, ['delta' => 1, 'set' => 5], 'zz-stk-00000009')->assertStatus(422);
        $this->stock($this->writer(), $product, [], 'zz-stk-00000010')->assertStatus(422);
    }

    public function test_a_build_product_whose_stock_comes_from_its_components_is_refused(): void
    {
        $product = $this->makeProduct(['stock' => 10, 'kind' => GP247_PRODUCT_BUILD]);

        $this->stock($this->writer(), $product, ['delta' => 1], 'zz-stk-00000011')->assertStatus(422)->assertJsonPath('code', 'stock_managed_by_components');
        $this->stock($this->writer(), $product, ['set' => 1], 'zz-stk-00000012')->assertStatus(422)->assertJsonPath('code', 'stock_managed_by_components');
    }

    public function test_an_order_taking_stock_between_two_erp_moves_loses_no_update(): void
    {
        $product = $this->makeProduct(['stock' => 20]);
        $token = $this->writer();

        $this->stock($token, $product, ['delta' => -5], 'zz-stk-00000013')->assertOk();
        ShopProduct::updateStock($product->id, 2);
        $this->stock($token, $product, ['delta' => 3], 'zz-stk-00000014')->assertOk();

        $this->assertEquals(16, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_a_product_of_another_store_is_not_found(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $storeId = $this->otherStoreId();
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);

        $this->stock($this->tokenFor($this->adminWithUris(['ANY::' . $this->productScreen() . '/*']), ['admin']), $product, ['delta' => 1], 'zz-stk-00000015')
            ->assertStatus(404);
    }

    /**
     * @param string      $token
     * @param ShopProduct $product
     * @param array       $body
     * @param string|null $key
     * @return \Illuminate\Testing\TestResponse
     */
    private function stock(string $token, ShopProduct $product, array $body, ?string $key)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer ' . $token);
        if ($key !== null) {
            $this->withHeader('Idempotency-Key', $key);
        }

        return $this->postJson($this->v2Core() . '/product/' . $product->id . '/stock', $body);
    }

    /**
     * @return string
     */
    private function writer(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
