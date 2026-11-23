<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * v2 lists accept declared filter/sort fields, a search term, a language and a page
 * size capped at 100; anything outside the declared lists is a 422, and products
 * carry the price the storefront charges (price_final).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-v2-query-parameters
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiV2QueryParametersTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private const PRODUCTS = '/api/v2/front/product/list';

    public function test_unknown_filter_or_sort_field_is_rejected(): void
    {
        $this->getJson(self::PRODUCTS . '?filter[cost]=1')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson(self::PRODUCTS . '?sort=cost')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_page_size_is_capped_at_100(): void
    {
        $this->makeProduct();

        $this->getJson(self::PRODUCTS . '?page[size]=500')->assertOk()->assertJson(['meta' => ['per_page' => 100]]);
    }

    public function test_search_finds_a_product_by_sku(): void
    {
        $product = $this->makeProduct(['sku' => 'ZZQSKU-' . strtoupper(uniqid())]);

        $ids = collect($this->getJson(self::PRODUCTS . '?q=' . urlencode($product->sku))->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$product->id], $ids->all());
    }

    public function test_brand_filter_keeps_only_that_brand(): void
    {
        $brand = ShopBrand::create(['name' => 'ZZ brand', 'alias' => 'zz-brand-' . uniqid(), 'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        $inBrand = $this->makeProduct(['brand_id' => $brand->id]);
        $other = $this->makeProduct();

        $ids = collect($this->getJson(self::PRODUCTS . '?filter[brand_id]=' . $brand->id . '&page[size]=100')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($inBrand->id, $ids->all());
        $this->assertNotContains($other->id, $ids->all());
    }

    public function test_sort_by_price_descending(): void
    {
        $sku = 'ZZSORT-' . strtoupper(uniqid());
        $this->makeProduct(['sku' => $sku . 'A', 'price' => 10]);
        $this->makeProduct(['sku' => $sku . 'B', 'price' => 90]);

        $prices = collect($this->getJson(self::PRODUCTS . '?q=' . $sku . '&sort=-price')->assertOk()->json('data'))->pluck('price')->map(fn ($p) => (float) $p);

        $this->assertSame([90.0, 10.0], $prices->all());
    }

    public function test_lang_picks_the_description_of_that_language(): void
    {
        $product = $this->makeProduct();
        ShopProductDescription::create(['product_id' => $product->id, 'lang' => 'vi', 'name' => 'ZZ tên tiếng Việt', 'description' => '', 'content' => '']);

        $data = $this->getJson('/api/v2/front/product/detail/' . $product->id . '?lang=vi')->assertOk()->json('data');

        $this->assertSame('ZZ tên tiếng Việt', $data['name']);
    }

    public function test_price_final_matches_the_storefront_price(): void
    {
        $product = $this->makeProduct(['price' => 120]);

        $data = $this->getJson('/api/v2/front/product/detail/' . $product->id)->assertOk()->json('data');

        $this->assertEquals((float) ShopProduct::find($product->id)->getFinalPrice(), (float) $data['price_final']);
    }

    public function test_admin_order_list_filters_by_status(): void
    {
        $this->pinAdminStoreContext(null, null);
        $token = $this->tokenFor($this->makeAdmin(['admin_order.index']), ['admin']);
        $match = $this->makeOrder(['status' => 2, 'email' => 'zzv2filter' . uniqid() . '@example.com']);

        $data = $this->getWithToken('/api/v2/core/order/list?filter[status]=2&q=' . urlencode($match->email), $token)->assertOk()->json('data');

        $this->assertSame([$match->id], collect($data)->pluck('id')->all());
    }

    public function test_array_search_or_sort_values_are_rejected_not_crashing(): void
    {
        $this->getJson(self::PRODUCTS . '?q[]=x')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson(self::PRODUCTS . '?sort[]=price')->assertStatus(422);
    }

    public function test_lists_leave_out_the_html_body_that_details_carry(): void
    {
        $product = $this->makeProduct(['sku' => 'ZZBODY-' . strtoupper(uniqid())]);

        $item = $this->getJson(self::PRODUCTS . '?q=' . $product->sku)->assertOk()->json('data.0');
        $detail = $this->getJson('/api/v2/front/product/detail/' . $product->id)->assertOk()->json('data');

        $this->assertArrayNotHasKey('content', $item);
        $this->assertArrayHasKey('content', $detail);
    }
}
