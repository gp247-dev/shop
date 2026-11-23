<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\AttributeGroupManager;
use GP247\Shop\Models\ShopAttributeGroup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin attribute-group manager (ADR-006/007): two-column
 * form+list on the core ResourcePanel base, mirroring the legacy
 * AdminAttributeGroupController (name + type radio/select). Domain unchanged
 * (ShopAttributeGroup). Gated by `admin_attribute_group`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-002
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class AttributeGroupTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Create a ShopAttributeGroup row for the test.
     *
     * @param array<string, mixed> $overrides
     * @return ShopAttributeGroup
     */
    private function makeGroup(array $overrides = []): ShopAttributeGroup
    {
        return ShopAttributeGroup::create(array_merge([
            'name' => 'ZZ Seed Group',
            'type' => 'radio',
            'sort' => 0,
            'status' => 1,
        ], $overrides));
    }

    public function test_lists_existing_groups(): void
    {
        $this->asUser(slugs: ['admin_attribute_group']);
        $this->makeGroup(['name' => 'ZZ List Group']);

        Livewire::test(AttributeGroupManager::class)->assertSee('ZZ List Group');
    }

    public function test_creates_group(): void
    {
        $this->asUser(slugs: ['admin_attribute_group']);

        Livewire::test(AttributeGroupManager::class)
            ->set('form.name', 'ZZ Created Group')
            ->set('form.type', 'select')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $row = ShopAttributeGroup::where('name', 'ZZ Created Group')->first();
        $this->assertNotNull($row);
        $this->assertSame('select', $row->type);
    }

    public function test_create_requires_name_and_type(): void
    {
        $this->asUser(slugs: ['admin_attribute_group']);

        Livewire::test(AttributeGroupManager::class)
            ->set('form.name', '')
            ->set('form.type', '')
            ->call('save')
            ->assertHasErrors(['form.name', 'form.type']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        $this->asUser(slugs: ['admin_attribute_group']);
        $row = $this->makeGroup(['name' => 'ZZ Edit Group', 'type' => 'radio']);

        Livewire::test(AttributeGroupManager::class, ['id' => (string) $row->id])
            ->assertSet('form.name', 'ZZ Edit Group')
            ->assertSet('editingId', (string) $row->id)
            ->set('form.name', 'ZZ Edited Group')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited Group', ShopAttributeGroup::find($row->id)->name);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_attribute_group']);
        $row = $this->makeGroup(['name' => 'ZZ Delete Group']);

        Livewire::test(AttributeGroupManager::class)->call('delete', $row->id);

        $this->assertNull(ShopAttributeGroup::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(AttributeGroupManager::class)
            ->set('form.name', 'Nope Group')
            ->set('form.type', 'radio');

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopAttributeGroup::where('name', 'Nope Group')->exists());
    }
}
