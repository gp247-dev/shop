<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductPromotion;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * A promotion the shop has switched off does not lower the selling price, even while it
 * is still inside its date range. The order line takes
 * getFinalPrice(), so this is the price the customer is charged.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-order-line-effective-price
 */
class PromotionStatusPriceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    /**
     * Reset AdminConfig's per-store memo and activate the store.
     */
    protected function setUp(): void
    {
        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
    }

    /**
     * Restore the store's active flag.
     */
    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * A product listed at 20 with a promotion at 15 running from yesterday to next week.
     *
     * @param int $status status_promotion: 1 = on, 0 = off.
     * @return ShopProduct Freshly loaded, as a storefront request would.
     */
    private function productOnPromotion(int $status): ShopProduct
    {
        $product = $this->makeProduct('ZZ Promotion Status Product', ['price' => 20]);
        ShopProductPromotion::insert([
            'product_id'       => $product->id,
            'price_promotion'  => 15,
            'date_start'       => now()->subDay()->format('Y-m-d H:i:s'),
            'date_end'         => now()->addWeek()->format('Y-m-d H:i:s'),
            'status_promotion' => $status,
        ]);

        return ShopProduct::find($product->id);
    }

    public function test_a_disabled_promotion_does_not_lower_the_selling_price(): void
    {
        $product = $this->productOnPromotion(0);

        $this->assertEquals(20.0, (float) $product->getFinalPrice());
    }

    public function test_a_running_enabled_promotion_still_sets_the_selling_price(): void
    {
        $product = $this->productOnPromotion(1);

        $this->assertEquals(15.0, (float) $product->getFinalPrice());
    }

    public function test_reading_the_price_does_not_switch_a_disabled_promotion_on(): void
    {
        $product = $this->productOnPromotion(0);

        $product->getFinalPrice();

        $this->assertSame(0, (int) $product->promotionPrice->status_promotion);
    }

    public function test_the_product_page_shows_and_publishes_the_list_price_when_the_promotion_is_disabled(): void
    {
        $product = $this->productOnPromotion(0);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $response->assertSee('"price":"20"', escape: false);
        $response->assertDontSee('"price":"15"', escape: false);
        $response->assertDontSee('priceValidUntil', escape: false);
    }
}
