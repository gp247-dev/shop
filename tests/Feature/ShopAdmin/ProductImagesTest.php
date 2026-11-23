<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductImage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the product gallery sub-panel (group F, US-SADM-001): add/remove
 * sub-images, persisted into ShopProductImage. Domain unchanged. Gated by
 * `admin_product`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class ProductImagesTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;

    private function makeCategory(): ShopCategory
    {
        return ShopCategory::create(['alias' => 'zz-imgcat-' . uniqid(), 'status' => 1, 'sort' => 0]);
    }

    public function test_saves_gallery_images(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $category = $this->makeCategory();

        $component = Livewire::test(ProductManager::class)
            ->set('form.sku', 'ZZ-IMG-SKU')
            ->set('form.alias', 'zz-img-alias')
            ->set('form.category', [(string) $category->id])
            ->set('gallery', ['gallery/a.jpg', 'gallery/b.jpg']);
        $this->withNames($component, 'Img Product')->call('save')->assertHasNoErrors();

        $product = ShopProduct::where('sku', 'ZZ-IMG-SKU')->first();
        $this->assertSame(2, ShopProductImage::where('product_id', $product->id)->count());
    }

    public function test_edit_loads_gallery(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $product = $this->makeProduct('Img Edit', ['sku' => 'ZZ-IMG-EDIT']);
        $product->images()->create(['image' => 'gallery/x.jpg']);
        $product->images()->create(['image' => 'gallery/y.jpg']);

        $component = Livewire::test(ProductManager::class, ['id' => (string) $product->id]);
        $this->assertCount(2, $component->get('gallery'));
    }

    public function test_remove_gallery_image(): void
    {
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();

        $component = Livewire::test(ProductManager::class)
            ->set('gallery', ['gallery/a.jpg', 'gallery/b.jpg'])
            ->call('removeGalleryImage', 0);

        $this->assertCount(1, $component->get('gallery'));
        $this->assertSame('gallery/b.jpg', $component->get('gallery')[0]);
    }
}
