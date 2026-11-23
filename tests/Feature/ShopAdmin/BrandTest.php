<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Models\ShopBrand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin brand manager (ADR-006/007): two-column form+list,
 * create/edit (alias auto + unique, name + image required), inline edit load,
 * delete, and Layer-2 authorization. Domain unchanged (ShopBrand).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class BrandTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function makeBrand(array $overrides = []): ShopBrand
    {
        return ShopBrand::create(array_merge([
            'name' => 'ZZ Seed Brand',
            'alias' => 'zz-seed-brand-' . uniqid(),
            'image' => 'path/seed.png',
            'sort' => 0,
            'status' => 1,
        ], $overrides));
    }

    public function test_lists_existing_brands(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->makeBrand(['name' => 'ZZ List Brand']);

        Livewire::test(BrandManager::class)->assertSee('ZZ List Brand');
    }

    public function test_creates_brand_with_auto_alias(): void
    {
        $this->asUser(slugs: ['admin_brand']);

        Livewire::test(BrandManager::class)
            ->set('form.name', 'ZZ Created Brand')
            ->set('form.image', 'path/created.png')
            ->set('form.sort', 2)
            ->set('form.status', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $row = ShopBrand::where('name', 'ZZ Created Brand')->first();
        $this->assertNotNull($row);
        $this->assertSame('zz-created-brand', $row->alias);
        $this->assertSame(2, (int) $row->sort);
    }

    public function test_create_requires_name_and_image(): void
    {
        $this->asUser(slugs: ['admin_brand']);

        Livewire::test(BrandManager::class)
            ->set('form.name', '')
            ->set('form.image', '')
            ->set('form.sort', -1)
            ->call('save')
            ->assertHasErrors(['form.name', 'form.image', 'form.sort']);
    }

    public function test_alias_must_be_unique(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->makeBrand(['name' => 'ZZ Dup', 'alias' => 'zz-dup-brand']);

        Livewire::test(BrandManager::class)
            ->set('form.name', 'ZZ Dup')
            ->set('form.alias', 'zz-dup-brand')
            ->set('form.image', 'path/x.png')
            ->set('form.sort', 0)
            ->call('save')
            ->assertHasErrors(['form.alias']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        // Edit is reached by the {id}/edit route, which loads the form on mount.
        $this->asUser(slugs: ['admin_brand']);
        $row = $this->makeBrand(['name' => 'ZZ Edit Brand']);

        Livewire::test(BrandManager::class, ['id' => (string) $row->id])
            ->assertSet('form.name', 'ZZ Edit Brand')
            ->assertSet('editingId', (string) $row->id)
            ->set('form.name', 'ZZ Edited Brand')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited Brand', ShopBrand::find($row->id)->name);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $row = $this->makeBrand(['name' => 'ZZ Delete Brand']);

        Livewire::test(BrandManager::class)->call('delete', $row->id);

        $this->assertNull(ShopBrand::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(BrandManager::class)
            ->set('form.name', 'Nope Brand')
            ->set('form.image', 'path/nope.png')
            ->set('form.sort', 0);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopBrand::where('name', 'Nope Brand')->exists());
    }
}
