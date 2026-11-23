<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP syncs stock in batches of up to 100 lines by id or SKU: every line has its own
 * result, a bad line never spoils the good ones, and a retried batch moves nothing twice.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-stock-batch
 * @aidlc-adr api-admin-write_stock-commands
 */
#[Group('api-admin-write')]
class ApiStockBatchTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;

    public function test_every_line_of_a_batch_has_its_own_result_and_a_bad_line_does_not_spoil_the_others(): void
    {
        $a = $this->makeProduct(['stock' => 10]);
        $b = $this->makeProduct(['stock' => 1]);

        $body = $this->batch($this->writer(), [
            ['id' => $a->id, 'delta' => 5],
            ['sku' => $b->sku, 'delta' => -3],
            ['sku' => 'ZZ-NO-SUCH-SKU', 'delta' => 1],
            ['sku' => $b->sku, 'set' => 7],
        ], 'zz-batch-0000001')->assertOk()->json('data');

        $this->assertSame(2, $body['ok']);
        $this->assertSame(2, $body['failed']);
        $this->assertTrue($body['results'][0]['ok']);
        $this->assertEquals(15, $body['results'][0]['stock']);
        $this->assertSame('stock_would_be_negative', $body['results'][1]['code']);
        $this->assertSame('sku_not_found', $body['results'][2]['code']);
        $this->assertTrue($body['results'][3]['ok']);
        $this->assertEquals(15, (float) ShopProduct::find($a->id)->stock);
        $this->assertEquals(7, (float) ShopProduct::find($b->id)->stock);
    }

    public function test_a_sku_shared_by_two_products_or_belonging_to_another_store_is_reported_on_its_line(): void
    {
        $sku = 'ZZ-DUP-' . strtoupper(uniqid());
        $this->makeProduct(['sku' => $sku]);
        $this->makeProduct(['sku' => $sku]);
        $rootProduct = $this->makeProduct(['stock' => 10]);

        $results = $this->batch($this->writer(), [['sku' => $sku, 'delta' => 1]], 'zz-batch-0000002')->assertOk()->json('data.results');
        $this->assertSame('sku_ambiguous', $results[0]['code']);

        // An account working in another store does not reach the root store's products.
        $storeId = $this->otherStoreId();
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);
        $manager = $this->tokenFor($this->adminWithUris(['ANY::' . $this->productScreen() . '/*']), ['admin']);
        $results = $this->batch($manager, [['id' => $rootProduct->id, 'delta' => 1], ['sku' => $rootProduct->sku, 'delta' => 1]], 'zz-batch-0000006')
            ->assertOk()->json('data.results');

        $this->assertSame('not_found', $results[0]['code']);
        $this->assertSame('sku_not_found', $results[1]['code']);
        $this->assertEquals(10, (float) ShopProduct::find($rootProduct->id)->stock);
    }

    public function test_a_batch_over_one_hundred_lines_is_refused_without_moving_anything(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $lines = array_fill(0, 101, ['id' => $product->id, 'delta' => 1]);

        $this->batch($this->writer(), $lines, 'zz-batch-0000003')->assertStatus(422);
        $this->batch($this->writer(), [['id' => $product->id, 'sku' => $product->sku, 'delta' => 1]], 'zz-batch-0000007')->assertStatus(422);
        $this->assertEquals(10, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_a_retried_batch_with_the_same_key_moves_nothing_twice(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $token = $this->writer();
        $lines = [['id' => $product->id, 'delta' => -2]];

        $first = $this->batch($token, $lines, 'zz-batch-0000004')->assertOk()->json();
        $again = $this->batch($token, $lines, 'zz-batch-0000004')->assertOk()->assertHeader('Idempotent-Replayed', 'true')->json();

        $this->assertSame($first, $again);
        $this->assertEquals(8, (float) ShopProduct::find($product->id)->stock);
    }

    public function test_a_set_line_of_an_account_that_is_not_administrator_is_refused_on_its_own(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $manager = $this->tokenFor($this->adminWithUris(['ANY::' . $this->productScreen() . '/*']), ['admin']);

        $results = $this->batch($manager, [['id' => $product->id, 'set' => 99], ['id' => $product->id, 'delta' => 1]], 'zz-batch-0000005')
            ->assertOk()->json('data.results');

        $this->assertSame('forbidden', $results[0]['code']);
        $this->assertTrue($results[1]['ok']);
        $this->assertEquals(11, (float) ShopProduct::find($product->id)->stock);
    }

    /**
     * @param string $token
     * @param array  $items
     * @param string $key
     * @return \Illuminate\Testing\TestResponse
     */
    private function batch(string $token, array $items, string $key)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Idempotency-Key' => $key])
            ->postJson($this->v2Core() . '/stock/batch', ['items' => $items]);
    }

    /**
     * @return string
     */
    private function writer(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
