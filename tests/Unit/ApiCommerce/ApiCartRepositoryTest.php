<?php

namespace GP247\Shop\Tests\Unit\ApiCommerce;

use GP247\Shop\Api\Support\Commerce\ApiCartRepository;
use GP247\Shop\Api\Support\Commerce\CartOwner;
use GP247\Shop\Api\Support\Commerce\GuestCartToken;
use GP247\Shop\Models\ShopCart;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\TestCase;

/**
 * The API cart repository reads and writes shop_shoppingcart rows by cart owner: a write
 * only lands when the row still holds what was read (compare-and-swap), a guest row older
 * than 30 days reads as absent and is removed, and sweeping removes a bounded number of
 * expired guest rows only.
 */
class ApiCartRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private function repo(): ApiCartRepository
    {
        return new ApiCartRepository();
    }

    private function guest(): CartOwner
    {
        return CartOwner::guest(GuestCartToken::issue());
    }

    public function test_a_new_row_is_written_and_read_back(): void
    {
        $owner = $this->guest();

        $this->assertNull($this->repo()->read($owner, 'default'));
        $this->assertTrue($this->repo()->write($owner, 'default', '{"a":1}', null));
        $this->assertSame('{"a":1}', $this->repo()->read($owner, 'default'));
    }

    public function test_a_write_based_on_a_stale_read_is_refused(): void
    {
        $owner = $this->guest();
        $this->repo()->write($owner, 'default', '{"v":1}', null);
        $this->repo()->write($owner, 'default', '{"v":2}', '{"v":1}');

        $this->assertFalse($this->repo()->write($owner, 'default', '{"v":3}', '{"v":1}'), 'the row changed since it was read');
        $this->assertFalse($this->repo()->write($owner, 'default', '{"v":3}', null), 'a row now exists that the caller did not read');
        $this->assertSame('{"v":2}', $this->repo()->read($owner, 'default'));
    }

    public function test_a_guest_row_untouched_for_more_than_30_days_reads_as_absent_and_is_removed(): void
    {
        $owner = $this->guest();
        $this->repo()->write($owner, 'default', '{"a":1}', null);
        ShopCart::where('identifier', $owner->storageKey())->update(['updated_at' => now()->subDays(31)]);

        $this->assertNull($this->repo()->read($owner, 'default'));
        $this->assertSame(0, ShopCart::where('identifier', $owner->storageKey())->count());
    }

    public function test_a_customer_row_never_expires(): void
    {
        $owner = CartOwner::customer((object) ['id' => 'ZZ-CUS-' . uniqid()]);
        $this->repo()->write($owner, 'default', '{"a":1}', null);
        ShopCart::where('identifier', $owner->storageKey())->update(['updated_at' => now()->subDays(400)]);

        $this->assertSame('{"a":1}', $this->repo()->read($owner, 'default'));
    }

    public function test_sweeping_removes_a_bounded_number_of_expired_guest_rows_only(): void
    {
        $expired = [];
        foreach (range(1, 3) as $i) {
            $owner = $this->guest();
            $this->repo()->write($owner, 'default', '[]', null);
            $expired[] = $owner->storageKey();
        }
        $fresh = $this->guest();
        $this->repo()->write($fresh, 'default', '[]', null);
        $customer = CartOwner::customer((object) ['id' => 'ZZ-CUS-' . uniqid()]);
        $this->repo()->write($customer, 'default', '[]', null);
        ShopCart::whereIn('identifier', array_merge($expired, [$customer->storageKey()]))->update(['updated_at' => now()->subDays(40)]);

        $removed = $this->repo()->sweepExpiredGuests(2);

        $this->assertSame(2, $removed);
        $this->assertSame(1, ShopCart::whereIn('identifier', $expired)->count());
        $this->assertSame(1, ShopCart::where('identifier', $fresh->storageKey())->count());
        $this->assertSame(1, ShopCart::where('identifier', $customer->storageKey())->count(), 'customer carts are never swept');
    }

    public function test_deleting_removes_every_instance_of_the_owner(): void
    {
        $owner = $this->guest();
        $this->repo()->write($owner, 'default', '[]', null);
        $this->repo()->write($owner, 'api_checkout', '[]', null);

        $this->repo()->forgetOwner($owner);

        $this->assertSame(0, ShopCart::where('identifier', $owner->storageKey())->count());
    }
}
