<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Models\ShopOrderHistory;
use GP247\Shop\Models\ShopOrderTransaction;
use GP247\Shop\Models\ShopProductImage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An app gets related records in the same call with include=a,b: only relations declared
 * for the resource and the caller, one level deep, at most three, each loaded with one
 * query for the whole page, and filtered by the same visibility rules as the record
 * itself. Without include the body is exactly what it was.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-include-relations
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiIncludeRelationsTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    /**
     * Brand of the root store.
     *
     * @param int $status
     * @return ShopBrand
     */
    private function makeBrand(int $status = 1): ShopBrand
    {
        return ShopBrand::create(['name' => 'ZZ inc brand', 'alias' => 'zz-inc-brand-' . uniqid(), 'status' => $status, 'store_id' => (string) GP247_STORE_ID_ROOT]);
    }

    /**
     * Category of the root store with a description in the current locale.
     *
     * @param int $status
     * @return ShopCategory
     */
    private function makeCategory(int $status = 1): ShopCategory
    {
        $category = ShopCategory::create(['alias' => 'zz-inc-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0, 'status' => $status, 'sort' => 0, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        ShopCategoryDescription::create(['category_id' => $category->id, 'lang' => gp247_get_locale(), 'name' => 'ZZ inc category', 'keyword' => '', 'description' => '']);
        return $category;
    }

    public function test_without_include_nothing_is_added(): void
    {
        $product = $this->makeProduct(['brand_id' => $this->makeBrand()->id]);

        $item = $this->getJson($this->v2Front() . '/product/list?q=' . $product->sku)->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('brand', $item);
        $this->assertArrayNotHasKey('categories', $item);
        $this->assertSame([], $item['images']);
    }

    public function test_a_product_list_can_carry_its_brand_categories_and_images(): void
    {
        $brand = $this->makeBrand();
        $shown = $this->makeCategory();
        $hidden = $this->makeCategory(0);
        $product = $this->makeProduct(['brand_id' => $brand->id]);
        $product->categories()->attach([$shown->id, $hidden->id]);
        ShopProductImage::create(['product_id' => $product->id, 'image' => '/data/zz-inc.jpg']);

        $item = $this->getJson($this->v2Front() . '/product/list?q=' . $product->sku . '&include=brand,categories,images')->assertOk()->json('data.0');

        $this->assertSame($brand->id, $item['brand']['id']);
        $this->assertSame('ZZ inc brand', $item['brand']['name']);
        $this->assertSame([$shown->id], collect($item['categories'])->pluck('id')->all());
        $this->assertSame('ZZ inc category', $item['categories'][0]['name']);
        $this->assertSame(['/data/zz-inc.jpg'], $item['images']);
        $this->assertSame([$shown->id], $item['category_ids']);
    }

    public function test_a_disabled_brand_is_not_carried_and_included_records_take_their_own_fields(): void
    {
        $off = $this->makeProduct(['brand_id' => $this->makeBrand(0)->id]);
        $this->assertNull($this->getJson($this->v2Front() . '/product/detail/' . $off->id . '?include=brand')->assertOk()->json('data.brand'));

        $on = $this->makeProduct(['brand_id' => $this->makeBrand()->id]);
        $brand = $this->getJson($this->v2Front() . '/product/detail/' . $on->id . '?include=brand&fields[product]=name,brand&fields[brand]=name')->assertOk()->json('data');
        $this->assertSame(['id', 'name', 'brand'], array_keys($brand));
        $this->assertSame(['id', 'name'], array_keys($brand['brand']));
    }

    public function test_each_included_relation_costs_one_query_whatever_the_page_size(): void
    {
        $sku = 'ZZINCQ-' . strtoupper(uniqid());
        $brand = $this->makeBrand();
        $category = $this->makeCategory();
        for ($i = 0; $i < 6; $i++) {
            $this->makeProduct(['sku' => $sku . $i, 'brand_id' => $brand->id])->categories()->attach($category->id);
        }
        $uri = fn (int $size) => $this->v2Front() . '/product/list?q=' . $sku . '&page[size]=' . $size . '&include=brand,categories,images';
        // WHY a warm-up call: the first request fills per-process memos (config, languages).
        $this->getJson($uri(6))->assertOk();

        $this->assertSame($this->queriesFor($uri(2)), $this->queriesFor($uri(6)));
    }

    /**
     * Number of queries one GET runs on the GP247 connection.
     *
     * @param string $uri
     * @return int
     */
    private function queriesFor(string $uri): int
    {
        $connection = DB::connection(GP247_DB_CONNECTION);
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->getJson($uri)->assertOk();
        $count = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        return $count;
    }

    public function test_an_unknown_nested_or_excessive_include_is_rejected(): void
    {
        $base = $this->v2Front() . '/product/list?include=';

        $this->getJson($base . 'zz_nope')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson($base . 'brand.products')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson($this->v2Front() . '/product/list?include[]=brand')->assertStatus(422);

        config(['gp247-config.api.query.max_include' => 1]);
        $this->getJson($base . 'brand,images')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_an_admin_order_can_carry_its_payments_and_plain_text_history_but_a_customer_order_cannot(): void
    {
        $this->pinAdminStoreContext(null, null);
        $token = $this->tokenFor($this->makeAdmin(['admin_order.index', 'admin_order.detail']), ['admin']);
        $order = $this->makeOrder(['total' => 50]);
        ShopOrderTransaction::create(['order_id' => $order->id, 'type' => 'payment', 'amount' => 20, 'amount_base' => 20, 'currency' => 'USD', 'method' => 'manual', 'paid_at' => now()]);
        ShopOrderHistory::create(['order_id' => $order->id, 'content' => '<b>Paid</b> by <i>cash</i>', 'admin_id' => 0, 'customer_id' => 0, 'order_status_id' => 1]);

        $data = $this->getWithToken($this->v2Core() . '/order/detail/' . $order->id . '?include=transactions,histories', $token)->assertOk()->json('data');

        $this->assertSame('payment', $data['transactions'][0]['type']);
        $this->assertEquals(20.0, (float) $data['transactions'][0]['amount']);
        $this->assertSame('Paid by cash', $data['histories'][0]['content']);

        $customer = $this->makeCustomer();
        $mine = $this->makeOrder(['customer_id' => $customer->id]);
        $this->getWithToken($this->v2Front() . '/member/order/detail/' . $mine->id . '?include=transactions', $this->tokenFor($customer, ['user']))
            ->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }
}
