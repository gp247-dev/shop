<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Admin\Livewire\CategoryManager;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Admin\Livewire\SupplierManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Regression for the LFM folder-category wiring (US-SADM-001 follow-up): the
 * media picker URL must carry the screen's real category (?type=product /
 * category / brand / supplier), never the bogus ?type=image that maps to no
 * folder_categories entry.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-005, ADR-007
 */
class MediaTypeTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    public function test_product_image_uses_product_category(): void
    {
        $this->asUser(slugs: ['admin_product']);

        Livewire::test(ProductManager::class)
            ->assertSee('?type=product', false)
            ->assertDontSee('?type=image', false);
    }

    public function test_category_image_uses_category_category(): void
    {
        $this->asUser(slugs: ['admin_category']);

        Livewire::test(CategoryManager::class)
            ->assertSee('?type=category', false)
            ->assertDontSee('?type=image', false);
    }

    public function test_brand_image_uses_brand_category(): void
    {
        $this->asUser(slugs: ['admin_brand']);

        Livewire::test(BrandManager::class)
            ->assertSee('?type=brand', false)
            ->assertDontSee('?type=image', false);
    }

    public function test_supplier_image_uses_supplier_category(): void
    {
        $this->asUser(slugs: ['admin_supplier']);

        Livewire::test(SupplierManager::class)
            ->assertSee('?type=supplier', false)
            ->assertDontSee('?type=image', false);
    }
}
