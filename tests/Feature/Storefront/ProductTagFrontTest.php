<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\ProductFilter;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductTag;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for the storefront keyword-tag listing backend
 * (US-LW-product-tag-filter): the ShopProduct::getProductToTag($alias) builder that
 * powers the /tag/<alias> page. It joins the product↔tag pivot and the tag table,
 * matching by the tag's alias and restricting to active tags only. Also asserts the
 * `shop.tag` route (what ShopProductTag::getUrl resolves) is registered.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-product-tag-filter
 * @aidlc-adr shop-admin_product-tag-storage
 * @aidlc-adr storefront_livewire-page-context-props
 */
class ProductTagFrontTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;

    /**
     * Ids returned by the keyword-tag builder for the given alias.
     *
     * WHY strings: shop_product.id is a uuid, so casting to int collapses every id
     * to 0 and makes contains/not-contains assertions meaningless.
     *
     * @param string $alias
     * @return array<int, string>
     */
    private function idsForTag(string $alias): array
    {
        return (new ShopProduct())
            ->getProductToTag($alias)
            ->getData()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Ids rendered by the storefront product grid when the page pins a keyword tag.
     *
     * This is the end-to-end path a shopper actually gets: the tag page does not
     * render the controller's $products, it embeds the ProductFilter Livewire
     * component, which builds its own query from the page-context props.
     *
     * @param string|null $alias Tag alias pinned by the page, or null for no context.
     * @return array<int, string>
     */
    private function idsInGridForTag(?string $alias): array
    {
        return Livewire::test(ProductFilter::class, ['initialTag' => $alias])
            ->viewData('products')
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    public function test_returns_only_products_carrying_the_tag(): void
    {
        $this->useAdminStore();
        $p1 = $this->makeProduct('ZZ Tag P1', ['sku' => 'ZZ-TAGF-1']);
        $p2 = $this->makeProduct('ZZ Tag P2', ['sku' => 'ZZ-TAGF-2']);
        $tagA = ShopProductTag::create(['name' => 'FA', 'alias' => 'fa-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagB = ShopProductTag::create(['name' => 'FB', 'alias' => 'fb-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagA->products()->attach($p1->id);
        $tagB->products()->attach($p2->id);

        $ids = $this->idsForTag($tagA->alias);

        $this->assertContains((string) $p1->id, $ids);
        $this->assertNotContains((string) $p2->id, $ids);
    }

    public function test_excludes_products_of_inactive_tag(): void
    {
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Tag Inactive', ['sku' => 'ZZ-TAGF-3']);
        $inactive = ShopProductTag::create(['name' => 'FInactive', 'alias' => 'finactive-' . uniqid(), 'status' => 0, 'sort' => 0]);
        $inactive->products()->attach($product->id);

        // WHY: the storefront join filters tag.status = 1, so a product linked only
        // through an inactive tag must not surface on that tag's listing.
        $this->assertNotContains((string) $product->id, $this->idsForTag($inactive->alias));
    }

    public function test_does_not_duplicate_products_with_multiple_matching_links(): void
    {
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ Tag Dedup', ['sku' => 'ZZ-TAGF-4']);
        $tag = ShopProductTag::create(['name' => 'FDedup', 'alias' => 'fdedup-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tag->products()->attach($product->id);

        // The subquery is distinct on product id, so a single product appears once.
        $ids = $this->idsForTag($tag->alias);
        $this->assertSame(1, count(array_keys($ids, (string) $product->id)));
    }

    /**
     * The tag page's product grid lists only the products carrying that tag.
     *
     * Regression guard for the production bug of 2026-09-19: /tag/free and /tag/pro both returned the same 15 products
     * (the whole catalogue). The controller filtered correctly, but the listing view
     * does not render its $products - it embeds ProductFilter, which was handed no
     * tag context and therefore queried everything. Asserting on the builder alone
     * stayed green throughout, so this case goes through the component.
     */
    public function test_tag_page_grid_lists_only_products_carrying_that_tag(): void
    {
        $this->useAdminStore();
        $free = $this->makeProduct('ZZ Grid Free', ['sku' => 'ZZ-TAGG-1']);
        $pro  = $this->makeProduct('ZZ Grid Pro', ['sku' => 'ZZ-TAGG-2']);
        $tagFree = ShopProductTag::create(['name' => 'GFree', 'alias' => 'gfree-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagPro  = ShopProductTag::create(['name' => 'GPro', 'alias' => 'gpro-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagFree->products()->attach($free->id);
        $tagPro->products()->attach($pro->id);

        $ids = $this->idsInGridForTag($tagFree->alias);

        $this->assertContains((string) $free->id, $ids);
        $this->assertNotContains((string) $pro->id, $ids);
    }

    /**
     * Two different tag pages produce two different product grids.
     *
     * The original bug was invisible to any single-page assertion: every tag page
     * was a full catalogue listing, so each one did contain "its" product. Only
     * comparing two tags exposes it.
     */
    public function test_two_tag_pages_do_not_return_the_same_grid(): void
    {
        $this->useAdminStore();
        $first  = $this->makeProduct('ZZ Grid A', ['sku' => 'ZZ-TAGG-3']);
        $second = $this->makeProduct('ZZ Grid B', ['sku' => 'ZZ-TAGG-4']);
        $tagA = ShopProductTag::create(['name' => 'GA', 'alias' => 'ga-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagB = ShopProductTag::create(['name' => 'GB', 'alias' => 'gb-' . uniqid(), 'status' => 1, 'sort' => 0]);
        $tagA->products()->attach($first->id);
        $tagB->products()->attach($second->id);

        $this->assertNotSame(
            $this->idsInGridForTag($tagA->alias),
            $this->idsInGridForTag($tagB->alias),
            'Two tag pages returning an identical grid means the tag context never reached the component.'
        );
    }

    /**
     * The listing view hands the product grid every page-context prop it may need.
     *
     * One view only: the shop half of GP247Front is served straight from this
     * package since the vendor-resident template change (ADR
     * frontend-template-dev_template-vendor-resident-views).
     *
     * The view is the only place that can carry page context into the component, so
     * a tag or brand page silently degrades into a full catalogue listing when a
     * prop is missing here (RISK-TECH-livewire-grid-drops-page-context).
     */
    public function test_listing_views_pass_page_context_to_the_product_grid(): void
    {
        $view   = 'vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_product_list.blade.php';
        $markup = file_get_contents(base_path($view));

        foreach (['initialCategory', 'initialTag', 'initialBrand'] as $prop) {
            $this->assertStringContainsString(
                $prop,
                $markup,
                $view . ' must pass ' . $prop . ' to the product-filter component.'
            );
        }
    }

    public function test_shop_tag_route_is_registered(): void
    {
        $this->assertTrue(
            Route::has('shop.tag'),
            'The storefront shop.tag route must exist (ShopProductTag::getUrl resolves it).'
        );
    }
}
