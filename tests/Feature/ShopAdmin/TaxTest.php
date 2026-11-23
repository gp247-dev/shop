<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\TaxManager;
use GP247\Shop\Models\ShopTax;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin tax manager (ADR-006/007): two-column form+list,
 * create/edit validation, inline edit, delete, and Layer-2 authorization. Domain
 * unchanged (ShopTax).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-005
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class TaxTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function makeTax(array $overrides = []): ShopTax
    {
        return ShopTax::create(array_merge(['name' => 'ZZ Seed Tax', 'value' => 10], $overrides));
    }

    public function test_lists_existing_taxes(): void
    {
        $this->asUser(slugs: ['admin_tax']);
        $this->makeTax(['name' => 'ZZ List Tax']);

        Livewire::test(TaxManager::class)->assertSee('ZZ List Tax');
    }

    public function test_creates_tax(): void
    {
        $this->asUser(slugs: ['admin_tax']);

        Livewire::test(TaxManager::class)
            ->set('form.name', 'ZZ Created Tax')
            ->set('form.value', 8)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame(8, (int) ShopTax::where('name', 'ZZ Created Tax')->value('value'));
    }

    public function test_create_requires_name_and_numeric_value(): void
    {
        $this->asUser(slugs: ['admin_tax']);

        Livewire::test(TaxManager::class)
            ->set('form.name', '')
            ->set('form.value', 'abc')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.value']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        $this->asUser(slugs: ['admin_tax']);
        $row = $this->makeTax(['name' => 'ZZ Edit Tax']);

        Livewire::test(TaxManager::class, ['id' => (string) $row->id])
            ->assertSet('form.name', 'ZZ Edit Tax')
            ->set('form.name', 'ZZ Edited Tax')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited Tax', ShopTax::find($row->id)->name);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_tax']);
        $row = $this->makeTax(['name' => 'ZZ Delete Tax']);

        Livewire::test(TaxManager::class)->call('delete', $row->id);

        $this->assertNull(ShopTax::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(TaxManager::class)
            ->set('form.name', 'Nope Tax')
            ->set('form.value', 1);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopTax::where('name', 'Nope Tax')->exists());
    }
}
