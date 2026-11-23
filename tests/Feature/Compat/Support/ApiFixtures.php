<?php

namespace GP247\Shop\Tests\Feature\Compat\Support;

use GP247\Core\Models\AdminPermission;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminStore;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * Shared fixtures for REST API Feature-Tests: admins with RBAC grants, customers,
 * products, orders and Sanctum tokens. Every row is written inside the caller's
 * DatabaseTransactions transaction (no DDL here), so nothing survives the test.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-005
 */
trait ApiFixtures
{
    /** @var string[] The API switch keys of the API management screen (US-CMP-api-admin-switch). */
    protected array $apiSwitchKeys = ['api_enabled', 'api_v1_core', 'api_v1_front', 'api_v2_core', 'api_v2_front'];

    /**
     * Run by Laravel's setUpTraits(): turn every API switch on and the API Connection gate
     * off inside the test transaction, so API tests never depend on the install defaults
     * (a fresh install ships v1 off) or on what the site owner set on the screen.
     *
     * @return void
     */
    protected function setUpApiFixtures(): void
    {
        // WHY the guard: never write outside a transaction that rolls back.
        if (\DB::connection(GP247_DB_CONNECTION)->transactionLevel() === 0) {
            $this->fail('ApiFixtures needs DatabaseTransactions on ' . GP247_DB_CONNECTION . ' (connectionsToTransact) so its writes roll back.');
        }
        foreach ($this->apiSwitchKeys as $key) {
            $this->setApiSwitch($key, true);
        }
        // WHY: the API Connection gate is a site choice too; tests that need it turn it on themselves.
        \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', 'api_connection_required')->where('store_id', GP247_STORE_ID_GLOBAL)
            ->update(['value' => '0']);
        $this->forgetConfigMemo();
    }

    /**
     * Set one API switch in the global config and drop the per-request config memo.
     *
     * @param string $key  One of $apiSwitchKeys.
     * @param bool   $on   Whether the switch is on.
     * @return void
     */
    protected function setApiSwitch(string $key, bool $on): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $where = ['key' => $key, 'store_id' => GP247_STORE_ID_GLOBAL];
        // WHY value only on an existing row: the seeded sort/detail (labels) must survive.
        if ($table->clone()->where($where)->exists()) {
            $table->clone()->where($where)->update(['value' => $on ? '1' : '0']);
        } else {
            $detail = $key === 'api_enabled' ? 'switch_all' : (str_ends_with($key, '_core') ? 'switch_core' : 'switch_front');
            $table->clone()->insert($where + [
                'group' => 'global', 'code' => 'api_config', 'value' => $on ? '1' : '0',
                'sort' => (int) array_search($key, $this->apiSwitchKeys, true) + 2, 'detail' => 'admin.api_connection.' . $detail,
            ]);
        }
        $this->forgetConfigMemo();
    }

    /**
     * Drop AdminConfig's per-request memo so the next call reads the database (a real
     * request starts with an empty memo).
     *
     * @return void
     */
    protected function forgetConfigMemo(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(\GP247\Core\Models\AdminConfig::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * Admin API prefix (v1).
     *
     * @return string Leading-slash prefix, e.g. "/api/core".
     */
    protected function coreApi(): string
    {
        return '/' . trim(GP247_API_CORE_PREFIX, '/');
    }

    /**
     * Storefront API prefix (v1).
     *
     * @return string Leading-slash prefix, e.g. "/api/front".
     */
    protected function frontApi(): string
    {
        return '/' . trim(GP247_API_FRONT_PREFIX, '/');
    }

    /**
     * Admin API prefix (v2).
     *
     * @return string Leading-slash prefix, e.g. "/api/v2/core".
     */
    protected function v2Core(): string
    {
        return '/' . trim(GP247_API_V2_CORE_PREFIX, '/');
    }

    /**
     * Storefront API prefix (v2).
     *
     * @return string Leading-slash prefix, e.g. "/api/v2/front".
     */
    protected function v2Front(): string
    {
        return '/' . trim(GP247_API_V2_FRONT_PREFIX, '/');
    }

    /**
     * Send a JSON request with a bearer token (or none), resetting headers and cached
     * guard users first so calls in one test stay independent.
     *
     * @param string      $method HTTP method.
     * @param string      $uri    Request URI.
     * @param string|null $token  Bearer token, null for an anonymous call.
     * @param array       $data   Request body / query.
     * @return TestResponse
     */
    protected function apiCall(string $method, string $uri, ?string $token = null, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($token !== null) {
            $this->withHeader('Authorization', 'Bearer ' . $token);
        }
        return $this->json($method, $uri, $data);
    }

    /**
     * Set store-level settings (customer_*, captcha_*, …) inside the test transaction.
     * Rows that exist are updated in place (labels kept); missing ones are inserted.
     *
     * @param array<string, string> $values  key => value.
     * @param string|null           $storeId Store of the rows (default GLOBAL).
     * @return void
     */
    protected function setStoreConfig(array $values, ?string $storeId = null): void
    {
        $storeId = $storeId ?? (string) GP247_STORE_ID_GLOBAL;
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        foreach ($values as $key => $value) {
            $where = ['key' => $key, 'store_id' => $storeId];
            if ($table->clone()->where($where)->exists()) {
                $table->clone()->where($where)->update(['value' => (string) $value]);
            } else {
                $table->clone()->insert($where + ['group' => 'zz_test', 'code' => 'zz_test', 'value' => (string) $value, 'sort' => 0, 'detail' => '']);
            }
        }
        $this->forgetConfigMemo();
    }

    /**
     * Customer settings that keep account tests independent of the site: e-mail on
     * (optional), every optional address field off.
     *
     * @return void
     */
    protected function useMinimalCustomerForm(): void
    {
        $off = ['customer_lastname', 'customer_address2', 'customer_address3', 'customer_phone', 'customer_country',
            'customer_postcode', 'customer_company', 'customer_sex', 'customer_birthday', 'customer_group',
            'customer_name_kana', 'customer_city', 'customer_district', 'customer_ward', 'customer_verify'];
        $this->setStoreConfig(array_fill_keys($off, '0') + ['customer_email' => '1', 'customer_email_required' => '1']);
    }

    /**
     * Create an active admin with the given admin route names granted as GET permissions
     * (through a dedicated role) and/or existing role slugs attached.
     *
     * @param string[] $adminRouteNames Admin route names, e.g. ['admin_order.index'].
     * @param string[] $roleSlugs       Existing role slugs, e.g. ['administrator'].
     * @param int      $status          Admin status (1 = active).
     * @return AdminUser
     */
    protected function makeAdmin(array $adminRouteNames = [], array $roleSlugs = [], int $status = 1): AdminUser
    {
        $suffix = uniqid();
        $admin = AdminUser::create([
            'username' => 'zzapi' . $suffix,
            'password' => bcrypt('Secret#123'),
            'name'     => 'ZZ Api ' . $suffix,
            'email'    => 'zzapi' . $suffix . '@example.com',
            'status'   => $status,
        ]);

        if ($adminRouteNames) {
            $uris = [];
            foreach ($adminRouteNames as $name) {
                $uris[] = 'GET::' . Route::getRoutes()->getByName($name)->uri();
            }
            $permission = AdminPermission::create([
                'name' => 'ZZ api perm ' . $suffix, 'slug' => 'zz_api_perm_' . $suffix, 'http_uri' => implode(',', $uris),
            ]);
            $role = AdminRole::create(['name' => 'ZZ api role ' . $suffix, 'slug' => 'zz_api_role_' . $suffix]);
            $role->permissions()->attach($permission->id);
            $admin->roles()->attach($role->id);
        }
        foreach ($roleSlugs as $slug) {
            $existing = AdminRole::where('slug', $slug)->first();
            if ($existing) {
                $admin->roles()->attach($existing->id);
            }
        }

        return $admin->fresh();
    }

    /**
     * Issue a Sanctum token with the given abilities.
     *
     * @param \Illuminate\Database\Eloquent\Model $owner     Admin or customer.
     * @param string[]                            $abilities Token abilities.
     * @return string Plain-text bearer token.
     */
    protected function tokenFor($owner, array $abilities): string
    {
        return $owner->createToken('zz-api-test', $abilities)->plainTextToken;
    }

    /**
     * Send a GET with a bearer token, forgetting cached guard users first so several
     * calls in one test authenticate independently.
     *
     * @param string $uri   Request URI.
     * @param string $token Bearer token.
     * @return TestResponse
     */
    protected function getWithToken(string $uri, string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();
        return $this->withHeader('Authorization', 'Bearer ' . $token)->getJson($uri);
    }

    /**
     * Create a customer.
     *
     * @param int    $status   Customer status (1 = active, 0 = locked).
     * @param string $password Plain password.
     * @return ShopCustomer
     */
    protected function makeCustomer(int $status = 1, string $password = 'Secret#123'): ShopCustomer
    {
        return ShopCustomer::create([
            'first_name' => 'ZZ', 'last_name' => 'Api',
            'email'      => 'zzapicus' . uniqid() . '@example.com',
            'password'   => bcrypt($password),
            'status'     => $status,
            'store_id'   => (string) GP247_STORE_ID_ROOT,
        ]);
    }

    /**
     * Create a product with a description in the current locale.
     *
     * @param array $attributes Column overrides.
     * @return ShopProduct
     */
    protected function makeProduct(array $attributes = []): ShopProduct
    {
        $product = ShopProduct::create(array_merge([
            'sku'      => 'ZZAPI-' . strtoupper(uniqid()),
            'alias'    => 'zz-api-' . uniqid(),
            'price'    => 100,
            'cost'     => 42,
            'stock'    => 50,
            'status'   => 1,
            'approve'  => 1,
            'kind'     => 0,
            'store_id' => (string) GP247_STORE_ID_ROOT,
        ], $attributes));
        ShopProductDescription::create([
            'product_id' => $product->id, 'lang' => gp247_get_locale(), 'name' => 'ZZ api product', 'description' => '', 'content' => '',
        ]);

        return $product;
    }

    /**
     * Create an order.
     *
     * @param array $attributes Column overrides.
     * @return ShopOrder
     */
    protected function makeOrder(array $attributes = []): ShopOrder
    {
        return ShopOrder::create(array_merge([
            'customer_id' => 0, 'currency' => 'USD', 'exchange_rate' => 1,
            'email' => 'zzapiord' . uniqid() . '@example.com', 'first_name' => 'ZZ Api',
            'status' => 1, 'payment_status' => 1, 'shipping_status' => 1,
            'subtotal' => 0, 'tax' => 0, 'shipping' => 0, 'other_fee' => 0,
            'store_id' => (string) GP247_STORE_ID_ROOT,
        ], $attributes));
    }

    /**
     * Id of a store other than ROOT (existing one, or a new one).
     *
     * @return string
     */
    protected function otherStoreId(): string
    {
        $id = AdminStore::where('id', '<>', (string) GP247_STORE_ID_ROOT)->value('id');
        if ($id) {
            return (string) $id;
        }
        return (string) AdminStore::create([
            'code' => 'zzapi' . uniqid(), 'domain' => 'zzapi' . uniqid() . '.local', 'status' => 1,
            // WHY: both columns are NOT NULL without default — a site with only the ROOT store failed here.
            'language' => gp247_get_locale(), 'currency' => 'USD',
        ])->id;
    }

    /**
     * Pin the admin store context of API requests and the plugin fence for this test.
     *
     * @param mixed $resolver Callable returning the admin store id, or null for ROOT.
     * @param mixed $fence    Callable(AdminUser, Request): bool, or null for no fence.
     * @return void
     */
    protected function pinAdminStoreContext($resolver = null, $fence = null): void
    {
        config([
            'gp247-config.admin.store_resolver' => $resolver,
            'gp247-config.api.admin_fence'      => $fence,
        ]);
    }
}
