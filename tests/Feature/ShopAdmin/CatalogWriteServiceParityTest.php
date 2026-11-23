<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Admin\Livewire\CategoryManager;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Admin\Livewire\SupplierManager;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Models\ShopSupplier;
use GP247\Shop\Services\Catalog\BrandWriter;
use GP247\Shop\Services\Catalog\CategoryWriter;
use GP247\Shop\Services\Catalog\CustomerWriter;
use GP247\Shop\Services\Catalog\ProductWriter;
use GP247\Shop\Services\Catalog\SupplierWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The admin screens and any other writer (a data import, a CLI run) write products,
 * categories, brands, suppliers and customers through one shared service, so both give
 * the same result under the same rules: alias, multilingual texts, same-store references,
 * fields switched off in Shop Config, custom fields, events.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-catalog-write-service
 * @aidlc-adr shop-admin_catalog-write-service
 */
#[Group('shop-admin')]
class CatalogWriteServiceParityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    private function importContext(?string $store = null): WriteContext
    {
        return new WriteContext($store ?? (string) $this->storeId(), WriteContext::ACTOR_IMPORT);
    }

    private function makeCategory(?string $store = null): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-wcat-' . uniqid(), 'status' => 1, 'sort' => 0, 'store_id' => $store ?? $this->storeId()]);
    }

    /**
     * Texts in every active language.
     *
     * @return array<string, array<string, string>>
     */
    private function texts(string $name, string $content = 'Body'): array
    {
        $out = [];
        foreach ($this->langCodes() as $code) {
            $out[$code] = ['name' => $name, 'keyword' => '', 'description' => '', 'content' => $content];
        }

        return $out;
    }

    /**
     * What a saved product looks like, without its identity (id, sku, alias, dates).
     *
     * @return array<string, mixed>
     */
    private function productShape(ShopProduct $product): array
    {
        $product->refresh();

        return [
            'columns' => collect($product->getAttributes())
                ->except(['id', 'sku', 'alias', 'created_at', 'updated_at'])
                ->map(static fn ($v) => (string) $v)->sortKeys()->all(),
            'texts' => ShopProductDescription::where('product_id', $product->id)->orderBy('lang')->get(['lang', 'name', 'keyword', 'description', 'content'])->toArray(),
            'categories' => $product->categories()->pluck('category_id')->map(static fn ($id) => (string) $id)->sort()->values()->all(),
            'tags' => $product->tags()->pluck('name')->sort()->values()->all(),
        ];
    }

    public function test_a_product_written_by_the_service_matches_the_same_product_saved_on_the_admin_screen(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-PARITY-SCREEN')
            ->set('form.alias', 'zz-parity-screen')
            ->set('form.price', 25)
            ->set('form.cost', 10)
            ->set('form.stock', 7)
            ->set('form.category', [(string) $category->id])
            ->set('form.tags', 'Green Tea, Gift');
        $this->withNames($component, 'Parity Product')->call('save')->assertHasNoErrors();
        $screen = ShopProduct::where('sku', 'ZZ-PARITY-SCREEN')->firstOrFail();

        $service = (new ProductWriter)->save($this->importContext(), [
            'form' => [
                'sku' => 'ZZ-PARITY-SERVICE', 'alias' => 'zz-parity-service',
                'price' => 25, 'cost' => 10, 'stock' => 7, 'category' => [(string) $category->id], 'tags' => 'Green Tea, Gift',
            ],
            'descriptions' => $this->texts('Parity Product'),
        ]);

        $this->assertEquals($this->productShape($screen), $this->productShape($service));
    }

    public function test_the_service_derives_the_alias_from_the_first_language_name_like_the_screen(): void
    {
        $category = $this->makeCategory();

        $product = (new ProductWriter)->save($this->importContext(), [
            'form' => ['sku' => 'ZZ-ALIAS-' . uniqid(), 'category' => [(string) $category->id]],
            'descriptions' => $this->texts('Matcha Latte Set ' . uniqid()),
        ]);

        $this->assertStringStartsWith('matcha-latte-set-', (string) $product->alias);
    }

    public function test_the_service_refuses_a_category_or_brand_of_another_store_with_the_field_of_the_form(): void
    {
        $foreignCategory = $this->makeCategory('zz-other-store');
        $foreignBrand = ShopBrand::create(['name' => 'ZZ Foreign', 'alias' => 'zz-fb-' . uniqid(), 'image' => 'x.png', 'store_id' => 'zz-other-store']);
        $writer = new ProductWriter;

        foreach ([['category' => [(string) $foreignCategory->id]], ['category' => [(string) $this->makeCategory()->id], 'brand_id' => (string) $foreignBrand->id]] as $refs) {
            try {
                $writer->save($this->importContext(), [
                    'form' => array_merge(['sku' => 'ZZ-XSTORE-' . uniqid(), 'alias' => 'zz-xstore-' . uniqid()], $refs),
                    'descriptions' => $this->texts('Cross store'),
                ]);
                $this->fail('A reference to another store must be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey(isset($refs['brand_id']) ? 'form.brand_id' : 'form.category', $e->errors());
            }
        }
    }

    public function test_checking_a_row_reports_the_same_errors_as_the_screen_and_writes_nothing(): void
    {
        $existing = $this->makeProduct('Taken', ['sku' => 'ZZ-TAKEN-' . uniqid()]);
        $before = ShopProduct::count();

        $errors = (new ProductWriter)->validate($this->importContext(), [
            'form' => ['sku' => $existing->sku, 'alias' => 'zz-check-' . uniqid(), 'category' => []],
            'descriptions' => $this->texts(''),
        ]);

        $this->assertArrayHasKey('form.sku', $errors);
        $this->assertArrayHasKey('form.category', $errors);
        $this->assertArrayHasKey('desc.' . $this->langCodes()[0] . '.name', $errors);
        $this->assertSame($before, ShopProduct::count());
    }

    public function test_an_update_through_the_service_only_touches_the_parts_it_was_given(): void
    {
        $category = $this->makeCategory();
        $writer = new ProductWriter;
        $product = $writer->save($this->importContext(), [
            'form' => ['sku' => 'ZZ-PART-' . uniqid(), 'alias' => 'zz-part-' . uniqid(), 'price' => 10, 'stock' => 5, 'category' => [(string) $category->id]],
            'descriptions' => $this->texts('Kept name', 'Kept body'),
        ]);

        $writer->save($this->importContext(), ['form' => ['price' => 99]], (string) $product->id);

        $product->refresh();
        $this->assertEquals(99, (float) $product->price);
        $this->assertEquals(5, (float) $product->stock);
        $this->assertSame([(string) $category->id], $product->categories()->pluck('category_id')->map(static fn ($id) => (string) $id)->all());
        $this->assertSame('Kept name', (string) ShopProductDescription::where('product_id', $product->id)->value('name'));
    }

    public function test_the_service_will_not_edit_a_record_of_another_store(): void
    {
        $product = $this->makeProduct('Theirs', ['store_id' => 'zz-other-store']);

        $this->expectException(ValidationException::class);
        (new ProductWriter)->save($this->importContext(), ['form' => ['price' => 1]], (string) $product->id);
    }

    public function test_a_write_inside_the_callers_transaction_is_undone_with_it(): void
    {
        $category = $this->makeCategory();
        $sku = 'ZZ-TX-' . uniqid();
        $connection = defined('GP247_DB_CONNECTION') ? GP247_DB_CONNECTION : null;

        try {
            DB::connection($connection)->transaction(function () use ($sku, $category): void {
                (new ProductWriter)->save($this->importContext(), [
                    'form' => ['sku' => $sku, 'alias' => strtolower($sku), 'category' => [(string) $category->id]],
                    'descriptions' => $this->texts('Rolled back'),
                ]);
                throw new \RuntimeException('caller failed after the write');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertNull(ShopProduct::where('sku', $sku)->first());
    }

    public function test_a_category_written_by_the_service_matches_the_screen_and_keeps_its_parent_in_the_same_store(): void
    {
        $this->asUser(slugs: ['admin_category']);
        $this->useAdminStore();
        $parent = $this->makeCategory();

        $component = Livewire::test(CategoryManager::class)->set('form.alias', 'zz-cat-screen-' . uniqid())->set('form.parent', (string) $parent->id)->set('form.sort', 3);
        foreach ($this->langCodes() as $code) {
            $component->set("desc.{$code}.name", 'Teaware');
        }
        $component->call('save')->assertHasNoErrors();
        $screen = ShopCategory::where('parent', $parent->id)->latest('id')->firstOrFail();

        $service = (new CategoryWriter)->save($this->importContext(), [
            'form' => ['parent' => (string) $parent->id, 'sort' => 3],
            'descriptions' => $this->texts('Teaware Import ' . uniqid()),
        ]);

        foreach (['parent', 'top', 'status', 'sort', 'store_id', 'image'] as $column) {
            $this->assertSame((string) $screen->{$column}, (string) $service->{$column}, $column);
        }
        $this->assertStringStartsWith('teaware-import-', (string) $service->alias);
        $this->assertSame(count($this->langCodes()), ShopCategoryDescription::where('category_id', $service->id)->count());

        $this->expectException(ValidationException::class);
        (new CategoryWriter)->save($this->importContext(), [
            'form' => ['parent' => (string) $this->makeCategory('zz-other-store')->id],
            'descriptions' => $this->texts('Wrong parent'),
        ]);
    }

    public function test_brands_and_suppliers_take_their_alias_from_the_name_like_the_screens(): void
    {
        $this->asUser(slugs: ['admin_brand', 'admin_supplier']);
        $this->useAdminStore();
        $name = 'Kyoto Kiln ' . uniqid();

        Livewire::test(BrandManager::class)->set('form.name', $name)->set('form.image', 'b.png')->set('form.sort', 0)->call('save')->assertHasNoErrors();
        $screenAlias = (string) ShopBrand::where('name', $name)->value('alias');
        ShopBrand::where('name', $name)->delete();
        $brand = (new BrandWriter)->save($this->importContext(), ['form' => ['name' => $name, 'image' => 'b.png', 'sort' => 0]]);
        $this->assertSame($screenAlias, (string) $brand->alias);
        $this->assertSame((string) $this->storeId(), (string) $brand->store_id);

        Livewire::test(SupplierManager::class)->set('form.name', $name)->set('form.image', 's.png')->set('form.sort', 0)->call('save')->assertHasNoErrors();
        $screenAlias = (string) ShopSupplier::where('name', $name)->value('alias');
        ShopSupplier::where('name', $name)->delete();
        $supplier = (new SupplierWriter)->save($this->importContext(), ['form' => ['name' => $name, 'image' => 's.png', 'sort' => 0, 'email' => 'kiln@example.test']]);
        $this->assertSame($screenAlias, (string) $supplier->alias);

        $errors = (new SupplierWriter)->validate($this->importContext(), ['form' => ['name' => 'Bad', 'image' => 's.png', 'sort' => 0, 'email' => 'not-an-email']]);
        $this->assertArrayHasKey('form.email', $errors);
    }

    public function test_an_imported_customer_keeps_a_bcrypt_hash_and_without_one_cannot_sign_in_until_reset(): void
    {
        $writer = new CustomerWriter;
        $base = ['first_name' => 'Hanako', 'last_name' => 'Yamada', 'country' => 'JP', 'address1' => 'Shibuya 1', 'phone' => '0900000000', 'status' => 1];

        $hash = Hash::make('old-shop-password');
        $kept = $writer->save($this->importContext(), $base + ['email' => 'zz-imp-' . uniqid() . '@example.test', 'password_hash' => $hash]);
        $this->assertSame($hash, (string) ShopCustomer::whereKey($kept->id)->value('password'));
        $this->assertSame((string) $this->storeId(), (string) $kept->store_id);

        $blank = $writer->save($this->importContext(), $base + ['email' => 'zz-imp-' . uniqid() . '@example.test']);
        $stored = (string) ShopCustomer::whereKey($blank->id)->value('password');
        $this->assertNotSame('', $stored);
        $this->assertFalse(Hash::check('', $stored));

        $errors = $writer->validate($this->importContext(), $base + ['email' => 'zz-imp-' . uniqid() . '@example.test', 'password_hash' => md5('x')]);
        $this->assertArrayHasKey('password_hash', $errors);
    }

    public function test_a_customer_written_by_the_service_matches_the_same_customer_saved_on_the_screen(): void
    {
        $this->asUser(slugs: ['admin_customer']);
        $this->useAdminStore();
        $fields = ['first_name' => 'Taro', 'last_name' => 'Sato', 'country' => 'JP', 'address1' => 'Ueno 2', 'phone' => '0900000001'];

        $component = Livewire::test(\GP247\Shop\Admin\Livewire\CustomerManager::class);
        foreach ($fields + ['email' => 'zz-cscreen-' . uniqid() . '@example.test', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123'] as $field => $value) {
            $component->set('form.' . $field, $value);
        }
        $component->call('save')->assertHasNoErrors();
        $screen = ShopCustomer::where('first_name', 'Taro')->where('email', 'like', 'zz-cscreen-%')->latest('id')->firstOrFail();

        $service = (new CustomerWriter)->save(new WriteContext((string) $this->storeId()), $fields + [
            'email' => 'zz-cservice-' . uniqid() . '@example.test', 'password' => 'Secret-pass-123', 'password_confirmation' => 'Secret-pass-123', 'status' => 1,
        ]);

        $shape = static fn (ShopCustomer $c): array => collect($c->fresh()->getAttributes())
            ->except(['id', 'email', 'password', 'address_id', 'created_at', 'updated_at', 'remember_token'])->map(static fn ($v) => (string) $v)->sortKeys()->all();
        $this->assertEquals($shape($screen), $shape($service));
        $this->assertSame(1, $service->addresses()->count());
        $this->assertTrue(Hash::check('Secret-pass-123', (string) $service->fresh()->password));
    }
}
