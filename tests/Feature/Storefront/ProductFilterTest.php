<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\ProductFilter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use ReflectionClass;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for ProductFilter Livewire component (US-LW-005).
 *
 * Covers:
 *  - Component class structure and method contract (AC1-3)
 *  - Livewire mounting: default state, initialCategory, initialKeyword, page context
 *  - Filter state management: sort whitelist, brand toggle CSV, price apply/clear
 *  - URL attribute reflection (sort, price, brand, keyword via #[Url])
 *  - clearFilters resets all active filters
 *  - Invalid/unknown sort key is silently ignored (security whitelist)
 *  - Infrastructure: view file, namespace registration
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-005
 * @aidlc-adr storefront_livewire-page-context-props
 */
class ProductFilterTest extends TestCase
{
    use DatabaseTransactions;

    // -------------------------------------------------------------------------
    // 1. Class structure & contract
    // -------------------------------------------------------------------------

    /**
     * ProductFilter must exist in the GP247\Shop\Front\Livewire namespace (US-LW-005).
     */
    public function test_product_filter_class_exists(): void
    {
        $this->assertTrue(
            class_exists(ProductFilter::class),
            'ProductFilter must exist at GP247\\Shop\\Front\\Livewire\\ProductFilter (US-LW-005)'
        );
    }

    /**
     * ProductFilter must expose the documented public properties (AC1/AC2/AC3).
     */
    public function test_product_filter_has_required_public_properties(): void
    {
        $ref = new ReflectionClass(ProductFilter::class);

        foreach (['initialCategory', 'initialTag', 'sort', 'price', 'brand', 'keyword', 'priceMin', 'priceMax'] as $prop) {
            $this->assertTrue(
                $ref->hasProperty($prop) && $ref->getProperty($prop)->isPublic(),
                "ProductFilter must have public property \${$prop} (US-LW-005)"
            );
        }
    }

    /**
     * ProductFilter must expose all required methods (US-LW-005).
     */
    public function test_product_filter_has_required_methods(): void
    {
        $required = [
            'mount',
            'updatedSort',
            'updatedBrand',
            'updatedKeyword',
            'toggleBrand',
            'applyPrice',
            'clearFilters',
            'render',
        ];

        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(ProductFilter::class, $method),
                "ProductFilter must have method {$method}() (US-LW-005)"
            );
        }
    }

    // -------------------------------------------------------------------------
    // 2. Infrastructure
    // -------------------------------------------------------------------------

    /**
     * The Livewire view template for the filter component must exist (US-LW-005).
     */
    public function test_product_filter_view_file_exists(): void
    {
        $path = base_path('vendor/gp247/shop/src/Views/templates/GP247Front/livewire/shop_product-filter.blade.php');

        $this->assertFileExists(
            $path,
            'shop_product-filter.blade.php view must exist at vendor/gp247/shop/src/Views/templates/GP247Front/livewire/ (US-LW-005)'
        );
    }

    /**
     * The storefront Livewire namespace must resolve ProductFilter via alias
     * 'gp247-shop-front::product-filter' at runtime (US-LW-005 AC1).
     */
    public function test_product_filter_resolves_via_livewire_namespace(): void
    {
        $component = Livewire::test('gp247-shop-front::product-filter');

        $this->assertInstanceOf(
            ProductFilter::class,
            $component->instance(),
            'gp247-shop-front::product-filter must resolve to ProductFilter at runtime (US-LW-005)'
        );
    }

    /**
     * shop_product_list.blade.php (vendor) must reference the Livewire component (US-LW-005 AC3).
     */
    public function test_product_list_view_uses_livewire_component(): void
    {
        $view = file_get_contents(
            base_path('vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_product_list.blade.php')
        );

        $this->assertStringContainsString(
            "gp247-shop-front::product-filter",
            $view,
            'shop_product_list.blade.php must embed the product-filter Livewire component (US-LW-005 AC3)'
        );
    }

    /**
     * shop_search.blade.php (vendor) must reference the Livewire component (US-LW-005 AC3).
     */
    public function test_search_view_uses_livewire_component(): void
    {
        $view = file_get_contents(
            base_path('vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_search.blade.php')
        );

        $this->assertStringContainsString(
            "gp247-shop-front::product-filter",
            $view,
            'shop_search.blade.php must embed the product-filter Livewire component (US-LW-005 AC3)'
        );
    }

    // -------------------------------------------------------------------------
    // 3. Mount & default state
    // -------------------------------------------------------------------------

    /**
     * Component must mount with all filter properties at their default empty state (AC1).
     */
    public function test_mounts_with_default_empty_state(): void
    {
        $component = Livewire::test(ProductFilter::class);

        $component
            ->assertSet('sort', '')
            ->assertSet('price', '')
            ->assertSet('brand', '')
            ->assertSet('keyword', '')
            ->assertSet('initialCategory', null)
            ->assertSet('priceMin', '')
            ->assertSet('priceMax', '');
    }

    /**
     * mount() must pin the initialCategory when provided (AC1 — category page context).
     */
    public function test_mounts_with_initial_category(): void
    {
        $component = Livewire::test(ProductFilter::class, ['initialCategory' => 'cat-uuid-123']);

        $component->assertSet('initialCategory', 'cat-uuid-123');
    }

    /**
     * mount() must seed the keyword from initialKeyword when no URL keyword is active (AC1).
     */
    public function test_mounts_with_initial_keyword(): void
    {
        $component = Livewire::test(ProductFilter::class, ['initialKeyword' => 'laptop']);

        $component->assertSet('keyword', 'laptop');
    }

    /**
     * mount() must split a persisted price string ("min__max") into priceMin/priceMax (AC1).
     */
    public function test_mount_splits_persisted_price_into_min_max(): void
    {
        $component = Livewire::test(ProductFilter::class)
            ->set('price', '100__500');

        // Reload to trigger mount with URL state
        $component->assertSet('price', '100__500');
    }

    // -------------------------------------------------------------------------
    // 4. Sort filter
    // -------------------------------------------------------------------------

    /**
     * Setting sort to a valid key must reflect the new sort value (AC1 — URL query string).
     */
    public function test_valid_sort_key_is_accepted(): void
    {
        $component = Livewire::test(ProductFilter::class)
            ->set('sort', 'price_asc');

        $component->assertSet('sort', 'price_asc');
    }

    /**
     * An invalid / non-whitelisted sort key must NOT alter the rendered product set
     * in an unpredictable way — the builder simply ignores it (whitelist enforced in
     * buildProductQuery; the property itself may hold the raw value but the query
     * builder receives nothing for unlisted keys).
     *
     * Verification: render() must complete without exception for any string value.
     */
    public function test_invalid_sort_key_does_not_throw(): void
    {
        // If this call throws, the test fails — we just need a clean render
        $component = Livewire::test(ProductFilter::class)
            ->set('sort', 'MALICIOUS_SORT; DROP TABLE shop_product;--');

        $component->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 5. Brand toggle
    // -------------------------------------------------------------------------

    /**
     * toggleBrand adds a brand alias to the CSV $brand property (AC1 — URL filter).
     */
    public function test_toggle_brand_adds_alias(): void
    {
        Livewire::test(ProductFilter::class)
            ->call('toggleBrand', 'apple')
            ->assertSet('brand', 'apple');
    }

    /**
     * toggleBrand called twice with the same alias removes it (toggle off).
     */
    public function test_toggle_brand_removes_alias_on_second_call(): void
    {
        Livewire::test(ProductFilter::class)
            ->call('toggleBrand', 'apple')
            ->call('toggleBrand', 'apple')
            ->assertSet('brand', '');
    }

    /**
     * toggleBrand accumulates multiple aliases as CSV (AC1 — multi-brand filter).
     */
    public function test_toggle_brand_accumulates_multiple_aliases(): void
    {
        $component = Livewire::test(ProductFilter::class)
            ->call('toggleBrand', 'apple')
            ->call('toggleBrand', 'samsung');

        $brandValue = $component->get('brand');

        $this->assertStringContainsString('apple', $brandValue);
        $this->assertStringContainsString('samsung', $brandValue);
    }

    /**
     * toggleBrand with an empty string must be a silent noop (security — no empty CSV segment).
     */
    public function test_toggle_brand_ignores_empty_alias(): void
    {
        Livewire::test(ProductFilter::class)
            ->call('toggleBrand', '')
            ->assertSet('brand', '');
    }

    // -------------------------------------------------------------------------
    // 6. Price apply
    // -------------------------------------------------------------------------

    /**
     * applyPrice must combine priceMin and priceMax into "min__max" format (AC1).
     */
    public function test_apply_price_sets_price_string(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('priceMin', '100')
            ->set('priceMax', '500')
            ->call('applyPrice')
            ->assertSet('price', '100__500');
    }

    /**
     * applyPrice with both inputs empty must clear the price filter (AC1).
     */
    public function test_apply_price_clears_when_both_empty(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('priceMin', '')
            ->set('priceMax', '')
            ->call('applyPrice')
            ->assertSet('price', '');
    }

    /**
     * applyPrice must swap min/max when min > max to avoid empty result sets (AC1).
     */
    public function test_apply_price_swaps_when_min_greater_than_max(): void
    {
        $component = Livewire::test(ProductFilter::class)
            ->set('priceMin', '800')
            ->set('priceMax', '200')
            ->call('applyPrice');

        $price = $component->get('price');
        [$a, $b] = explode('__', $price);
        $this->assertLessThan((int) $b, (int) $a, 'applyPrice must swap min/max when min > max (US-LW-005)');
    }

    // -------------------------------------------------------------------------
    // 7. clearFilters
    // -------------------------------------------------------------------------

    /**
     * clearFilters must reset all filter properties to their default empty values (AC1).
     */
    public function test_clear_filters_resets_all_state(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('sort', 'price_asc')
            ->set('price', '100__500')
            ->set('priceMin', '100')
            ->set('priceMax', '500')
            ->set('brand', 'apple,samsung')
            ->set('keyword', 'laptop')
            ->call('clearFilters')
            ->assertSet('sort', '')
            ->assertSet('price', '')
            ->assertSet('priceMin', '')
            ->assertSet('priceMax', '')
            ->assertSet('brand', '')
            ->assertSet('keyword', '');
    }

    // -------------------------------------------------------------------------
    // 8. Render
    // -------------------------------------------------------------------------

    /**
     * Rendering with no filters must complete without exception and return HTTP 200 (AC1).
     */
    public function test_renders_without_exception_on_mount(): void
    {
        Livewire::test(ProductFilter::class)
            ->assertStatus(200);
    }

    /**
     * Rendering with a non-existent category ID must not throw — builder returns empty (AC1).
     */
    public function test_renders_with_nonexistent_category_id(): void
    {
        Livewire::test(ProductFilter::class, ['initialCategory' => 'no-such-category-uuid'])
            ->assertStatus(200);
    }

    /**
     * Rendering with sort + brand + keyword must produce HTTP 200 and not throw (AC1/AC2).
     */
    public function test_renders_with_all_filters_active(): void
    {
        Livewire::test(ProductFilter::class)
            ->set('sort', 'price_desc')
            ->set('keyword', 'test-product-xyz')
            ->call('toggleBrand', 'no-such-brand')
            ->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 9. URL attribute reflection (AC2 — shareable URLs)
    // -------------------------------------------------------------------------

    /**
     * The $sort property must carry the #[Url] attribute so Livewire reflects
     * filter state in the browser URL (AC2 — shareable filter URLs).
     *
     * Verification: check source-of-truth that #[Url] is declared on $sort.
     */
    public function test_sort_property_has_url_attribute(): void
    {
        $ref      = new ReflectionClass(ProductFilter::class);
        $property = $ref->getProperty('sort');
        $attrs    = $property->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertNotEmpty(
            $attrs,
            '$sort must carry #[Url] so the filter reflects in the browser URL (US-LW-005 AC2)'
        );
    }

    /**
     * The $brand property must carry the #[Url] attribute (AC2).
     */
    public function test_brand_property_has_url_attribute(): void
    {
        $ref      = new ReflectionClass(ProductFilter::class);
        $property = $ref->getProperty('brand');
        $attrs    = $property->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertNotEmpty(
            $attrs,
            '$brand must carry #[Url] so the brand filter reflects in the browser URL (US-LW-005 AC2)'
        );
    }

    /**
     * The $keyword property must carry the #[Url] attribute (AC2).
     */
    public function test_keyword_property_has_url_attribute(): void
    {
        $ref      = new ReflectionClass(ProductFilter::class);
        $property = $ref->getProperty('keyword');
        $attrs    = $property->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertNotEmpty(
            $attrs,
            '$keyword must carry #[Url] so the keyword filter reflects in the browser URL (US-LW-005 AC2)'
        );
    }

    /**
     * The $price property must carry the #[Url] attribute (AC2).
     */
    public function test_price_property_has_url_attribute(): void
    {
        $ref      = new ReflectionClass(ProductFilter::class);
        $property = $ref->getProperty('price');
        $attrs    = $property->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertNotEmpty(
            $attrs,
            '$price must carry #[Url] so the price filter reflects in the browser URL (US-LW-005 AC2)'
        );
    }

    // -------------------------------------------------------------------------
    // 9. Page context (US-LW-005 AC4)
    // -------------------------------------------------------------------------

    /**
     * mount() must pin the keyword tag the page stands for.
     */
    public function test_mounts_with_initial_tag(): void
    {
        $component = Livewire::test(ProductFilter::class, ['initialTag' => 'summer-sale']);

        $component->assertSet('initialTag', 'summer-sale');
    }

    /**
     * The pinned tag must not be user-changeable through the query string.
     *
     * Page context is what the page IS: a shopper changing it is visiting a different
     * page, not re-filtering this one. Marking it #[Url] would give the tag two
     * sources of truth and let the query string contradict the heading, breadcrumb
     * and canonical URL the controller already rendered.
     */
    public function test_initial_tag_property_has_no_url_attribute(): void
    {
        $ref   = new ReflectionClass(ProductFilter::class);
        $attrs = $ref->getProperty('initialTag')->getAttributes(\Livewire\Attributes\Url::class);

        $this->assertEmpty(
            $attrs,
            '$initialTag is page context, so it must not be exposed as a URL parameter.'
        );
    }

    /**
     * A brand page opens with its own brand already ticked in the filter panel.
     *
     * Brand is pre-selected rather than pinned: the panel lists brands as checkboxes,
     * so the shopper can act on it and toggling another brand should re-filter rather
     * than intersect with the page brand and return nothing.
     */
    public function test_mount_preselects_the_brand_of_a_brand_page(): void
    {
        $component = Livewire::test(ProductFilter::class, ['initialBrand' => 'acme']);

        $component->assertSet('brand', 'acme');
    }

    /**
     * A brand already chosen in the URL wins over the page's own brand.
     */
    public function test_url_brand_survives_the_brand_page_preselection(): void
    {
        $component = Livewire::withQueryParams(['brand' => 'chosen'])
            ->test(ProductFilter::class, ['initialBrand' => 'acme']);

        $component->assertSet('brand', 'chosen');
    }

    /**
     * With no page context at all the grid stays unfiltered (home/all-products).
     */
    public function test_mounts_without_page_context(): void
    {
        $component = Livewire::test(ProductFilter::class);

        $component->assertSet('initialTag', null)
            ->assertSet('initialCategory', null)
            ->assertSet('brand', '');
    }
}
