<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Admin\Livewire\CustomerManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin customer manager (ADR-006/007): two-column form+list on
 * the core ResourcePanel base, reusing the brownfield validation/persistence
 * helpers (password hashing, email unique, default address). Scoped to the admin
 * store. Domain unchanged (ShopCustomer). Gated by `admin_customer`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-004
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class CustomerTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * @param array<string, mixed> $overrides
     * @return ShopCustomer
     */
    private function makeCustomer(array $overrides = []): ShopCustomer
    {
        // Seed includes the config-driven required fields (last_name, country) so
        // an edit save validates without re-entering them.
        return ShopCustomer::createCustomer(array_merge([
            'first_name' => 'ZZ Seed',
            'last_name' => 'Last',
            'email' => 'zz-' . uniqid() . '@example.com',
            'password' => bcrypt('secret123'),
            'address1' => 'Addr 1',
            'country' => 'VN',
            'status' => 1,
            'store_id' => session('adminStoreId', GP247_STORE_ID_ROOT),
        ], $overrides));
    }

    public function test_lists_existing_customers(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $this->makeCustomer(['email' => 'zz-list@example.com']);

        Livewire::test(CustomerManager::class)->assertSee('zz-list@example.com');
    }

    public function test_creates_customer_with_default_address(): void
    {
        $this->asUser(slugs: ['admin_customer']);

        Livewire::test(CustomerManager::class)
            ->set('form.email', 'zz-created@example.com')
            ->set('form.first_name', 'ZZ Created')
            ->set('form.last_name', 'Created')
            ->set('form.country', 'VN')
            ->set('form.address1', 'Some street')
            ->set('form.address2', 'Apt 2')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $row = ShopCustomer::where('email', 'zz-created@example.com')->first();
        $this->assertNotNull($row);
        $this->assertSame((string) session('adminStoreId', GP247_STORE_ID_ROOT), (string) $row->store_id);
        $this->assertSame(1, $row->addresses()->count());
    }

    public function test_create_requires_first_name(): void
    {
        // first_name is always required; last_name/country are required by this
        // store's config (password is nullable on create per the legacy mapping).
        $this->asUser(slugs: ['admin_customer']);

        Livewire::test(CustomerManager::class)
            ->set('form.email', 'zz-novalidate@example.com')
            ->set('form.first_name', '')
            ->call('save')
            ->assertHasErrors(['form.first_name']);
    }

    public function test_email_must_be_unique(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $this->makeCustomer(['email' => 'zz-dup@example.com']);

        Livewire::test(CustomerManager::class)
            ->set('form.email', 'zz-dup@example.com')
            ->set('form.first_name', 'ZZ Dup')
            ->set('form.address1', 'x')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123')
            ->call('save')
            ->assertHasErrors(['form.email']);
    }

    public function test_insert_mapping_maps_empty_email_to_null(): void
    {
        // The insert helper must store NULL (not '') when no email is given, so the
        // nullable+unique index on shop_customer.email admits many email-less
        // customers (MySQL allows many NULLs, only one ''). Deterministic — no DB.
        // @aidlc-story US-SADM-004
        $missing = gp247_customer_data_insert_mapping(['first_name' => 'A', 'password' => 'x']);
        $this->assertNull($missing['dataInsert']['email']);

        $blank = gp247_customer_data_insert_mapping(['first_name' => 'A', 'email' => '', 'password' => 'x']);
        $this->assertNull($blank['dataInsert']['email']);

        $filled = gp247_customer_data_insert_mapping(['first_name' => 'A', 'email' => 'a@b.com', 'password' => 'x']);
        $this->assertSame('a@b.com', $filled['dataInsert']['email']);
    }

    public function test_creates_two_customers_without_email_without_collision(): void
    {
        // Regression for `1062 Duplicate entry '' for key gp247_shop_customer_email_unique`:
        // creating a second email-less customer used to insert '' and collide.
        // @aidlc-story US-SADM-004
        $this->asUser(slugs: ['admin_customer']);

        Livewire::test(CustomerManager::class)
            ->set('form.email', '')
            ->set('form.first_name', 'No Email One')
            ->set('form.last_name', 'Last')
            ->set('form.country', 'VN')
            ->set('form.address1', 'Street 1')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test(CustomerManager::class)
            ->set('form.email', '')
            ->set('form.first_name', 'No Email Two')
            ->set('form.last_name', 'Last')
            ->set('form.country', 'VN')
            ->set('form.address1', 'Street 2')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123')
            ->call('save')
            ->assertHasNoErrors();

        // Both persisted, and neither stored an empty string.
        $this->assertSame(2, ShopCustomer::whereIn('first_name', ['No Email One', 'No Email Two'])->count());
        $this->assertSame(0, ShopCustomer::where('email', '')->count());
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->asUser(slugs: ['admin_customer']);

        Livewire::test(CustomerManager::class)
            ->set('form.email', 'zz-mismatch@example.com')
            ->set('form.first_name', 'ZZ Mismatch')
            ->set('form.address1', 'x')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'different')
            ->call('save')
            ->assertHasErrors(['form.password']);
    }

    public function test_edit_loads_via_route_and_updates_without_password(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $row = $this->makeCustomer(['email' => 'zz-edit@example.com', 'first_name' => 'ZZ Edit']);

        Livewire::test(CustomerManager::class, ['id' => (string) $row->id])
            ->assertSet('form.email', 'zz-edit@example.com')
            ->assertSet('editingId', (string) $row->id)
            ->set('form.first_name', 'ZZ Edited')
            ->set('form.address2', 'Apt 2')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited', ShopCustomer::find($row->id)->first_name);
    }

    public function test_delete_removes_customer(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $row = $this->makeCustomer(['email' => 'zz-delete@example.com']);

        Livewire::test(CustomerManager::class)->call('delete', $row->id);

        $this->assertNull(ShopCustomer::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(CustomerManager::class)
            ->set('form.email', 'zz-nope@example.com')
            ->set('form.first_name', 'Nope')
            ->set('form.address1', 'x')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopCustomer::where('email', 'zz-nope@example.com')->exists());
    }
}
