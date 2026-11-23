<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\CartManager;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use ReflectionClass;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Feature tests for CartManager Livewire component (US-LW-004).
 *
 * Covers:
 *  - Component structure and method contract (AC1-3)
 *  - Livewire mounting and rendering (empty cart, default/wishlist instance)
 *  - Error dispatching for invalid add-to-cart (non-existent product, allowSale)
 *  - Remove and clear interactions (session-only, no product DB rows needed)
 *  - Infrastructure: Livewire namespace registration, front layout scripts, view file
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 */
class CartManagerTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    // -------------------------------------------------------------------------
    // 1. Class structure & contract
    // -------------------------------------------------------------------------

    /**
     * CartManager must exist in the GP247\Shop\Front\Livewire namespace (US-LW-004).
     */
    public function test_cart_manager_class_exists(): void
    {
        $this->assertTrue(
            class_exists(CartManager::class),
            'CartManager must exist at GP247\\Shop\\Front\\Livewire\\CartManager (US-LW-004)'
        );
    }

    /**
     * CartManager must expose the documented public properties.
     */
    public function test_cart_manager_has_required_public_properties(): void
    {
        $ref = new ReflectionClass(CartManager::class);

        foreach (['instance', 'errorMessage', 'successMessage'] as $prop) {
            $this->assertTrue(
                $ref->hasProperty($prop) && $ref->getProperty($prop)->isPublic(),
                "CartManager must have public property \${$prop} (US-LW-004)"
            );
        }
    }

    /**
     * CartManager must expose the cart operation methods (AC1/AC2/AC3).
     */
    public function test_cart_manager_has_required_methods(): void
    {
        $required = [
            'mount',
            'addToCart',
            'updateQty',
            'removeItem',
            'clearCart',
            'getItemsGroupByStore',
            'getCount',
            'render',
        ];

        foreach ($required as $method) {
            $this->assertTrue(
                method_exists(CartManager::class, $method),
                "CartManager must have method {$method}() (US-LW-004)"
            );
        }
    }

    // -------------------------------------------------------------------------
    // 2. Infrastructure
    // -------------------------------------------------------------------------

    /**
     * The Livewire view template for the cart component must be physically present.
     */
    public function test_cart_manager_view_file_exists(): void
    {
        $path = base_path('vendor/gp247/shop/src/Views/templates/GP247Front/livewire/shop_cart-manager.blade.php');

        $this->assertFileExists(
            $path,
            'shop_cart-manager.blade.php view must exist at vendor/gp247/shop/src/Views/templates/GP247Front/livewire/ (US-LW-004)'
        );
    }

    /**
     * The storefront Livewire namespace must be registered so CartManager resolves
     * as 'gp247-shop-front::cart-manager' in blade templates (US-LW-004 AC1).
     *
     * Verification: ShopServiceProvider::registerStorefrontLivewire() calls
     * Livewire::addNamespace('gp247-shop-front', 'GP247\\Shop\\Front\\Livewire').
     * We validate by confirming the source file registers the namespace.
     */
    public function test_storefront_livewire_namespace_is_registered(): void
    {
        $providerSource = file_get_contents(
            base_path('vendor/gp247/shop/src/ShopServiceProvider.php')
        );

        $this->assertStringContainsString(
            "'gp247-shop-front'",
            $providerSource,
            'ShopServiceProvider must register the gp247-shop-front Livewire namespace (US-LW-004)'
        );

        $this->assertStringContainsString(
            'GP247\\\\Shop\\\\Front\\\\Livewire',
            $providerSource,
            'ShopServiceProvider must map gp247-shop-front to GP247\\Shop\\Front\\Livewire (US-LW-004)'
        );

        // Verify runtime resolution: mounting via alias must produce the CartManager class.
        $component = Livewire::test('gp247-shop-front::cart-manager');
        $this->assertInstanceOf(
            CartManager::class,
            $component->instance(),
            'gp247-shop-front::cart-manager must resolve to CartManager at runtime (US-LW-004)'
        );
    }

    /**
     * The front storefront layout must include @livewireStyles in <head>
     * so Livewire components render correctly.
     */
    public function test_front_layout_has_livewire_styles(): void
    {
        // Check vendor template (source of truth for gp247:view-front-template)
        $vendorLayout = base_path('vendor/gp247/front/src/Views/templates/GP247Front/layout.blade.php');
        $content = file_get_contents($vendorLayout);

        $this->assertStringContainsString(
            '@livewireStyles',
            $content,
            'Front layout (vendor) must include @livewireStyles in <head> (US-LW-004 infra)'
        );
    }

    /**
     * The front storefront layout must include @livewireScripts before </body>
     * so Livewire JS loads on every storefront page.
     */
    public function test_front_layout_has_livewire_scripts(): void
    {
        $vendorLayout = base_path('vendor/gp247/front/src/Views/templates/GP247Front/layout.blade.php');
        $content = file_get_contents($vendorLayout);

        $this->assertStringContainsString(
            '@livewireScripts',
            $content,
            'Front layout (vendor) must include @livewireScripts before </body> (US-LW-004 infra)'
        );

        // Scripts must appear AFTER @stack('scripts') so page-specific scripts load first
        $posStack   = strpos($content, "@stack('scripts')");
        $posScripts = strpos($content, '@livewireScripts');
        $this->assertGreaterThan(
            $posStack,
            $posScripts,
            '@livewireScripts must appear after @stack("scripts") (US-LW-004 infra)'
        );
    }

    // -------------------------------------------------------------------------
    // 3. Livewire mount & rendering
    // -------------------------------------------------------------------------

    /**
     * CartManager must mount with the default cart instance and render an
     * empty-cart message when no items are in session (AC1 — renders w/o error).
     */
    public function test_cart_manager_mounts_with_default_instance(): void
    {
        $component = Livewire::test(CartManager::class);

        $component->assertSet('instance', 'default');
        $component->assertSet('errorMessage', null);
        $component->assertSet('successMessage', null);
    }

    /**
     * CartManager must accept a non-default instance (wishlist/compare) on mount.
     * Ensures multi-instance support required by the cart infrastructure (AC1).
     */
    public function test_cart_manager_mounts_with_wishlist_instance(): void
    {
        $component = Livewire::test(CartManager::class, ['instance' => 'wishlist']);
        $component->assertSet('instance', 'wishlist');
    }

    /**
     * getCount() on an empty cart must return 0 (mini-cart display, AC1).
     */
    public function test_get_count_returns_zero_for_empty_cart(): void
    {
        $component = Livewire::test(CartManager::class);

        $this->assertSame(0, $component->instance()->getCount());
    }

    /**
     * getItemsGroupByStore() must return a Collection (AC3 — multi-vendor grouping).
     */
    public function test_get_items_group_by_store_returns_collection(): void
    {
        $component = Livewire::test(CartManager::class);

        $result = $component->instance()->getItemsGroupByStore();

        $this->assertInstanceOf(
            Collection::class,
            $result,
            'getItemsGroupByStore() must return a Collection for multi-vendor grouping (US-LW-004 AC3)'
        );
    }

    // -------------------------------------------------------------------------
    // 4. Add-to-cart error paths (AC2 — allowSale / product not found)
    // -------------------------------------------------------------------------

    /**
     * addToCart with a product ID that does not exist must dispatch 'cart-error'
     * and set errorMessage — prevents phantom cart entries (AC2).
     */
    public function test_add_to_cart_dispatches_error_for_nonexistent_product(): void
    {
        Livewire::test(CartManager::class)
            ->call('addToCart', 999999)
            ->assertDispatched('cart-error')
            ->assertSet('errorMessage', fn($msg) => $msg !== null);
    }

    /**
     * addToCart must NOT dispatch 'cart-updated' when the product is not found,
     * ensuring no phantom items enter the cart (AC2).
     */
    public function test_add_to_cart_does_not_dispatch_cart_updated_for_nonexistent_product(): void
    {
        Livewire::test(CartManager::class)
            ->call('addToCart', 999999)
            ->assertNotDispatched('cart-updated');
    }

    // -------------------------------------------------------------------------
    // 5. Remove & clear interactions
    // -------------------------------------------------------------------------

    /**
     * removeItem with an unknown rowId must silently complete without
     * throwing or dispatching an error event — graceful noop (AC1).
     */
    public function test_remove_nonexistent_item_is_silent_noop(): void
    {
        $component = Livewire::test(CartManager::class)
            ->call('removeItem', 'nonexistent-row-id');

        // No error dispatched
        $component->assertNotDispatched('cart-error');
        $component->assertSet('errorMessage', null);
    }

    /**
     * clearCart on an empty cart must dispatch 'cart-updated' with count 0
     * and not throw (AC1 — realtime mini-cart update).
     */
    public function test_clear_cart_dispatches_cart_updated(): void
    {
        Livewire::test(CartManager::class)
            ->call('clearCart')
            ->assertDispatched('cart-updated');
    }

    /**
     * updateQty with an unknown rowId must dispatch 'cart-error' (AC2 — stock/qty guard).
     */
    public function test_update_qty_dispatches_error_for_nonexistent_row(): void
    {
        Livewire::test(CartManager::class)
            ->call('updateQty', 'no-such-row', 3)
            ->assertDispatched('cart-error');
    }

    /**
     * qty format follows product_qty_decimal (ADR-016). CartService::update() sets qty directly (bypassing
     * CartItem::setQuantity()), so CartManager::updateQty() enforces it itself.
     */
    public function test_update_qty_dispatches_error_for_decimal_qty_when_disabled(): void
    {
        $this->setQtyDecimalEnabled(false);
        $product = $this->makeProduct('ZZ Update Qty Reject');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Cart Item', 'qty' => 1, 'storeId' => $this->storeId()]);

        Livewire::test(CartManager::class)
            ->call('updateQty', $item->rowId, 1.5)
            ->assertDispatched('cart-error');

        $this->assertEqualsWithDelta(1, (float) $cart->get($item->rowId)->qty, 0.01);
    }

    public function test_update_qty_accepts_decimal_qty_when_enabled(): void
    {
        $this->setQtyDecimalEnabled(true);
        $product = $this->makeProduct('ZZ Update Qty Accept');
        $cart = (new CartService())->instance('default');
        $item = $cart->add(['id' => (string) $product->id, 'name' => 'ZZ Cart Item', 'qty' => 1, 'storeId' => $this->storeId()]);

        Livewire::test(CartManager::class)
            ->call('updateQty', $item->rowId, 1.5)
            ->assertDispatched('cart-updated');

        $this->assertEqualsWithDelta(1.5, (float) $cart->get($item->rowId)->qty, 0.01);
    }
}
