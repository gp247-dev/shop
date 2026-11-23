<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Public and member REST API return declared fields only and only records the
 * storefront of the calling domain would show: no cost price, supplier, internal
 * stock counters, approval flags, unapproved/hidden products, or order forensics.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-public-field-allowlist
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiPublicFieldAllowlistTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private const PRODUCT_INTERNAL = ['cost', 'supplier_id', 'stock', 'sold', 'approve', 'view', 'date_lastview'];
    private const ORDER_INTERNAL = ['ip', 'user_agent', 'device_type', 'checkout_token', 'transaction', 'domain'];

    public function test_public_product_detail_hides_internal_fields(): void
    {
        $product = $this->makeProduct();

        $json = $this->getJson($this->frontApi() . '/product/detail/' . $product->id)->assertOk()->json();

        $this->assertSame($product->id, $json['id']);
        foreach (self::PRODUCT_INTERNAL as $field) {
            $this->assertArrayNotHasKey($field, $json, "Public product must not expose {$field}");
        }
        $this->assertArrayHasKey('price', $json);
        $this->assertArrayHasKey('descriptions', $json);
    }

    public function test_public_product_list_items_hide_internal_fields(): void
    {
        $this->makeProduct();

        $items = $this->getJson($this->frontApi() . '/product/list')->assertOk()->json('data');

        $this->assertNotEmpty($items);
        foreach ($items as $item) {
            foreach (self::PRODUCT_INTERNAL as $field) {
                $this->assertArrayNotHasKey($field, $item);
            }
        }
    }

    public function test_unapproved_product_is_not_served_publicly(): void
    {
        $product = $this->makeProduct(['approve' => 0]);

        $this->getJson($this->frontApi() . '/product/detail/' . $product->id)->assertJsonMissing(['id' => $product->id]);
    }

    public function test_disabled_product_is_not_served_publicly(): void
    {
        $product = $this->makeProduct(['status' => 0]);

        $this->getJson($this->frontApi() . '/product/detail/' . $product->id)->assertJsonMissing(['id' => $product->id]);
    }

    public function test_product_of_another_store_follows_storefront_visibility_of_the_domain(): void
    {
        config(['app.storeId' => GP247_STORE_ID_ROOT]);
        $product = $this->makeProduct(['store_id' => $this->otherStoreId()]);

        $response = $this->getJson($this->frontApi() . '/product/detail/' . $product->id);

        if (gp247_store_check_multi_domain_installed()) {
            $response->assertJsonMissing(['id' => $product->id]);
        } else {
            $response->assertOk()->assertJson(['id' => $product->id]);
        }
    }

    public function test_category_detail_lists_only_visible_products_without_internal_fields(): void
    {
        $category = ShopCategory::create(['alias' => 'zz-api-cat-' . uniqid(), 'parent' => '', 'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        ShopCategoryDescription::create(['category_id' => $category->id, 'lang' => gp247_get_locale(), 'name' => 'ZZ', 'keyword' => '', 'description' => '']);
        $visible = $this->makeProduct();
        $hidden = $this->makeProduct(['status' => 0]);
        $category->products()->attach([$visible->id, $hidden->id]);

        $products = collect($this->getJson($this->frontApi() . '/category/detail/' . $category->id)->assertOk()->json('products'));

        $this->assertSame([$visible->id], $products->pluck('id')->all());
        foreach (self::PRODUCT_INTERNAL as $field) {
            $this->assertArrayNotHasKey($field, $products->first());
        }
    }

    public function test_member_order_detail_hides_order_forensics(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder([
            'customer_id' => $customer->id, 'ip' => '10.1.2.3', 'user_agent' => 'ZZ-UA',
            'checkout_token' => 'zz-token-' . uniqid(), 'transaction' => 'zz-tx',
        ]);

        $json = $this->getWithToken($this->frontApi() . '/member/order/detail/' . $order->id, $this->tokenFor($customer, ['user']))
            ->assertOk()->json();

        $this->assertSame($order->id, $json['id']);
        foreach (self::ORDER_INTERNAL as $field) {
            $this->assertArrayNotHasKey($field, $json, "Member order must not expose {$field}");
        }
    }

    public function test_member_order_list_hides_order_forensics(): void
    {
        $customer = $this->makeCustomer();
        $this->makeOrder(['customer_id' => $customer->id, 'ip' => '10.1.2.3', 'user_agent' => 'ZZ-UA']);

        $items = $this->getWithToken($this->frontApi() . '/member/order/list', $this->tokenFor($customer, ['user']))
            ->assertOk()->json('data');

        $this->assertCount(1, $items);
        foreach (self::ORDER_INTERNAL as $field) {
            $this->assertArrayNotHasKey($field, $items[0]);
        }
    }

    public function test_member_info_hides_credentials_and_store(): void
    {
        $customer = $this->makeCustomer();

        $json = $this->getWithToken($this->frontApi() . '/info', $this->tokenFor($customer, ['user']))->assertOk()->json();

        $this->assertSame($customer->email, $json['email']);
        foreach (['password', 'remember_token', 'store_id'] as $field) {
            $this->assertArrayNotHasKey($field, $json);
        }
    }

    public function test_brand_banner_and_page_lists_return_declared_fields_only(): void
    {
        $specs = [
            'brand'  => \GP247\Shop\Api\Support\ShopApiFields::BRAND,
            'banner' => \GP247\Front\Api\FrontApiFields::BANNER,
            'page'   => \GP247\Front\Api\FrontApiFields::PAGE,
        ];
        foreach ($specs as $resource => $spec) {
            $allowed = array_map(fn ($k, $v) => is_int($k) ? $v : $k, array_keys($spec), $spec);
            foreach ($this->getJson($this->frontApi() . '/' . $resource . '/list')->assertOk()->json('data') as $item) {
                $this->assertSame([], array_values(array_diff(array_keys($item), $allowed)), "{$resource} exposes undeclared fields");
            }
        }
    }
}
