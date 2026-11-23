<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Core\Models\AdminLanguage;
use GP247\Shop\Admin\Livewire\CategoryManager;
use GP247\Shop\Admin\Livewire\ProductManager;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\ShopEventFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Recording shop events never costs or breaks anything: with the log off nothing is
 * recorded and no extra query runs, a failure to record leaves the change intact. And the
 * "changed since" mark means a real change: a page view no longer moves it, a new product
 * text on the admin form does.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-shop-events
 * @aidlc-adr api-admin-write_model-hook-event-emission
 */
#[Group('api-admin-write')]
class ShopEventSafetyTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use ShopEventFixtures;
    use ApiFixtures, MakesShopProducts {
        ApiFixtures::makeProduct insteadof MakesShopProducts;
        MakesShopProducts::makeProduct as makeNamedProduct;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_with_the_event_log_off_nothing_is_recorded_and_a_stock_move_costs_no_extra_query(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $this->setEventLog(false);
        $mark = $this->lastEventId();
        $queries = [];
        DB::connection(GP247_DB_CONNECTION)->listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'shop_product') || str_contains($query->sql, 'api_event')) {
                $queries[] = $query->sql;
            }
        });

        ShopProduct::adjustStock($product->id, 5);
        ShopProduct::setStock($product->id, 20);

        $this->assertCount(2, $queries, implode("\n", $queries));
        $this->assertSame(0, $this->events()->where('id', '>', $mark)->count());
    }

    public function test_a_failure_while_recording_an_event_never_breaks_the_change(): void
    {
        $product = $this->makeProduct(['stock' => 10]);
        $mark = $this->startEventLog();
        DB::connection(GP247_DB_CONNECTION)->beforeExecuting(function (string $sql) {
            if (str_contains($sql, 'api_event') && str_starts_with(strtolower(ltrim($sql)), 'insert')) {
                throw new \RuntimeException('zz event store down');
            }
        });

        $this->assertTrue(ShopProduct::adjustStock($product->id, 5));
        $fresh = ShopProduct::find($product->id);
        $fresh->price = 120;
        $fresh->save();

        $this->assertEquals(15, (float) ShopProduct::find($product->id)->stock);
        $this->assertEquals(120, (float) ShopProduct::find($product->id)->price);
        $this->assertSame(0, $this->events()->where('id', '>', $mark)->count());
    }

    public function test_viewing_a_product_page_counts_the_view_without_marking_the_product_changed(): void
    {
        $this->setUpFrontStore();
        try {
            $product = $this->makeNamedProduct('ZZ Viewed ' . uniqid());
            ShopProduct::where('id', $product->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00', 'view' => 4]);
            $mark = $this->startEventLog();

            $this->get(route('product.detail', ['alias' => $product->alias]))->assertOk();

            $fresh = ShopProduct::find($product->id);
            $this->assertSame(5, (int) $fresh->view);
            $this->assertNotNull($fresh->date_lastview);
            $this->assertSame('2026-01-01 00:00:00', (string) $fresh->getRawOriginal('updated_at'));
            $this->assertSame(0, $this->events()->where('id', '>', $mark)->count());
        } finally {
            $this->tearDownFrontStore();
        }
    }

    public function test_new_product_texts_saved_on_the_admin_form_mark_the_product_changed_and_record_product_updated(): void
    {
        $product = $this->inCategory($this->makeNamedProduct('ZZ Texts ' . uniqid()));
        $this->asUser(slugs: ['admin_product']);
        $this->useAdminStore();
        $mark = $this->startEventLog();

        // The form writes every amount again (10 over "10.00"): an equal number is not an edit.
        Livewire::test(ProductManager::class, ['id' => (string) $product->id])->call('save')->assertHasNoErrors();
        $this->assertCount(0, $this->eventsAfter($mark, null, (string) $product->id), 'saving nothing new records nothing');
        ShopProduct::where('id', $product->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);

        $form = Livewire::test(ProductManager::class, ['id' => (string) $product->id]);
        $this->withNames($form, 'ZZ Texts renamed')->call('save')->assertHasNoErrors();

        $this->assertNotSame('2026-01-01 00:00:00', (string) ShopProduct::find($product->id)->getRawOriginal('updated_at'));
        $this->assertEquals(['fields' => ['descriptions'], 'status' => 1], $this->eventsAfter($mark, 'product.updated', (string) $product->id)->sole()->data);
    }

    public function test_new_category_texts_saved_on_the_admin_form_mark_the_category_changed(): void
    {
        $category = ShopCategory::create(['alias' => 'zz-ev-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0, 'status' => 1, 'sort' => 0]);
        $codes = array_keys(AdminLanguage::getListActive()->all());
        foreach ($codes as $code) {
            ShopCategoryDescription::insert(['category_id' => $category->id, 'lang' => $code, 'name' => 'ZZ Cat', 'keyword' => '', 'description' => '']);
        }
        ShopCategory::where('id', $category->id)->toBase()->update(['updated_at' => '2026-01-01 00:00:00']);
        $this->asUser(slugs: ['admin_category']);

        $form = Livewire::test(CategoryManager::class, ['id' => (string) $category->id]);
        foreach ($codes as $code) {
            $form->set("desc.{$code}.name", 'ZZ Cat renamed');
        }
        $form->call('save')->assertHasNoErrors();

        $this->assertNotSame('2026-01-01 00:00:00', (string) ShopCategory::find($category->id)->getRawOriginal('updated_at'));
    }
}
