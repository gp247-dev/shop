<?php

namespace GP247\Shop\Tests\Feature\ApiCommerce\Support;

use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;

/**
 * Shared set-up of the cart / checkout API tests: API switches on, shop settings that
 * make a product sellable, products with attribute groups, and cart calls with an
 * optional bearer token and cart token.
 */
trait ApiCommerceFixtures
{
    use ApiFixtures;

    /**
     * Turn the shop settings the cart depends on to a known state (all stores, rolled back).
     *
     * @param array<string, string> $values key => value
     * @return void
     */
    protected function setShopConfig(array $values): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        foreach ($values as $key => $value) {
            if ($table->clone()->where('key', $key)->exists()) {
                $table->clone()->where('key', $key)->update(['value' => $value]);
            } else {
                $table->clone()->insert(['key' => $key, 'value' => $value, 'store_id' => GP247_STORE_ID_GLOBAL,
                    'group' => 'gp247_cart', 'code' => 'zz_api_commerce', 'sort' => 0, 'detail' => '']);
            }
        }
        $this->forgetConfigMemo();
    }

    /**
     * Sellable defaults: prices shown, stock tracked, no selling out of stock, guests allowed.
     *
     * @return void
     */
    protected function useSellableShop(): void
    {
        $this->setShopConfig([
            'product_price' => '1', 'product_stock' => '1', 'product_buy_out_of_stock' => '0',
            'product_preorder' => '1', 'shop_allow_guest' => '1',
        ]);
    }

    /**
     * Give a product one attribute group with the given options.
     *
     * @param ShopProduct              $product
     * @param array<string, int|float> $options name => add_price
     * @return ShopAttributeGroup
     */
    protected function addAttributeGroup(ShopProduct $product, array $options): ShopAttributeGroup
    {
        $group = ShopAttributeGroup::create(['name' => 'ZZ Grp ' . uniqid(), 'type' => 'radio', 'status' => 1, 'sort' => 0]);
        foreach ($options as $name => $price) {
            ShopProductAttribute::create(['name' => $name, 'attribute_group_id' => $group->id, 'product_id' => $product->id,
                'add_price' => $price, 'sort' => 0, 'status' => 1]);
        }

        return $group;
    }

    /**
     * Call the cart API with an optional customer token and cart token.
     *
     * @param string      $method
     * @param string      $path      Path under api/v2/front, e.g. "/cart/items".
     * @param string|null $token     Customer bearer token.
     * @param string|null $cartToken Guest cart token (X-Cart-Token).
     * @param array       $data
     * @return \Illuminate\Testing\TestResponse
     */
    protected function cartCall(string $method, string $path, ?string $token = null, ?string $cartToken = null, array $data = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($token !== null) {
            $this->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($cartToken !== null) {
            $this->withHeader('X-Cart-Token', $cartToken);
        }

        return $this->json($method, $this->v2Front() . $path, $data);
    }

    /**
     * Lines of the first store group of a cart response.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @return array
     */
    protected function linesOf($response): array
    {
        return $response->json('data.stores.0.lines') ?? [];
    }

    /**
     * Install + enable a payment plugin at GLOBAL with the given settings.
     *
     * @param string               $key
     * @param array<string,string> $settings
     * @return void
     */
    protected function installPaymentPlugin(string $key, array $settings = []): void
    {
        $global = (string) GP247_STORE_ID_GLOBAL;
        \GP247\Core\Models\AdminConfig::where('group', 'Plugins')->where('key', $key)->delete();
        \GP247\Core\Models\AdminConfig::insert(['group' => 'Plugins', 'key' => $key, 'code' => 'Payment', 'sort' => 0, 'store_id' => $global, 'value' => '1',
            'detail' => 'Plugins/' . $key . '::lang.title']);
        foreach ($settings as $name => $value) {
            \GP247\Core\Models\AdminConfig::where('group', $key)->where('key', $name)->delete();
            \GP247\Core\Models\AdminConfig::insert(['group' => $key, 'key' => $name, 'code' => $key . '_config', 'sort' => 1, 'store_id' => $global, 'value' => $value, 'detail' => '']);
        }
        $this->forgetConfigMemo();
    }

    /**
     * Make an installed payment plugin collect through a hosted page (the fake gateway).
     *
     * @param string $key Plugin key of the payment method.
     * @return void
     */
    protected function useHostedGateway(string $key): void
    {
        FakeHostedGateway::reset();
        $gateways = (array) config('gp247-config.payment.gateways', []);
        $gateways[$key] = ['label' => 'ZZ hosted', 'capabilities' => ['collect'], 'driver' => FakeHostedGateway::class];
        config(['gp247-config.payment.gateways' => $gateways]);
    }
}
