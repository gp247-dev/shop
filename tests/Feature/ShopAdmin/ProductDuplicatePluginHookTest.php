<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Models\ShopProductImage;
use GP247\Shop\Services\Catalog\ProductDuplicator;
use GP247\Shop\Services\Catalog\WriteContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\DuplicatesShopProducts;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The plugin hook of the product duplicate: a plugin registered in
 * `gp247-config.shop.product_duplicators` copies (or skips) its own data for the copy,
 * inside the same transaction; a failing plugin cancels the whole duplicate.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-duplicate-plugin-hook
 * @aidlc-adr shop-admin_product-duplicate
 */
#[Group('shop-admin')]
class ProductDuplicatePluginHookTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;
    use DuplicatesShopProducts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
    }

    /**
     * Register plugin duplicators for this test.
     *
     * @param array<int, array{key: string, callback: callable}> $duplicators
     * @return void
     */
    private function registerDuplicators(array $duplicators): void
    {
        config(['gp247-config.shop.product_duplicators' => $duplicators]);
    }

    public function test_a_registered_plugin_receives_the_original_and_the_copy_inside_the_duplicate_transaction_and_copies_its_own_data(): void
    {
        $source = $this->makeProduct('ZZ Hooked');
        $outerLevel = DB::connection(GP247_DB_CONNECTION)->transactionLevel();
        $seen = [];
        $this->registerDuplicators([[
            'key' => 'ZzRatingPlugin',
            'callback' => static function (ShopProduct $original, ShopProduct $copy, WriteContext $ctx) use (&$seen): void {
                $seen = [
                    'original' => (string) $original->id,
                    'copy' => (string) $copy->id,
                    'store' => $ctx->storeId,
                    'level' => DB::connection(GP247_DB_CONNECTION)->transactionLevel(),
                    'copy_has_texts' => ShopProductDescription::where('product_id', $copy->id)->exists(),
                ];
                // Stand-in for the plugin's own table: one row tied to the copy.
                ShopProductImage::create(['product_id' => $copy->id, 'image' => 'data/plugin/zz-hooked.jpg']);
            },
        ]]);

        $copy = $this->openedCopy(Livewire::test(ProductManager::class)->call('duplicate', $source->id)->assertHasNoErrors(), $source);

        $this->assertSame((string) $source->id, $seen['original']);
        $this->assertSame((string) $copy->id, $seen['copy']);
        $this->assertSame((string) $source->store_id, $seen['store']);
        $this->assertGreaterThan($outerLevel, $seen['level'], 'the plugin runs inside the duplicate transaction');
        $this->assertTrue($seen['copy_has_texts'], 'the plugin runs after the shop copied its own parts');
        $this->assertSame(['data/plugin/zz-hooked.jpg'], ShopProductImage::where('product_id', $copy->id)->pluck('image')->all());
    }

    public function test_a_failing_plugin_cancels_the_whole_duplicate_and_the_admin_is_told_which_plugin_failed(): void
    {
        $labels = $this->seedDuplicateLabels();
        $source = $this->makeProduct('ZZ Broken');
        $productsBefore = ShopProduct::count();
        $imagesBefore = ShopProductImage::count();
        $this->registerDuplicators([
            ['key' => 'ZzFirstPlugin', 'callback' => static function (ShopProduct $original, ShopProduct $copy): void {
                ShopProductImage::create(['product_id' => $copy->id, 'image' => 'data/plugin/zz-first.jpg']);
            }],
            ['key' => 'ZzBrokenPlugin', 'callback' => static function (): void {
                throw new \RuntimeException('plugin table missing');
            }],
        ]);

        Livewire::test(ProductManager::class)
            ->call('duplicate', $source->id)
            ->assertDispatched('notify', fn (string $name, array $params): bool => $params['type'] === 'error'
                && str_contains($params['message'], 'ZzBrokenPlugin')
                && !str_contains($params['message'], 'plugin table missing'))
            ->assertSet('editingId', null);

        $this->assertSame($productsBefore, ShopProduct::count(), 'no half-made copy is left behind');
        $this->assertSame($imagesBefore, ShopProductImage::count(), 'what an earlier plugin wrote is rolled back too');
        $this->assertStringContainsString(':plugin', $labels['admin.product.duplicate_failed']['en']);
    }

    public function test_the_duplicate_service_reports_the_failing_plugin_to_any_caller(): void
    {
        $source = $this->makeProduct('ZZ Service');
        $this->registerDuplicators([['key' => 'ZzServicePlugin', 'callback' => static function (): void {
            throw new \LogicException('boom');
        }]]);

        try {
            (new ProductDuplicator)->duplicate(new WriteContext((string) $source->store_id), (string) $source->id);
            $this->fail('A failing plugin must fail the duplicate.');
        } catch (\GP247\Shop\Services\Catalog\ProductDuplicateFailed $e) {
            $this->assertSame('ZzServicePlugin', $e->pluginKey);
            $this->assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function test_with_no_plugin_registered_the_duplicate_behaves_as_the_shop_alone_defines_it(): void
    {
        $this->registerDuplicators([]);
        $source = $this->makeProduct('ZZ Plain', ['stock' => 9]);

        $copy = $this->openedCopy(Livewire::test(ProductManager::class)->call('duplicate', $source->id)->assertHasNoErrors(), $source);

        $this->assertSame(0, (int) $copy->status);
        $this->assertEquals(0, (float) $copy->stock);
        $this->assertSame($source->sku . '-copy', $copy->sku);
    }

    public function test_an_entry_without_a_callable_is_skipped_and_does_not_block_the_site_owner(): void
    {
        $this->registerDuplicators([['key' => 'ZzMisconfigured', 'callback' => 'no_such_function_zz']]);
        $source = $this->makeProduct('ZZ Misconfigured');

        $this->openedCopy(Livewire::test(ProductManager::class)->call('duplicate', $source->id)->assertHasNoErrors(), $source);
    }
}
