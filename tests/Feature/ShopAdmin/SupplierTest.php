<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\SupplierManager;
use GP247\Shop\Models\ShopSupplier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin supplier manager (ADR-006/007): two-column form+list,
 * create/edit (alias auto + unique, name + image required, email format), inline
 * edit, delete, and Layer-2 authorization. Domain unchanged (ShopSupplier).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class SupplierTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function makeSupplier(array $overrides = []): ShopSupplier
    {
        return ShopSupplier::create(array_merge([
            'name' => 'ZZ Seed Supplier',
            'alias' => 'zz-seed-supplier-' . uniqid(),
            'image' => 'path/seed.png',
            'email' => 'seed@supplier.test',
            'store_id' => 1,
            'sort' => 0,
        ], $overrides));
    }

    public function test_lists_existing_suppliers(): void
    {
        $this->asUser(slugs: ['admin_supplier']);
        $this->makeSupplier(['name' => 'ZZ List Supplier']);

        Livewire::test(SupplierManager::class)->assertSee('ZZ List Supplier');
    }

    public function test_creates_supplier_with_auto_alias(): void
    {
        $this->asUser(slugs: ['admin_supplier']);

        Livewire::test(SupplierManager::class)
            ->set('form.name', 'ZZ Created Supplier')
            ->set('form.image', 'path/created.png')
            ->set('form.email', 'new@supplier.test')
            ->set('form.sort', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $row = ShopSupplier::where('name', 'ZZ Created Supplier')->first();
        $this->assertNotNull($row);
        $this->assertSame('zz-created-supplier', $row->alias);
        $this->assertSame('new@supplier.test', $row->email);
    }

    public function test_create_requires_name_image_and_valid_email(): void
    {
        $this->asUser(slugs: ['admin_supplier']);

        Livewire::test(SupplierManager::class)
            ->set('form.name', '')
            ->set('form.image', '')
            ->set('form.email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.image', 'form.email']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        $this->asUser(slugs: ['admin_supplier']);
        $row = $this->makeSupplier(['name' => 'ZZ Edit Supplier']);

        Livewire::test(SupplierManager::class, ['id' => (string) $row->id])
            ->assertSet('form.name', 'ZZ Edit Supplier')
            ->set('form.name', 'ZZ Edited Supplier')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited Supplier', ShopSupplier::find($row->id)->name);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_supplier']);
        $row = $this->makeSupplier(['name' => 'ZZ Delete Supplier']);

        Livewire::test(SupplierManager::class)->call('delete', $row->id);

        $this->assertNull(ShopSupplier::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(SupplierManager::class)
            ->set('form.name', 'Nope Supplier')
            ->set('form.image', 'path/nope.png')
            ->set('form.sort', 0);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopSupplier::where('name', 'Nope Supplier')->exists());
    }
}
