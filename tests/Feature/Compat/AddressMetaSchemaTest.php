<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopCustomerAddress;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;

/**
 * The three address blocks can keep the codes of an address next to its names, and
 * hold nothing there on a shop without an address plugin.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-address-meta-schema
 */
#[Group('compat-foundation')]
class AddressMetaSchemaTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;

    private const META = [
        'provider' => 'AddressVN', 'country' => 'VN', 'data_version' => '2025-07',
        'components' => ['province' => ['code' => '01', 'name' => 'Hà Nội']],
    ];

    public function test_the_address_meta_column_exists_on_the_three_address_blocks(): void
    {
        foreach (['shop_order', 'shop_customer', 'shop_customer_address'] as $table) {
            $this->assertTrue(Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . $table, 'address_meta'), $table);
        }
    }

    public function test_an_order_keeps_the_codes_of_its_address_and_none_by_default(): void
    {
        $plain = $this->makeOrder(['city' => 'Hà Nội']);
        $coded = $this->makeOrder(['city' => 'Hà Nội', 'address_meta' => self::META]);

        $this->assertNull(ShopOrder::find($plain->id)->address_meta);
        $this->assertSame(self::META, ShopOrder::find($coded->id)->address_meta);
    }

    public function test_a_customer_and_an_address_book_entry_keep_the_codes_as_an_array(): void
    {
        $customer = ShopCustomer::create([
            'first_name' => 'An', 'email' => 'an-address-meta@example.com', 'password' => 'x', 'address_meta' => self::META,
        ]);
        $address = ShopCustomerAddress::create([
            'customer_id' => $customer->id, 'first_name' => 'An', 'address1' => '1 Tràng Tiền', 'address_meta' => self::META,
        ]);

        $this->assertSame('01', ShopCustomer::find($customer->id)->address_meta['components']['province']['code']);
        $this->assertSame(self::META, ShopCustomerAddress::find($address->id)->address_meta);
    }
}
