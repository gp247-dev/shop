<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresStockPolicy;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * Unit-of-behaviour for ShopProduct::hasStockForOrder (US-SADM-order-stock-parity): the single shared guard that decides whether a
 * given quantity may be ordered, honouring product_buy_out_of_stock + product_stock
 * exactly like the storefront ShopOrder::createOrder check. Callers decide how to
 * react (storefront throws; admin warns) — this only encodes the predicate.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-order-stock-parity
 * @aidlc-adr shop-admin_order-stock-parity
 */
class ProductStockForOrderTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopOrders;
    use ConfiguresStockPolicy;

    public function test_allows_when_stock_is_sufficient(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 10]);

        $this->assertTrue($product->hasStockForOrder(3));
    }

    public function test_allows_exactly_at_stock_boundary(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 10]);

        $this->assertTrue($product->hasStockForOrder(10));
    }

    public function test_denies_when_quantity_exceeds_stock(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 10]);

        $this->assertFalse($product->hasStockForOrder(11));
    }

    public function test_denies_just_above_boundary_with_decimal(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 10]);

        $this->assertFalse($product->hasStockForOrder(10.01));
    }

    public function test_allows_over_stock_when_buy_out_of_stock_enabled(): void
    {
        $this->setProductStock(true);
        $this->setBuyOutOfStock(true);
        $product = $this->makeProduct(['stock' => 1]);

        $this->assertTrue($product->hasStockForOrder(999));
    }

    public function test_allows_when_stock_not_managed(): void
    {
        $this->setProductStock(false);
        $this->setBuyOutOfStock(false);
        $product = $this->makeProduct(['stock' => 0]);

        $this->assertTrue($product->hasStockForOrder(5));
    }
}
