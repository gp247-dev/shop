<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy\Support;

use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Payment\OrderPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds one customer with data in every place gp247/shop and gp247/front keep it (account,
 * addresses, cart, sessions, API sign-ins, custom field, contact message, newsletter, an order
 * with history, payments and a shipment, and a guest order with the same email), plus another
 * customer whose data must stay untouched.
 *
 * @aidlc-unit customer-privacy
 */
trait SeedsCustomerData
{
    protected const ADMIN_PASSWORD = 'correct horse battery';
    protected const NAME = 'Nguyen Van An';
    protected const PHONE = '0912345678';
    protected const STREET = '12 Nguyen Trai Street';

    /**
     * @return AdminUser A signed-in administrator whose password is ADMIN_PASSWORD.
     */
    protected function signInAdministrator(): AdminUser
    {
        $user = new AdminUser();
        $user->username = 'pd-' . bin2hex(random_bytes(4));
        $user->password = Hash::make(self::ADMIN_PASSWORD);
        $user->name = 'Privacy tester';
        $user->email = $user->username . '@example.com';
        $user->status = 1;
        $user->save();
        $user->roles()->attach(AdminRole::where('slug', 'administrator')->pluck('id')->all());
        foreach (['allPermissions', 'allViewPermissions', 'canChangeConfig', 'listStoreId', 'listStore'] as $property) {
            (new \ReflectionProperty(AdminUser::class, $property))->setValue(null, null);
        }
        $this->actingAs($user->fresh(), 'admin');

        return $user;
    }

    /**
     * @param string $email
     * @param int    $status Status of the account order.
     * @return array<string, string> Ids of what was created.
     */
    protected function seedCustomer(string $email, int $status = ShopOrderStatus::DONE): array
    {
        $customer = ShopCustomer::createCustomer([
            'first_name' => 'Nguyen', 'last_name' => 'Van An', 'email' => $email, 'password' => bcrypt('secret123'),
            'phone' => self::PHONE, 'address1' => self::STREET, 'country' => 'VN', 'status' => 1,
            'store_id' => GP247_STORE_ID_ROOT,
        ]);
        $id = (string) $customer->id;
        $order = $this->insertOrder($id, $email, $status);
        $guestOrder = $this->insertOrder('0', strtoupper($email), ShopOrderStatus::CANCELED);
        $now = now();

        $this->table('shop_order_history')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $order, 'admin_id' => '0', 'customer_id' => $id, 'order_status_id' => $status, 'add_date' => $now,
            'content' => 'Address changed to ' . self::STREET . ', phone ' . self::PHONE . ' by ' . self::NAME . ' (total 1700000)',
        ]);
        $this->table('shop_order_detail')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $order, 'product_id' => (string) Str::uuid(), 'name' => 'Tea cup', 'price' => 850000, 'qty' => 2,
            'store_id' => GP247_STORE_ID_ROOT, 'total_price' => 1700000, 'discount' => 0, 'tax' => 0, 'tax_rate' => 0, 'sku' => 'TEA-1', 'currency' => 'VND',
        ]);
        $this->table('shop_order_total')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $order, 'title' => 'Total', 'code' => 'total', 'value' => 1700000, 'sort' => 100,
        ]);
        $this->table('shop_order_transaction')->insert([
            'id' => (string) Str::uuid(), 'order_id' => $order, 'type' => 'payment', 'amount' => 1700000, 'amount_base' => 1700000,
            'currency' => 'VND', 'note' => 'Paid by ' . self::NAME,
        ]);
        $request = $this->table('payment_request')->insertGetId([
            'direction' => 'in', 'amount' => 1700000, 'currency' => 'VND', 'settled_amount' => 1700000, 'status' => 'settled', 'purpose' => 'order',
            'subject_type' => OrderPurpose::SUBJECT_TYPE, 'subject_id' => $order, 'party_name' => self::NAME, 'party_email' => $email,
            'party_phone' => self::PHONE, 'description' => 'Order for ' . self::NAME, 'store_id' => GP247_STORE_ID_ROOT,
            'metadata' => json_encode(['access_token' => 'gw-tok-1', 'amount' => 1700000, 'payer' => ['name' => self::NAME, 'phone' => self::PHONE]]),
        ]);
        $this->table('payment_movement')->insert([
            'request_id' => $request, 'type' => 'in', 'amount' => 1700000, 'currency' => 'VND', 'gateway' => 'manual', 'method' => 'cash',
            'reference' => 'RCPT-1', 'note' => 'Cash from ' . self::NAME,
        ]);
        $shipment = (string) Str::uuid();
        $this->table('shop_shipment')->insert([
            'id' => $shipment, 'order_id' => $order, 'store_id' => GP247_STORE_ID_ROOT, 'plugin_key' => 'ShippingStandard', 'status' => 'delivered',
            'fee' => 30000, 'cod_amount' => 0, 'weight_grams' => 500,
            'meta' => json_encode(['carrier_order' => 'GHN-1', 'receiver' => self::NAME . ' ' . self::PHONE]),
            'request' => json_encode(['origin' => ['name' => 'Shop warehouse'], 'destination' => ['name' => self::NAME, 'phone' => self::PHONE, 'line1' => self::STREET, 'country' => 'VN'], 'note' => 'Call ' . self::PHONE]),
        ]);
        $this->table('shop_shipment_event')->insert([
            'shipment_id' => $shipment, 'status' => 'delivered', 'message' => 'Delivered to ' . self::NAME, 'source' => 'webhook', 'dedupe_key' => Str::random(20),
        ]);
        $this->table('shop_shoppingcart')->insert(['identifier' => $id, 'instance' => 'default', 'content' => '[]', 'store_id' => GP247_STORE_ID_ROOT]);
        $this->table('shop_sessions')->insert(['id' => Str::random(40), 'customer_id' => $id, 'payload' => 'x', 'last_activity' => time()]);
        $this->table('shop_password_resets')->insert(['email' => $email, 'token' => Str::random(40), 'created_at' => $now]);
        $session = (string) Str::uuid();
        $this->table('api_session')->insert([
            'id' => $session, 'tokenable_type' => $customer->getMorphClass(), 'tokenable_id' => $id, 'audience' => 'customer', 'refreshable' => 1, 'ip' => '203.0.113.7',
        ]);
        $this->table('api_refresh_token')->insert(['session_id' => $session, 'token_hash' => hash('sha256', Str::random(20))]);
        DB::connection(GP247_DB_CONNECTION)->table('personal_access_tokens')->insert([
            'tokenable_type' => $customer->getMorphClass(), 'tokenable_id' => $id, 'name' => 'app', 'token' => hash('sha256', Str::random(20)),
        ]);
        $field = (string) Str::uuid();
        $this->table('admin_custom_field')->insert(['id' => $field, 'type' => $customer->getTable(), 'code' => 'pd_' . Str::random(6), 'name' => 'Tax code', 'required' => 0, 'status' => 1]);
        $this->table('admin_custom_field_detail')->insert(['id' => (string) Str::uuid(), 'custom_field_id' => $field, 'rel_id' => $id, 'text' => '0101234567']);
        $this->table('front_contact')->insert([
            'id' => (string) Str::uuid(), 'store_id' => GP247_STORE_ID_ROOT, 'name' => self::NAME, 'email' => $email, 'title' => 'Hello', 'content' => 'Question', 'status' => 0,
        ]);
        $this->table('front_subscribe')->insert(['id' => (string) Str::uuid(), 'email' => $email, 'status' => 1, 'store_id' => GP247_STORE_ID_ROOT]);

        return ['customer' => $id, 'order' => $order, 'guest_order' => $guestOrder, 'request' => (string) $request, 'shipment' => $shipment, 'session' => $session, 'field' => $field];
    }

    /**
     * @param string $customerId '0' for a guest.
     * @param string $email
     * @param int    $status
     * @return string Order id.
     */
    protected function insertOrder(string $customerId, string $email, int $status): string
    {
        $id = (string) Str::uuid();
        $this->table('shop_order')->insert([
            'id' => $id, 'customer_id' => $customerId, 'payment_status' => 3, 'shipping_status' => 3, 'status' => $status,
            'currency' => 'VND', 'subtotal' => 1700000, 'shipping' => 30000, 'discount' => 0, 'tax' => 0, 'total' => 1730000, 'received' => 1730000, 'balance' => 0,
            'first_name' => 'Nguyen', 'last_name' => 'Van An', 'email' => $email, 'phone' => self::PHONE, 'address1' => self::STREET,
            'city' => 'Ha Noi', 'country' => 'VN', 'postcode' => '100000', 'ip' => '203.0.113.7', 'user_agent' => 'Mozilla/5.0',
            'comment' => 'Ring ' . self::PHONE, 'store_id' => GP247_STORE_ID_ROOT, 'created_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param string $logical
     * @return \Illuminate\Database\Query\Builder
     */
    protected function table(string $logical)
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . $logical);
    }
}
