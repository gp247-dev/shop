<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\LanguageManager;
use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Admin\Livewire\CustomerManager;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCustomer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Two-panel screens (ResourcePanel) keep the list on the page that holds the
 * record being edited — after a deep link without ?page, after a save that moves
 * the record, and after a create — and a create always ends in edit mode of the
 * new record (or an empty form), never in add mode with the old input.
 * BrandManager stands in for every ResourcePanel screen (shared base behaviour).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-two-panel-state-preservation
 * @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation
 */
class TwoPanelRecordPageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** Rows per page of ResourcePanel. */
    private const PER_PAGE = 15;

    /** Keyword isolating this test's rows from the live catalogue. */
    private const TOKEN = 'zqtwopanel';

    /**
     * Seed $count brands named "<TOKEN>-01".."<TOKEN>-NN" (all sort = 0).
     *
     * @return array<int, ShopBrand> Indexed 1..$count.
     */
    private function seedBrands(int $count = 20): array
    {
        $brands = [];
        for ($i = 1; $i <= $count; $i++) {
            $label = sprintf('%s-%02d', self::TOKEN, $i);
            $brands[$i] = ShopBrand::create([
                'name' => $label, 'alias' => $label, 'image' => 'path/seed.png', 'sort' => 0, 'status' => 1,
            ]);
        }

        return $brands;
    }

    private function currentPage(Testable $component): int
    {
        return (int) (($component->get('paginators') ?? [])['page'] ?? 1);
    }

    /**
     * Oracle: the page a brand sits on in the default list (id desc), computed
     * independently by walking the same base query in order.
     */
    private function expectedDefaultPage(Testable $component, string $id): int
    {
        $method = new \ReflectionMethod($component->instance(), 'baseQuery');
        $method->setAccessible(true);
        $ids = $method->invoke($component->instance())->orderBy('id', 'desc')->pluck('id')
            ->map(fn ($v) => (string) $v)->all();
        $position = array_search($id, $ids, true);
        $this->assertNotFalse($position, 'seeded brand must be in the list');

        return intdiv((int) $position, self::PER_PAGE) + 1;
    }

    public function test_deep_link_without_page_opens_the_list_on_the_page_holding_the_record(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands();

        // Pick a record that is NOT on page 1 of the default ordering.
        $probe = Livewire::test(BrandManager::class);
        $method = new \ReflectionMethod($probe->instance(), 'baseQuery');
        $method->setAccessible(true);
        $ids = $method->invoke($probe->instance())->orderBy('id', 'desc')->pluck('id')->all();
        $target = (string) $ids[self::PER_PAGE + 2];

        $component = Livewire::withQueryParams(['edit' => $target])->test(BrandManager::class);

        $component->assertSet('editingId', $target);
        $this->assertSame($this->expectedDefaultPage($component, $target), $this->currentPage($component));
        $this->assertGreaterThan(1, $this->currentPage($component));
    }

    public function test_edit_route_segment_without_page_also_jumps_to_the_record_page(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands();

        $probe = Livewire::test(BrandManager::class);
        $method = new \ReflectionMethod($probe->instance(), 'baseQuery');
        $method->setAccessible(true);
        $ids = $method->invoke($probe->instance())->orderBy('id', 'desc')->pluck('id')->all();
        $target = (string) $ids[self::PER_PAGE + 1];

        $component = Livewire::test(BrandManager::class, ['id' => $target]);

        $component->assertSet('editingId', $target);
        $this->assertSame($this->expectedDefaultPage($component, $target), $this->currentPage($component));
    }

    public function test_an_explicit_page_in_the_url_is_respected_on_refresh(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $brands = $this->seedBrands();

        $component = Livewire::withQueryParams(['edit' => (string) $brands[1]->id, 'page' => 1])
            ->test(BrandManager::class);

        $component->assertSet('editingId', (string) $brands[1]->id);
        $this->assertSame(1, $this->currentPage($component));
    }

    public function test_editing_a_row_on_the_current_page_keeps_that_page_and_highlights_the_row(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $brands = $this->seedBrands();

        $component = Livewire::test(BrandManager::class)
            ->set('keyword', self::TOKEN)
            ->call('setSort', 'name')
            ->call('gotoPage', 2)
            ->call('editRow', (string) $brands[18]->id);

        $this->assertSame(2, $this->currentPage($component));
        $component->assertSet('editingId', (string) $brands[18]->id)
            ->assertSeeHtml('bg-blue-100 border-l-4 border-blue-500');
    }

    public function test_saving_a_rename_that_moves_the_record_follows_it_to_its_new_page(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $brands = $this->seedBrands();

        // name asc, keyword-filtered: -01..-15 on page 1, -16..-20 on page 2.
        $component = Livewire::test(BrandManager::class)
            ->set('keyword', self::TOKEN)
            ->call('setSort', 'name')
            ->call('editRow', (string) $brands[3]->id);
        $this->assertSame(1, $this->currentPage($component));

        $component->set('form.name', self::TOKEN . '-99')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        // "-99" now sorts last (position 20) → page 2, still open and visible.
        $this->assertSame(2, $this->currentPage($component));
        $component->assertSet('editingId', (string) $brands[3]->id)
            ->assertSee(self::TOKEN . '-99');
    }

    public function test_equal_sort_values_are_ordered_by_key_so_the_located_page_is_stable(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $brands = $this->seedBrands();

        // Every seeded brand has sort = 0: only the key tie-break orders them.
        $ordered = collect($brands)->map(fn ($b) => (string) $b->id)->sort()->values()->all();
        $target = $ordered[17]; // 18th by key asc → page 2

        $component = Livewire::test(BrandManager::class)
            ->set('keyword', self::TOKEN)
            ->call('setSort', 'sort')
            ->call('gotoPage', 2)
            ->call('editRow', $target)
            ->set('form.url', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, $this->currentPage($component));
        $component->assertSee(ShopBrand::find($target)->name);
    }

    public function test_creating_a_record_switches_the_form_to_editing_it_on_its_page(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands();

        $component = Livewire::test(BrandManager::class)
            ->set('keyword', self::TOKEN)
            ->call('setSort', 'name')
            ->call('gotoPage', 2)
            ->set('form.name', self::TOKEN . '-00')
            ->set('form.image', 'path/new.png')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $created = ShopBrand::where('name', self::TOKEN . '-00')->firstOrFail();
        $component->assertSet('editingId', (string) $created->id)
            ->assertSet('form.name', self::TOKEN . '-00');
        // "-00" sorts first → page 1.
        $this->assertSame(1, $this->currentPage($component));
    }

    public function test_a_screen_that_cannot_report_the_created_id_falls_back_to_an_empty_form(): void
    {
        $this->asUser(slugs: ['admin_brand']);

        Livewire::test(BrandManagerWithoutCreatedId::class)
            ->set('form.name', self::TOKEN . '-orphan')
            ->set('form.image', 'path/new.png')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editingId', null)
            ->assertSet('form.name', '');

        $this->assertSame(1, ShopBrand::where('name', self::TOKEN . '-orphan')->count());
    }

    public function test_creating_a_customer_moves_the_list_to_the_page_holding_the_new_customer(): void
    {
        // CustomerManager writes its own save() (mapping helpers), so it must
        // follow the record itself.
        $this->asUser(slugs: ['admin_customer']);
        for ($i = 1; $i <= 20; $i++) {
            ShopCustomer::createCustomer([
                'first_name' => self::TOKEN, 'last_name' => 'Last',
                'email' => sprintf('%s-%02d@example.com', self::TOKEN, $i),
                'password' => bcrypt('secret123'), 'address1' => 'Addr 1', 'country' => 'VN', 'status' => 1,
                'store_id' => session('adminStoreId', GP247_STORE_ID_ROOT),
            ]);
        }

        // email asc, keyword-filtered: "-00" sorts first → page 1.
        $component = Livewire::test(CustomerManager::class)
            ->set('keyword', self::TOKEN)
            ->call('setSort', 'email')
            ->call('gotoPage', 2)
            ->set('form.email', self::TOKEN . '-00@example.com')
            ->set('form.first_name', self::TOKEN)
            ->set('form.last_name', 'Created')
            ->set('form.country', 'VN')
            ->set('form.address1', 'Some street')
            ->set('form.password', 'secret123')
            ->set('form.password_confirmation', 'secret123')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $created = ShopCustomer::where('email', self::TOKEN . '-00@example.com')->firstOrFail();
        $component->assertSet('editingId', (string) $created->id);
        $this->assertSame(1, $this->currentPage($component));
    }

    public function test_creating_a_language_switches_to_editing_the_new_language(): void
    {
        // A core screen that previously stayed in add mode after create.
        $this->asUser(slugs: ['admin_language']);

        $component = Livewire::test(LanguageManager::class)
            ->set('form.name', 'Testish')
            ->set('form.code', 'zz')
            ->set('form.icon', 'GP247/Core/images/no-image.jpg')
            ->call('save')
            ->assertHasNoErrors();

        $created = \GP247\Core\Models\AdminLanguage::where('code', 'zz')->firstOrFail();
        $component->assertSet('editingId', (string) $created->id)
            ->assertSet('form.code', 'zz');
    }
}

/**
 * Test double: a third-party style screen whose persist() does not expose the
 * created id (the pre-contract behaviour).
 */
class BrandManagerWithoutCreatedId extends BrandManager
{
    protected function persist(array $data): void
    {
        parent::persist($data);
        if (($this->form['name'] ?? '') !== '' && str_ends_with((string) $this->form['name'], '-orphan')) {
            $this->editingId = null;
        }
    }
}
