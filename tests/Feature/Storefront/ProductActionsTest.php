<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Front\Livewire\ProductActions;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Wishlist/compare buttons on the product detail page.
 *
 * The detail screen is a plain Blade form posting to cart.add, so it had no way
 * to add the product to the wishlist or the compare list — only the listing cards
 * (ProductCard) offered that. ProductActions brings the same two buttons, through
 * the same AddsProductToCart rules, to the detail page.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-004
 * @aidlc-adr ADR-011, ADR-015
 */
class ProductActionsTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.storeId' => GP247_STORE_ID_ROOT]);
    }

    public function test_product_detail_page_shows_wishlist_and_compare_buttons(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions');

        $html = $this->get($product->getUrl())->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="storefront-product-detail-wishlist"', $html);
        $this->assertStringContainsString('data-testid="storefront-product-detail-compare"', $html);
    }

    public function test_buttons_follow_the_wishlist_and_compare_settings(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Settings');

        AdminConfig::where('store_id', GP247_STORE_ID_GLOBAL)
            ->where('key', 'product_use_button_wishlist')
            ->update(['value' => '0']);
        $this->resetConfigCaches();

        Livewire::test(ProductActions::class, ['productId' => $product->id])
            ->assertDontSee('storefront-product-detail-wishlist')
            ->assertSee('storefront-product-detail-compare');
    }

    protected function tearDown(): void
    {
        // WHY: AdminConfig memoizes statically; the DB update rolls back but the memo would not.
        $this->resetConfigCaches();
        parent::tearDown();
    }

    /**
     * Drop AdminConfig's static memo so the next gp247_config() reads the database.
     *
     * @return void
     */
    private function resetConfigCaches(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setAccessible(true);
            $prop->setValue(null, $empty);
        }
    }

    public function test_wishlist_button_adds_the_product_to_the_wishlist(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Wishlist');

        Livewire::test(ProductActions::class, ['productId' => $product->id])
            ->call('addToCart', 'wishlist')
            ->assertDispatched('cart-updated', fn ($name, $params) => $params['instance'] === 'wishlist');
    }

    public function test_compare_button_adds_the_product_to_the_compare_list(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Compare');

        Livewire::test(ProductActions::class, ['productId' => $product->id])
            ->call('addToCart', 'compare')
            ->assertDispatched('cart-updated', fn ($name, $params) => $params['instance'] === 'compare');
    }

    public function test_buttons_show_active_state_for_products_already_in_the_lists(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Active State');
        (new CartService())->instance('compare')->add(['id' => $product->id, 'name' => 'ZZ', 'qty' => 1, 'storeId' => GP247_STORE_ID_ROOT]);

        $html = Livewire::test(ProductActions::class, ['productId' => $product->id])->html();

        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*\bis-active\b[^"]*"[^>]*aria-pressed="true"[^>]*data-testid="storefront-product-detail-compare"/', $html);
        $this->assertMatchesRegularExpression('/<button(?![^>]*\bis-active\b)[^>]*aria-pressed="false"[^>]*data-testid="storefront-product-detail-wishlist"/', $html);
    }

    public function test_wishlist_button_turns_active_right_after_adding(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Active After Add');

        $html = Livewire::test(ProductActions::class, ['productId' => $product->id])
            ->call('addToCart', 'wishlist')
            ->html();

        $this->assertMatchesRegularExpression('/<button[^>]*class="[^"]*\bis-active\b[^"]*"[^>]*aria-pressed="true"[^>]*data-testid="storefront-product-detail-wishlist"/', $html);
    }

    public function test_detail_buttons_toggle_and_a_second_click_removes_the_product(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Toggle');

        $component = Livewire::test(ProductActions::class, ['productId' => $product->id]);
        $component->call('toggleList', 'wishlist')
            ->assertDispatched('cart-updated', fn ($name, $params) => $params['count'] === 1);
        $html = $component->call('toggleList', 'wishlist')
            ->assertDispatched('cart-updated', fn ($name, $params) => $params['count'] === 0)
            ->html();

        $this->assertMatchesRegularExpression('/<button type="button" wire:click="toggleList\(\'wishlist\'\)"(?![^>]*\bis-active\b)[^>]*aria-pressed="false"[^>]*data-testid="storefront-product-detail-wishlist"/', $html);
    }

    /**
     * Adding to the shopping cart stays on the page's own form, which carries the
     * quantity and the chosen attributes; this component must not become a second,
     * attribute-less path into the cart.
     */
    public function test_the_component_refuses_the_shopping_cart_instance(): void
    {
        $product = $this->makeProduct('ZZ Detail Actions Default');

        Livewire::test(ProductActions::class, ['productId' => $product->id])
            ->call('addToCart', 'default')
            ->assertNotDispatched('cart-updated')
            ->assertDispatched('cart-error');
    }
}
