<?php

namespace GP247\Shop\Tests\Unit\ApiCommerce;

use GP247\Shop\Api\Support\Commerce\CartOwner;
use GP247\Shop\Api\Support\Commerce\GuestCartToken;
use GP247\Shop\Tests\TestCase;

/**
 * Cart owner and guest cart token value objects: a customer cart keeps the website's
 * storage key (the customer id), a guest cart is keyed by the hash of its token, and the
 * token itself is a 256-bit base64url string never derived from anything else.
 */
class CartOwnerAndTokenTest extends TestCase
{
    public function test_a_guest_cart_token_is_256_random_bits_in_base64url(): void
    {
        $a = GuestCartToken::issue();
        $b = GuestCartToken::issue();

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertTrue(GuestCartToken::isWellFormed($a));
    }

    public function test_only_well_formed_tokens_are_accepted(): void
    {
        foreach (['', 'short', str_repeat('A', 42), str_repeat('A', 44), str_repeat('A', 42) . '=', str_repeat('A', 42) . '/'] as $junk) {
            $this->assertFalse(GuestCartToken::isWellFormed($junk), json_encode($junk));
        }
    }

    public function test_the_token_hash_is_a_stable_sha256(): void
    {
        $token = GuestCartToken::issue();

        $this->assertSame(hash('sha256', $token), GuestCartToken::hash($token));
        $this->assertSame(64, strlen(GuestCartToken::hash($token)));
    }

    public function test_a_customer_cart_is_stored_under_the_customer_id_like_the_website(): void
    {
        $owner = CartOwner::customer((object) ['id' => 'ZZ-CUS-1']);

        $this->assertFalse($owner->isGuest());
        $this->assertSame('ZZ-CUS-1', $owner->storageKey());
        $this->assertSame('customer:ZZ-CUS-1', $owner->idempotencyScope());
    }

    public function test_a_guest_cart_is_stored_under_the_hash_of_its_token(): void
    {
        $token = GuestCartToken::issue();
        $owner = CartOwner::guest($token);

        $this->assertTrue($owner->isGuest());
        $this->assertSame('guest:' . hash('sha256', $token), $owner->storageKey());
        $this->assertLessThanOrEqual(100, strlen($owner->storageKey()), 'fits shop_shoppingcart.identifier');
        $this->assertStringNotContainsString($token, $owner->storageKey());
    }
}
