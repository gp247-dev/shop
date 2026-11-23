<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminStore;
use GP247\Front\Admin\Livewire\LayoutBlockManager;
use GP247\Front\Admin\Livewire\LinkManager;
use GP247\Front\Admin\Livewire\PageManager;
use GP247\Front\Models\FrontLayoutBlock;
use GP247\Front\Models\FrontLink;
use GP247\Front\Models\FrontPage;
use GP247\Front\Models\FrontPageDescription;
use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Admin\Livewire\CategoryManager;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Store isolation of the two-panel (ResourcePanel) write paths: a store-admin bound to
 * one store can neither save nor delete a record of another store (page, brand,
 * category; delete also link and layout block) — not by changing the
 * client-side `editingId`, not by passing another store's id to delete(), and not when
 * the record was opened in its own store context and the session then moved to another
 * store (stale tab, re-login as a store-admin).
 * Their own store's records still save normally.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-store-scoped-resource, US-FADM-store-content-assignment, US-SADM-store-content-assignment
 * @aidlc-adr admin-shell_store-scoped-resource-panel
 */
class StoreScopedWriteIsolationTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * @return AdminStore A fresh sub-store.
     */
    private function makeStore(): AdminStore
    {
        $suffix = uniqid();

        return AdminStore::create([
            'language' => 'en', 'currency' => 'USD', 'template' => 'GP247Front',
            'code' => 'wi' . substr($suffix, -10), 'domain' => 'wi-' . $suffix . '.local', 'status' => 1,
        ]);
    }

    /**
     * @return array<int, string> Active language codes.
     */
    private function langCodes(): array
    {
        return array_keys(AdminLanguage::getListActive()->all());
    }

    /**
     * @param AdminStore $store Owner store.
     * @param string $title Title for every language.
     * @return FrontPage
     */
    private function makePage(AdminStore $store, string $title): FrontPage
    {
        $page = FrontPage::create(['image' => '', 'alias' => 'zz-wi-page-' . uniqid(), 'status' => 1, 'store_id' => $store->id]);
        foreach ($this->langCodes() as $code) {
            FrontPageDescription::create([
                'page_id' => $page->id, 'lang' => $code, 'name' => $title,
                'keyword' => '', 'description' => '', 'content' => '',
            ]);
        }

        return $page;
    }

    /**
     * @param AdminStore $store Owner store.
     * @param string $name Brand name.
     * @return ShopBrand
     */
    private function makeBrand(AdminStore $store, string $name): ShopBrand
    {
        return ShopBrand::create(['name' => $name, 'alias' => 'zz-wi-brand-' . uniqid(), 'image' => 'path/zz-wi.png', 'sort' => 0, 'status' => 1, 'store_id' => $store->id]);
    }

    /**
     * @param AdminStore $store Owner store.
     * @param string $title Title for every language.
     * @return ShopCategory
     */
    private function makeCategory(AdminStore $store, string $title): ShopCategory
    {
        $category = ShopCategory::create([
            'alias' => 'zz-wi-cat-' . uniqid(), 'image' => '', 'parent' => null,
            'top' => 0, 'status' => 1, 'sort' => 0, 'store_id' => $store->id,
        ]);
        foreach ($this->langCodes() as $code) {
            ShopCategoryDescription::insert([
                'category_id' => $category->id, 'lang' => $code, 'name' => $title, 'keyword' => '', 'description' => '',
            ]);
        }

        return $category;
    }

    /**
     * Run a write attempt that must be refused; refusal may surface as an exception
     * (locked property, record not found) or as a silent no-op — what matters is the data.
     *
     * @param callable $attempt
     * @return void
     */
    private function attempt(callable $attempt): void
    {
        try {
            $attempt();
        } catch (\Throwable $e) {
            // WHY: the assertions on the stored rows below decide the outcome.
        }
    }

    /**
     * @param FrontPage $page
     * @return array<int, string> Stored description names of the page.
     */
    private function pageNames(FrontPage $page): array
    {
        return FrontPageDescription::where('page_id', $page->id)->pluck('name')->unique()->values()->all();
    }

    public function test_the_record_being_edited_cannot_be_changed_from_the_browser(): void
    {
        // WHY: the lock covers every ResourcePanel screen, including those whose persist()
        // still loads the record without baseQuery() — guard it on its own.
        $this->asUser(slugs: ['admin_brand']);
        $brand = $this->makeBrand($this->makeStore(), 'ZZ Locked Brand');

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(BrandManager::class)->set('editingId', (string) $brand->id);
    }

    public function test_store_admin_cannot_point_page_save_at_another_store_by_changing_editing_id(): void
    {
        $this->asUser(slugs: ['admin_page']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makePage($other, 'ZZ Victim Page');
        $alias = $victim->alias;
        session(['adminStoreId' => $own->id]);

        $this->attempt(function () use ($victim): void {
            $c = Livewire::test(PageManager::class)->set('form.alias', 'zz-hacked-page');
            foreach ($this->langCodes() as $code) {
                $c->set("descriptions.{$code}.name", 'ZZ Hacked');
            }
            $c->set('editingId', (string) $victim->id)->call('save');
        });

        $this->assertSame($alias, FrontPage::find($victim->id)->alias);
        $this->assertSame((string) $other->id, (string) FrontPage::find($victim->id)->store_id);
        $this->assertSame(['ZZ Victim Page'], $this->pageNames($victim));
    }

    public function test_store_admin_cannot_point_brand_save_at_another_store_by_changing_editing_id(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makeBrand($other, 'ZZ Victim Brand');
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => Livewire::test(BrandManager::class)
            ->set('form.name', 'ZZ Hacked Brand')
            ->set('form.image', 'path/zz-hacked.png')
            ->set('editingId', (string) $victim->id)
            ->call('save'));

        $this->assertSame('ZZ Victim Brand', ShopBrand::find($victim->id)->name);
    }

    public function test_store_admin_cannot_point_category_save_at_another_store_by_changing_editing_id(): void
    {
        $this->asUser(slugs: ['admin_category']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makeCategory($other, 'ZZ Victim Category');
        $alias = $victim->alias;
        session(['adminStoreId' => $own->id]);

        $this->attempt(function () use ($victim): void {
            $c = Livewire::test(CategoryManager::class)->set('form.alias', 'zz-hacked-cat')->set('form.sort', 0);
            foreach ($this->langCodes() as $code) {
                $c->set("desc.{$code}.name", 'ZZ Hacked');
            }
            $c->set('editingId', (string) $victim->id)->call('save');
        });

        $this->assertSame($alias, ShopCategory::find($victim->id)->alias);
        $this->assertSame(
            ['ZZ Victim Category'],
            ShopCategoryDescription::where('category_id', $victim->id)->pluck('name')->unique()->values()->all()
        );
    }

    public function test_page_save_fails_closed_when_the_open_record_left_the_current_store_scope(): void
    {
        $this->asUser(slugs: ['admin_page']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makePage($other, 'ZZ Victim Page');
        $alias = $victim->alias;

        session(['adminStoreId' => $other->id]);
        $c = Livewire::test(PageManager::class, ['id' => $victim->id])->assertSet('editingId', (string) $victim->id);
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => $c->set('form.alias', 'zz-stale-tab-page')->call('save'));

        $this->assertSame($alias, FrontPage::find($victim->id)->alias);
    }

    public function test_brand_save_fails_closed_when_the_open_record_left_the_current_store_scope(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makeBrand($other, 'ZZ Victim Brand');

        session(['adminStoreId' => $other->id]);
        $c = Livewire::test(BrandManager::class, ['id' => $victim->id])->assertSet('editingId', (string) $victim->id);
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => $c->set('form.name', 'ZZ Stale Tab Brand')->call('save'));

        $this->assertSame('ZZ Victim Brand', ShopBrand::find($victim->id)->name);
    }

    public function test_category_save_fails_closed_when_the_open_record_left_the_current_store_scope(): void
    {
        $this->asUser(slugs: ['admin_category']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makeCategory($other, 'ZZ Victim Category');
        $alias = $victim->alias;

        session(['adminStoreId' => $other->id]);
        $c = Livewire::test(CategoryManager::class, ['id' => $victim->id])->assertSet('editingId', (string) $victim->id);
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => $c->set('form.alias', 'zz-stale-tab-cat')->call('save'));

        $this->assertSame($alias, ShopCategory::find($victim->id)->alias);
    }

    public function test_store_admin_cannot_delete_another_stores_page(): void
    {
        $this->asUser(slugs: ['admin_page']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $victim = $this->makePage($other, 'ZZ Victim Page');
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => Livewire::test(PageManager::class)->call('delete', (string) $victim->id));

        $this->assertNotNull(FrontPage::find($victim->id));
    }

    public function test_store_admin_cannot_delete_another_stores_brand_or_category(): void
    {
        $this->asUser(slugs: ['admin_brand', 'admin_category']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $brand = $this->makeBrand($other, 'ZZ Victim Brand');
        $category = $this->makeCategory($other, 'ZZ Victim Category');
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => Livewire::test(BrandManager::class)->call('delete', (string) $brand->id));
        $this->attempt(fn () => Livewire::test(CategoryManager::class)->call('delete', (string) $category->id));

        $this->assertNotNull(ShopBrand::find($brand->id));
        $this->assertNotNull(ShopCategory::find($category->id));
    }

    public function test_store_admin_cannot_delete_another_stores_link_or_layout_block(): void
    {
        $this->asUser(slugs: ['admin_link', 'admin_layout_block']);
        [$own, $other] = [$this->makeStore(), $this->makeStore()];
        $link = FrontLink::create([
            'name' => 'ZZ Victim Link', 'url' => '/zz-victim', 'type' => 'link', 'target' => '_self',
            'group' => 'main', 'sort' => 0, 'status' => 1, 'store_id' => $other->id,
        ]);
        $block = FrontLayoutBlock::create([
            'name' => 'ZZ Victim Block', 'position' => 'top', 'page' => '*', 'text' => '<p>victim</p>',
            'type' => 'html', 'sort' => 0, 'status' => 1, 'store_id' => $other->id, 'template' => '',
        ]);
        session(['adminStoreId' => $own->id]);

        $this->attempt(fn () => Livewire::test(LinkManager::class)->call('delete', (string) $link->id));
        $this->attempt(fn () => Livewire::test(LayoutBlockManager::class)->call('delete', (string) $block->id));

        $this->assertNotNull(FrontLink::find($link->id));
        $this->assertNotNull(FrontLayoutBlock::find($block->id));
    }

    public function test_store_admin_still_deletes_own_store_link_and_layout_block(): void
    {
        $this->asUser(slugs: ['admin_link', 'admin_layout_block']);
        $own = $this->makeStore();
        $link = FrontLink::create([
            'name' => 'ZZ Own Link', 'url' => '/zz-own', 'type' => 'link', 'target' => '_self',
            'group' => 'main', 'sort' => 0, 'status' => 1, 'store_id' => $own->id,
        ]);
        $block = FrontLayoutBlock::create([
            'name' => 'ZZ Own Block', 'position' => 'top', 'page' => '*', 'text' => '<p>own</p>',
            'type' => 'html', 'sort' => 0, 'status' => 1, 'store_id' => $own->id, 'template' => '',
        ]);
        session(['adminStoreId' => $own->id]);

        Livewire::test(LinkManager::class)->call('delete', (string) $link->id);
        Livewire::test(LayoutBlockManager::class)->call('delete', (string) $block->id);

        $this->assertNull(FrontLink::find($link->id));
        $this->assertNull(FrontLayoutBlock::find($block->id));
    }

    public function test_store_admin_still_saves_own_store_page_brand_and_category(): void
    {
        $this->asUser(slugs: ['admin_page', 'admin_brand', 'admin_category']);
        $own = $this->makeStore();
        $page = $this->makePage($own, 'ZZ Own Page');
        $brand = $this->makeBrand($own, 'ZZ Own Brand');
        $category = $this->makeCategory($own, 'ZZ Own Category');
        session(['adminStoreId' => $own->id]);

        $pageAlias = 'zz-own-page-' . substr(uniqid(), -6);
        Livewire::test(PageManager::class, ['id' => $page->id])->set('form.alias', $pageAlias)->call('save')->assertHasNoErrors();
        Livewire::test(BrandManager::class, ['id' => $brand->id])->set('form.name', 'ZZ Own Brand Edited')->call('save')->assertHasNoErrors();
        $catAlias = 'zz-own-cat-' . substr(uniqid(), -6);
        Livewire::test(CategoryManager::class, ['id' => $category->id])->set('form.alias', $catAlias)->call('save')->assertHasNoErrors();

        $this->assertSame($pageAlias, FrontPage::find($page->id)->alias);
        $this->assertSame('ZZ Own Brand Edited', ShopBrand::find($brand->id)->name);
        $this->assertSame($catAlias, ShopCategory::find($category->id)->alias);
    }
}
