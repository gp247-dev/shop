<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Variant slug authoring in the shop-admin product form
 * (US-SADM-product-attribute-slug): each variant row carries a slug that is
 * normalized to kebab-case on save (gp247_word_format_url), duplicates allowed,
 * empty allowed. attributeSlugOptions() offers the DISTINCT non-empty slugs of one
 * attribute group for the datalist. Gated by `admin_product`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-attribute-slug
 * @aidlc-adr shop-admin_product-attribute-slug
 */
class ProductVariantSlugTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    private function makeCategory(): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-slugcat-' . uniqid(), 'status' => 1, 'sort' => 0]);
    }

    private function makeGroup(): ShopAttributeGroup
    {
        return ShopAttributeGroup::create(['name' => 'Color ' . uniqid(), 'type' => 'radio']);
    }

    public function test_saves_slug_normalized_kebab_case(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();
        $group = $this->makeGroup();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-SLUG-SKU')
            ->set('form.alias', 'zz-slug-alias')
            ->set('form.category', [(string) $category->id])
            ->set('variants', [
                ['attribute_group_id' => (string) $group->id, 'name' => 'Red', 'add_price' => 5, 'slug' => 'Scarlet Red', 'sort' => 0, 'status' => 1],
            ]);
        $this->withNames($component, 'Slug Product')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-SLUG-SKU')->first();
        $row = ShopProductAttribute::where('product_id', $product->id)->first();
        // "Scarlet Red" → normalized kebab-case.
        $this->assertSame('scarlet-red', $row->slug);
    }

    public function test_slug_never_contains_double_underscore_separator(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();
        $group = $this->makeGroup();

        // Cart options encode a variant as "name__add_price__slug" and read the
        // slug back as explode('__', ...)[2]. A slug carrying "__" would split
        // into extra segments and silently truncate, so normalization must fold
        // underscores away (NFR-SEC-product-attribute-slug).
        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-SLUG-USCORE')
            ->set('form.alias', 'zz-slug-uscore')
            ->set('form.category', [(string) $category->id])
            ->set('variants', [
                ['attribute_group_id' => (string) $group->id, 'name' => 'Red', 'add_price' => 5, 'slug' => 'Scarlet__Red_XL', 'sort' => 0, 'status' => 1],
            ]);
        $this->withNames($component, 'Slug Underscore')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-SLUG-USCORE')->first();
        $row = ShopProductAttribute::where('product_id', $product->id)->first();
        // "Scarlet__Red_XL" → underscores fold to hyphens, runs collapse.
        $this->assertSame('scarlet-red-xl', $row->slug);
        $this->assertStringNotContainsString('_', (string) $row->slug);
    }

    public function test_empty_slug_allowed(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();
        $group = $this->makeGroup();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-SLUG-EMPTY')
            ->set('form.alias', 'zz-slug-empty')
            ->set('form.category', [(string) $category->id])
            ->set('variants', [
                ['attribute_group_id' => (string) $group->id, 'name' => 'Blue', 'add_price' => 0, 'slug' => '', 'sort' => 0, 'status' => 1],
            ]);
        $this->withNames($component, 'Slug Empty')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-SLUG-EMPTY')->first();
        $row = ShopProductAttribute::where('product_id', $product->id)->first();
        $this->assertSame('', (string) $row->slug);
    }

    public function test_edit_loads_slug_into_state(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $group = $this->makeGroup();
        $product = $this->makeProduct('Slug Edit', ['sku' => 'ZZ-SLUG-EDIT']);
        $product->attributes()->create([
            'attribute_group_id' => $group->id, 'name' => 'Green', 'add_price' => 0, 'slug' => 'emerald', 'sort' => 0, 'status' => 1,
        ]);

        $component = Livewire::test(ProductManager::class, ['id' => (string) $product->id]);
        $this->assertSame('emerald', $component->get('variants')[0]['slug']);
    }

    public function test_attribute_slug_options_are_distinct_per_group(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $groupA = $this->makeGroup();
        $groupB = $this->makeGroup();
        $product = $this->makeProduct('Slug Options', ['sku' => 'ZZ-SLUG-OPT']);

        // Two rows in group A sharing a slug (duplicates allowed) + one distinct;
        // one row in group B with a slug that must NOT leak into group A's options.
        $product->attributes()->create(['attribute_group_id' => $groupA->id, 'name' => 'R1', 'slug' => 'red', 'add_price' => 0, 'sort' => 0, 'status' => 1]);
        $product->attributes()->create(['attribute_group_id' => $groupA->id, 'name' => 'R2', 'slug' => 'red', 'add_price' => 0, 'sort' => 0, 'status' => 1]);
        $product->attributes()->create(['attribute_group_id' => $groupA->id, 'name' => 'B1', 'slug' => 'blue', 'add_price' => 0, 'sort' => 0, 'status' => 1]);
        $product->attributes()->create(['attribute_group_id' => $groupB->id, 'name' => 'S1', 'slug' => 'small', 'add_price' => 0, 'sort' => 0, 'status' => 1]);

        $options = Livewire::test(ProductManager::class)->instance()->attributeSlugOptions($groupA->id);

        sort($options);
        $this->assertSame(['blue', 'red'], $options); // DISTINCT, group B's "small" excluded
    }

    public function test_attribute_slug_options_empty_for_no_group(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();

        $this->assertSame([], Livewire::test(ProductManager::class)->instance()->attributeSlugOptions(''));
    }
}
