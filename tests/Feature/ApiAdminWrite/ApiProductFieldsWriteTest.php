<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP keeps the price list on its side and writes selling price, cost and status of
 * a product through the admin API — only those fields, checked like the product form,
 * in the account's store, with the product screen's change permission.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-product-fields
 * @aidlc-adr api-admin-write_write-authorization-as-admin-action
 */
#[Group('api-admin-write')]
class ApiProductFieldsWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;

    public function test_an_erp_sets_the_selling_price_the_cost_and_the_status_of_a_product(): void
    {
        $product = $this->makeProduct(['price' => 100, 'cost' => 40, 'status' => 1]);

        $this->writeFields($this->writer(), $product, ['price' => 120.5, 'cost' => 45, 'status' => 0])
            ->assertOk()->assertJsonPath('data.id', $product->id);

        $fresh = ShopProduct::find($product->id);
        $this->assertEquals(120.5, (float) $fresh->price);
        $this->assertEquals(45, (float) $fresh->cost);
        $this->assertSame(0, (int) $fresh->status);
    }

    public function test_another_field_or_an_empty_body_is_refused(): void
    {
        $product = $this->makeProduct();

        $this->writeFields($this->writer(), $product, ['price' => 10, 'sku' => 'HACKED'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->writeFields($this->writer(), $product, ['stock' => 5])->assertStatus(422);
        $this->writeFields($this->writer(), $product, [])->assertStatus(422);
        $this->assertNotSame('HACKED', ShopProduct::find($product->id)->sku);
    }

    public function test_a_negative_price_or_a_status_other_than_on_or_off_is_refused(): void
    {
        $product = $this->makeProduct(['price' => 100]);

        $this->writeFields($this->writer(), $product, ['price' => -1])->assertStatus(422);
        $this->writeFields($this->writer(), $product, ['status' => 7])->assertStatus(422);
        $this->writeFields($this->writer(), $product, ['price' => 1e15])->assertStatus(422);
        $this->assertEquals(100, (float) ShopProduct::find($product->id)->price);
    }

    public function test_a_field_turned_off_in_the_product_settings_is_refused(): void
    {
        $product = $this->makeProduct(['cost' => 40]);
        $this->setStoreConfig(['product_cost' => '0']);

        $this->writeFields($this->writer(), $product, ['cost' => 50])->assertStatus(422);
        $this->assertEquals(40, (float) ShopProduct::find($product->id)->cost);
    }

    public function test_a_product_of_another_store_is_not_found_and_view_only_accounts_are_refused(): void
    {
        $product = $this->makeProduct();
        $readOnly = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $this->writeFields($readOnly, $product, ['price' => 10])->assertStatus(403);

        $storeId = $this->otherStoreId();
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);
        $this->writeFields($this->tokenFor($this->adminWithUris(['ANY::' . $this->productScreen() . '/*']), ['admin']), $product, ['price' => 10])
            ->assertStatus(404);
    }

    /**
     * @param string      $token
     * @param ShopProduct $product
     * @param array       $body
     * @return \Illuminate\Testing\TestResponse
     */
    private function writeFields(string $token, ShopProduct $product, array $body)
    {
        return $this->apiCall('PATCH', $this->v2Core() . '/product/' . $product->id, $token, $body);
    }

    /**
     * @return string
     */
    private function writer(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
