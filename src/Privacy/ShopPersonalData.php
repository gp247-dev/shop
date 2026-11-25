<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\PersonalDataSource;
use GP247\Core\PersonalData\Domain\SubjectLink;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Payment\OrderPurpose;
use Illuminate\Database\Query\Builder;

/**
 * Where gp247/shop keeps personal data of customers, and what erasing a customer does to each
 * place: the account, its addresses, cart, sessions and API sign-ins are deleted; orders, their
 * history, payments and shipments stay for the accounts but lose name, contact and address
 * (the money, dates and order codes are kept). Guest orders placed with the email of the
 * account are treated the same way; an email typed on an order is never used to find other
 * rows — it may be someone else's.
 *
 * Processing order: sign-ins first (the customer is signed out at once, so no new order is
 * placed with the account while the erasure runs), then rows that point to another declared row
 * (history before the order it belongs to), the account row last. JSON columns keep their keys
 * and numbers: only string values are scrubbed.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-declare-shop-data
 * @aidlc-adr personal-data-registry_source-declaration, personal-data-registry_anonymisation-rules
 */
final class ShopPersonalData
{
    public const OWNER = 'gp247/shop';

    /** @var array<string, string> Shop tables that look personal by column names but hold no customer data. */
    public const NOT_CUSTOMER_DATA = [
        'shop_supplier' => 'suppliers of the shop, not customers',
        'shop_product_description' => 'product texts written by the shop',
    ];

    /** Address and contact columns of an order, emptied on erasure (names get the marker). */
    private const ORDER_CONTACT = [
        'email', 'phone', 'company', 'country', 'city', 'district', 'ward',
        'address1', 'address2', 'address3', 'postcode', 'address_meta',
        'comment', 'ip', 'user_agent', 'device_type', 'last_name', 'first_name_kana', 'last_name_kana',
    ];

    /** Values collected before erasing, to remove them from free text (history, notes). */
    private const IDENTITY = ['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'phone', 'company', 'address1', 'address2', 'address3'];

    /** Shipping address keys inside shop_shipment.request, emptied (destination is the customer). */
    private const SHIPMENT_PATHS = [
        'destination.name', 'destination.phone', 'destination.email', 'destination.company',
        'destination.line1', 'destination.line2', 'destination.ward', 'destination.postcode', 'note',
    ];

    /**
     * @return void
     */
    public static function register(): void
    {
        foreach (self::sources() as $source) {
            PersonalDataRegistry::declare($source);
        }
        PersonalDataRegistry::blocker(new OpenOrderBlocker());
        // WHY method_exists: the core of a site updated in two steps may not have these yet.
        if (method_exists(PersonalDataRegistry::class, 'notCustomerData')) {
            foreach (self::NOT_CUSTOMER_DATA as $table => $reason) {
                PersonalDataRegistry::notCustomerData($table, $reason);
            }
        }
        if (method_exists(PersonalDataRegistry::class, 'customerResolver')) {
            PersonalDataRegistry::customerResolver(static fn (string $idOrEmail): ?array => self::findCustomer($idOrEmail));
        }
        if (method_exists(PersonalDataRegistry::class, 'customersResolver')) {
            PersonalDataRegistry::customersResolver(static fn (array $ids): array => self::findCustomers($ids));
        }
    }

    /**
     * @param string $idOrEmail
     * @return array{id: string, email: string|null, name: string|null, store_id: string|null, verified?: bool}|null
     */
    public static function findCustomer(string $idOrEmail): ?array
    {
        $value = trim($idOrEmail);
        $customer = str_contains($value, '@')
            ? ShopCustomer::query()->where('email', mb_strtolower($value))->first()
            : ShopCustomer::query()->where('id', $value)->first();
        if ($customer === null) {
            return null;
        }

        return [
            'id' => (string) $customer->id,
            'email' => $customer->email === null ? null : (string) $customer->email,
            'name' => trim((string) $customer->first_name . ' ' . (string) $customer->last_name) ?: null,
            'store_id' => $customer->store_id === null ? null : (string) $customer->store_id,
            // WHY: rows found by this email join a customer's own request only when it is proven theirs.
            'verified' => $customer->email_verified_at !== null,
        ];
    }

    /**
     * @param list<string> $ids
     * @return array<string, array{id: string, email: string|null, name: string|null, store_id: string|null}> Id => customer (one query).
     */
    public static function findCustomers(array $ids): array
    {
        $out = [];
        foreach (ShopCustomer::query()->whereIn('id', $ids)->get(['id', 'email', 'first_name', 'last_name', 'store_id']) as $customer) {
            $out[(string) $customer->id] = [
                'id' => (string) $customer->id,
                'email' => $customer->email === null ? null : (string) $customer->email,
                'name' => trim((string) $customer->first_name . ' ' . (string) $customer->last_name) ?: null,
                'store_id' => $customer->store_id === null ? null : (string) $customer->store_id,
            ];
        }

        return $out;
    }

    /**
     * @return list<PersonalDataSource>
     */
    public static function sources(): array
    {
        $customerType = (new ShopCustomer())->getMorphClass();
        $orderParent = SubjectLink::parent('shop_order', 'order_id');

        return [
            // Sign-ins first: refresh tokens before the sessions they belong to.
            self::label(PersonalDataSource::table('shop_sessions')->link(SubjectLink::customer('customer_id'))->deleteRows()->order(3), 'sessions'),
            self::label(PersonalDataSource::table('api_refresh_token')->link(SubjectLink::parent('api_session', 'session_id'))->deleteRows()->order(3), 'api_refresh_tokens'),
            self::label(PersonalDataSource::table('api_session', 'id')->link(SubjectLink::morph('tokenable_type', 'tokenable_id', $customerType))->deleteRows()
                ->export(['name', 'ip', 'user_agent', 'last_used_at', 'created_at'])->order(4), 'api_sessions'),
            self::label(PersonalDataSource::table('personal_access_tokens')->unprefixed()->link(SubjectLink::morph('tokenable_type', 'tokenable_id', $customerType))->deleteRows()
                ->export(['name', 'last_used_at', 'created_at'])->order(4), 'api_tokens'),
            // Children of orders, payments and shipments.
            self::label(PersonalDataSource::table('shop_order_history')->link($orderParent)
                ->anonymise(['content' => 'scrubText'])->export(['order_id', 'content', 'add_date'])->order(10), 'order_history'),
            self::label(PersonalDataSource::table('shop_order_transaction')->link($orderParent)
                ->anonymise(['note' => 'scrubText'])->export(['order_id', 'type', 'amount', 'currency', 'method', 'paid_at'])->order(10), 'order_transactions'),
            self::label(PersonalDataSource::table('payment_movement')->link(SubjectLink::parent('payment_request', 'request_id'))
                ->anonymise(['note' => 'scrubText', 'metadata' => self::jsonValues()])->export(['request_id', 'type', 'amount', 'currency', 'paid_at'])->order(10), 'payment_movements'),
            self::label(PersonalDataSource::table('shop_shipment_event')->link(SubjectLink::parent('shop_shipment', 'shipment_id'))
                ->anonymise(['message' => 'scrubText'])->export(['shipment_id', 'status', 'occurred_at'])->order(10), 'shipment_events'),
            self::label(PersonalDataSource::table('shop_order_detail')->link($orderParent)->retain()
                ->export(['order_id', 'name', 'sku', 'qty', 'price', 'total_price', 'currency'])->order(15), 'order_lines'),
            self::label(PersonalDataSource::table('shop_order_total')->link($orderParent)->retain()
                ->export(['order_id', 'code', 'title', 'value'])->order(15), 'order_totals'),
            self::label(PersonalDataSource::table('shop_shipment')->link($orderParent)
                ->anonymise(['request' => ColumnRule::of(ColumnRule::SCRUB_JSON, self::SHIPMENT_PATHS), 'meta' => self::jsonValues()])->export(['order_id', 'tracking_number', 'status'])->order(20), 'shipments'),
            self::label(PersonalDataSource::table('payment_request')
                ->link(SubjectLink::parent('shop_order', 'subject_id', 'subject_type', OrderPurpose::SUBJECT_TYPE))
                ->link(SubjectLink::email('party_email'))
                ->anonymise(['party_name' => 'mark', 'party_email' => 'null', 'party_phone' => 'null', 'description' => 'scrubText', 'metadata' => self::jsonValues()])
                ->identity(['party_name', 'party_phone'])
                ->export(['direction', 'amount', 'currency', 'status', 'purpose', 'created_at'])->order(20), 'payment_requests'),
            // Orders: kept for the accounts, personal columns emptied.
            self::label(PersonalDataSource::table('shop_order')
                ->link(SubjectLink::customer('customer_id'))
                ->link(SubjectLink::email('email', onlyWhenEmpty: 'customer_id'))
                ->anonymise(['first_name' => 'mark'] + array_fill_keys(self::ORDER_CONTACT, 'null'))
                ->identity(self::IDENTITY)
                ->export(['id', 'created_at', 'status', 'payment_status', 'shipping_status', 'currency', 'subtotal', 'shipping', 'discount', 'tax', 'total',
                    'first_name', 'last_name', 'email', 'phone', 'country', 'city', 'district', 'ward', 'address1', 'address2', 'address3', 'postcode'])
                ->order(40), 'orders'),
            // Account data: deleted.
            self::label(PersonalDataSource::table('shop_customer_address')->link(SubjectLink::customer('customer_id'))->deleteRows()
                ->identity(self::identityOfAddress())
                ->export(['first_name', 'last_name', 'phone', 'country', 'city', 'district', 'ward', 'address1', 'address2', 'address3', 'postcode'])->order(60), 'addresses'),
            self::label(PersonalDataSource::table('shop_shoppingcart', 'identifier')->link(SubjectLink::customer('identifier'))->deleteRows()
                ->export(['instance', 'content', 'updated_at'])->order(60), 'carts'),
            self::label(PersonalDataSource::table('shop_password_resets', 'email')->link(SubjectLink::email('email'))->deleteRows()->order(60), 'password_resets'),
            self::label(PersonalDataSource::table('admin_custom_field_detail')->link(SubjectLink::customer('rel_id'))
                ->whereUsing(static function (Builder $query): void {
                    // WHY: rel_id holds ids of several entity types; only the customer fields count.
                    $query->whereIn('custom_field_id', fn (Builder $q) => $q->select('id')
                        ->from(GP247_DB_PREFIX . 'admin_custom_field')
                        ->where('type', (new ShopCustomer())->getTable()));
                })
                ->deleteRows()->export(['custom_field_id', 'text'])->order(70), 'custom_fields'),
            self::label(PersonalDataSource::table('shop_customer')->link(SubjectLink::customer('id'))->deleteRows()
                ->identity(self::IDENTITY, ['email'])
                ->export(['id', 'first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'email', 'phone', 'sex', 'birthday', 'company',
                    'country', 'city', 'district', 'ward', 'address1', 'address2', 'address3', 'postcode', 'created_at'])
                ->order(100), 'account'),
        ];
    }

    /**
     * @return ColumnRule Scrub known values from the string values of a JSON column (gateway or
     *                    carrier payloads), keeping its keys and numbers.
     */
    private static function jsonValues(): ColumnRule
    {
        return ColumnRule::of(ColumnRule::SCRUB_JSON);
    }

    /**
     * @return list<string> Identity columns of an address book row (no company column there).
     */
    private static function identityOfAddress(): array
    {
        return array_values(array_diff(self::IDENTITY, ['company']));
    }

    /**
     * @param PersonalDataSource $source
     * @param string             $key    Suffix of the language key admin.personal_data.source.*.
     * @return PersonalDataSource
     */
    private static function label(PersonalDataSource $source, string $key): PersonalDataSource
    {
        return $source->describe('admin.personal_data.source.' . $key, self::OWNER);
    }
}
