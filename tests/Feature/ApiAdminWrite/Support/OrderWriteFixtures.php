<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite\Support;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderHistory;
use GP247\Shop\Models\ShopProduct;

/**
 * Orders and admin accounts for the admin-write API tests (with ApiFixtures).
 */
trait OrderWriteFixtures
{
    /**
     * Admin whose permission catalog is exactly $httpUris (e.g. "ANY::gp247_admin/order/*").
     *
     * @param string[] $httpUris
     * @return AdminUser
     */
    protected function adminWithUris(array $httpUris): AdminUser
    {
        $admin = $this->makeAdmin();
        $suffix = uniqid();
        $permission = AdminPermission::create(['name' => 'ZZ write ' . $suffix, 'slug' => 'zz_write_' . $suffix, 'http_uri' => implode(',', $httpUris)]);
        $role = AdminRole::create(['name' => 'ZZ write role ' . $suffix, 'slug' => 'zz_write_role_' . $suffix]);
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        return $admin->fresh();
    }

    /**
     * Admin path of the order screen (as the admin screens check it).
     *
     * @return string
     */
    protected function orderScreen(): string
    {
        return ltrim((string) parse_url(route('admin_order.index'), PHP_URL_PATH), '/');
    }

    /**
     * Admin path of the product screen.
     *
     * @return string
     */
    protected function productScreen(): string
    {
        return ltrim((string) parse_url(route('admin_product.index'), PHP_URL_PATH), '/');
    }

    /**
     * Admin path of the customer screen.
     *
     * @return string
     */
    protected function customerScreen(): string
    {
        return ltrim((string) parse_url(route('admin_customer.index'), PHP_URL_PATH), '/');
    }

    /**
     * Order of one product (qty 2) whose stock is already net of the order.
     *
     * @param int   $stock
     * @param array $attributes
     * @return array{0: ShopOrder, 1: ShopProduct}
     */
    protected function orderWithStock(int $stock = 98, array $attributes = []): array
    {
        $product = $this->makeProduct(['stock' => $stock]);
        $order = $this->makeOrder($attributes);
        ShopOrderDetail::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'name' => 'ZZ Item', 'sku' => $product->sku,
            'price' => 50, 'qty' => 2, 'total_price' => 100, 'tax' => 0, 'attribute' => '[]',
            'currency' => $order->currency, 'exchange_rate' => $order->exchange_rate,
        ]);

        return [ShopOrder::find($order->id), ShopProduct::find($product->id)];
    }

    /**
     * @param ShopOrder $order
     * @return \Illuminate\Support\Collection
     */
    protected function historyOf(ShopOrder $order)
    {
        return ShopOrderHistory::where('order_id', $order->id)->orderBy('add_date')->get();
    }

    /**
     * @param string $token
     * @param string $path  After api/v2/core/.
     * @param array  $body
     * @return \Illuminate\Testing\TestResponse
     */
    protected function write(string $token, string $path, array $body)
    {
        return $this->apiCall('POST', $this->v2Core() . '/' . $path, $token, $body);
    }
}
