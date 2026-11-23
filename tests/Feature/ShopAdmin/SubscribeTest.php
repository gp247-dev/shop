<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\SubscribeManager;
use GP247\Shop\Admin\Models\AdminSubscribe;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin subscribe (newsletter) manager (ADR-006/007):
 * two-column form+list on the core ResourcePanel base, email + status,
 * scoped to the current admin store. Domain unchanged (FrontSubscribe).
 * Gated by `admin_subscribe`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-004
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class SubscribeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * @param array<string, mixed> $overrides
     * @return AdminSubscribe
     */
    private function makeSubscribe(array $overrides = []): AdminSubscribe
    {
        return AdminSubscribe::create(array_merge([
            'email' => 'zz-' . uniqid() . '@example.com',
            'status' => 1,
            'store_id' => session('adminStoreId', GP247_STORE_ID_ROOT),
        ], $overrides));
    }

    public function test_lists_existing_subscribers(): void
    {
        $this->asUser(slugs: ['admin_subscribe']);
        $this->makeSubscribe(['email' => 'zz-list@example.com']);

        Livewire::test(SubscribeManager::class)->assertSee('zz-list@example.com');
    }

    public function test_creates_subscriber(): void
    {
        $this->asUser(slugs: ['admin_subscribe']);

        Livewire::test(SubscribeManager::class)
            ->set('form.email', 'zz-created@example.com')
            ->set('form.status', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertTrue(AdminSubscribe::where('email', 'zz-created@example.com')->exists());
    }

    public function test_create_requires_valid_email(): void
    {
        $this->asUser(slugs: ['admin_subscribe']);

        Livewire::test(SubscribeManager::class)
            ->set('form.email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['form.email']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        $this->asUser(slugs: ['admin_subscribe']);
        $row = $this->makeSubscribe(['email' => 'zz-edit@example.com']);

        Livewire::test(SubscribeManager::class, ['id' => (string) $row->id])
            ->assertSet('form.email', 'zz-edit@example.com')
            ->assertSet('editingId', (string) $row->id)
            ->set('form.email', 'zz-edited@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('zz-edited@example.com', AdminSubscribe::find($row->id)->email);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_subscribe']);
        $row = $this->makeSubscribe(['email' => 'zz-delete@example.com']);

        Livewire::test(SubscribeManager::class)->call('delete', $row->id);

        $this->assertNull(AdminSubscribe::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(SubscribeManager::class)->set('form.email', 'nope@example.com');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(AdminSubscribe::where('email', 'nope@example.com')->exists());
    }
}
