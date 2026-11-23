<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An app asks only for the fields its screen needs with fields[<type>]=a,b: the answer
 * keeps those fields and the id, nothing outside the resource is ever reachable, unknown
 * names are a 422, and without the parameter the body is exactly what it was.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-sparse-fieldsets
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiSparseFieldsetsTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    /**
     * Keys of a public product in a v2 list before this change — the body without `fields`.
     */
    private const PUBLIC_PRODUCT_KEYS = [
        'id', 'sku', 'alias', 'name', 'keyword', 'description', 'image', 'images', 'price', 'price_final', 'tax_id', 'kind',
        'product_type', 'brand_id', 'category_ids', 'minimum', 'weight', 'weight_class', 'length', 'width', 'height',
        'length_class', 'date_available', 'store_id', 'created_at', 'updated_at', 'image_sizes', 'stock_status', 'attributes',
    ];

    public function test_without_fields_a_product_keeps_every_field_it_had(): void
    {
        $product = $this->makeProduct();

        $item = $this->getJson($this->v2Front() . '/product/list?q=' . $product->sku)->assertOk()->json('data.0');

        $this->assertSame(self::PUBLIC_PRODUCT_KEYS, array_keys($item));
    }

    public function test_fields_keep_only_the_chosen_fields_and_the_id_in_lists_and_details(): void
    {
        $product = $this->makeProduct();

        $list = $this->getJson($this->v2Front() . '/product/list?q=' . $product->sku . '&fields[product]=name,price_final')->assertOk();
        $this->assertSame(['id', 'name', 'price_final'], array_keys($list->json('data.0')));
        $this->assertNotNull($list->json('meta.total'));

        $detail = $this->getJson($this->v2Front() . '/product/detail/' . $product->id . '?fields[product]=sku,content')->assertOk()->json('data');
        $this->assertSame(['id', 'sku', 'content'], array_keys($detail));
    }

    public function test_an_unknown_field_or_type_is_rejected(): void
    {
        $base = $this->v2Front() . '/product/list';

        $this->getJson($base . '?fields[product]=name,zz_nope')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson($base . '?fields[order]=total')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        $this->getJson($base . '?fields[product][]=name')->assertStatus(422)->assertJson(['code' => 'validation_failed']);
    }

    public function test_choosing_fields_never_opens_a_field_the_caller_could_not_see(): void
    {
        $product = $this->makeProduct();

        $this->getJson($this->v2Front() . '/product/detail/' . $product->id . '?fields[product]=cost')->assertStatus(422);

        $this->pinAdminStoreContext(null, null);
        $token = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $admin = $this->getWithToken($this->v2Core() . '/product/detail/' . $product->id . '?fields[product]=cost', $token)->assertOk()->json('data');
        $this->assertSame(['id', 'cost'], array_keys($admin));
    }

    public function test_fields_apply_to_other_resources_and_to_sync_mode(): void
    {
        $this->pinAdminStoreContext(null, null);
        $token = $this->tokenFor($this->makeAdmin(['admin_order.index', 'admin_product.index']), ['admin']);
        $order = $this->makeOrder(['email' => 'zzfields' . uniqid() . '@example.com']);

        $orders = $this->getWithToken($this->v2Core() . '/order/list?q=' . urlencode($order->email) . '&fields[order]=status,total', $token)->assertOk();
        $this->assertSame(['id', 'status', 'total'], array_keys($orders->json('data.0')));

        $this->makeProduct();
        $sync = $this->getWithToken($this->v2Core() . '/product/list?updated_since=2000-01-01T00:00:00Z&page[size]=2&fields[product]=sku', $token)->assertOk();
        $this->assertSame(['id', 'sku'], array_keys($sync->json('data.0')));
        $this->assertNotNull($sync->json('meta.next_after'));
    }
}
