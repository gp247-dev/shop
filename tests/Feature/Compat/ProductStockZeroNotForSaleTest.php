<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * A product with no stock left cannot be sold while the shop manages stock and does not
 * sell out of stock — even though the decimal stock column reads back as the string
 * "0.00": no buy button, no add to cart on the website or through the API, and the
 * product page tells search engines it is out of stock. Selling out of stock (or not
 * managing stock) keeps such a product on sale. (The REST API cart case lives in
 * GP247\Shop\Tests\Feature\ApiCommerce\ApiCartTest.)
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-stock-zero-not-for-sale
 */
class ProductStockZeroNotForSaleTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->setShopConfig(['product_price' => '1', 'product_stock' => '1', 'product_buy_out_of_stock' => '0', 'product_preorder' => '1']);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Shop settings the sale rule reads (every store row of each key, rolled back).
     *
     * @param array<string, string> $values
     * @return void
     */
    private function setShopConfig(array $values): void
    {
        $table = \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        foreach ($values as $key => $value) {
            $table->clone()->where('key', $key)->update(['value' => $value]);
        }
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(\GP247\Core\Models\AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }

    /**
     * A product read back from the DB (stock comes back as a decimal string).
     *
     * @param int|float $stock
     * @return ShopProduct
     */
    private function productWithStock($stock): ShopProduct
    {
        return ShopProduct::find($this->makeProduct('ZZ Stock ' . $stock . ' ' . uniqid(), ['stock' => $stock])->id);
    }

    public function test_a_product_with_no_stock_left_is_not_for_sale_when_the_shop_does_not_sell_out_of_stock(): void
    {
        foreach ([0, -2] as $stock) {
            $product = $this->productWithStock($stock);
            $this->assertFalse($product->allowSale(), 'stock ' . var_export($product->stock, true) . ' must not be for sale');
        }
        $this->assertTrue($this->productWithStock(3)->allowSale(), 'a product in stock stays on sale');
    }

    public function test_the_website_refuses_to_put_an_out_of_stock_product_in_the_cart(): void
    {
        $product = $this->productWithStock(0);

        $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 1])
            ->assertSessionHas('error', gp247_language_render('product.dont_allow_sale', ['sku' => $product->sku]));

        $this->assertSame(0, (new CartService())->instance('default')->count());
    }

    public function test_the_product_page_hides_the_buy_button_and_says_out_of_stock(): void
    {
        $html = $this->get($this->productWithStock(0)->getUrl())->assertOk()->getContent();

        $this->assertStringNotContainsString('id="gp247-button-process"', $html);
        $this->assertStringContainsString('OutOfStock', $html);
    }

    public function test_a_shop_that_sells_out_of_stock_keeps_such_a_product_on_sale(): void
    {
        $this->setShopConfig(['product_buy_out_of_stock' => '1']);

        $this->assertTrue($this->productWithStock(0)->allowSale());
    }

    public function test_a_shop_that_does_not_manage_stock_keeps_such_a_product_on_sale(): void
    {
        $this->setShopConfig(['product_stock' => '0']);

        $this->assertTrue($this->productWithStock(0)->allowSale());
    }

    public function test_no_shop_code_checks_stock_by_truthiness(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('vendor/gp247/shop/src'), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            // WHY: the stock column is decimal — "0.00" is truthy in PHP, so stock must be compared as a number.
            // A "(" right after a name is a call argument (gp247_qty_format($p->stock)), not a condition.
            if (preg_match_all('/(?:\|\||&&|(?<![\w\]])\(|!)\s*\$\w+(?:->\w+)*->stock\b\s*(?:\)|&&|\|\||\?(?!\?)|\r?\n)/', file_get_contents($file->getPathname()), $m)) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ': ' . trim($m[0][0]);
            }
        }

        $this->assertSame([], $offenders);
    }
}
