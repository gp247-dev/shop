<?php

namespace GP247\Shop\Tests\Feature\ApiSync;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP keeps a copy of the site in sync by asking each admin list for the records
 * changed since its last run: `updated_since` + cursor paging that neither skips nor
 * repeats rows, even when many rows share one second or a row changes between pages.
 *
 * Timestamps sit in 2037 so the probe never mixes with the site's own data.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-changed-since-filter
 * @aidlc-adr api-sync_keyset-changed-since
 */
#[Group('api-sync')]
class ApiChangedSinceListTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private const SINCE = '2037-06-01 00:00:00';

    public function test_an_erp_receives_only_the_products_changed_since_its_mark_oldest_change_first(): void
    {
        $token = $this->productReader();
        $older = $this->productAt('2037-05-01 09:00:00');
        $second = $this->productAt('2037-06-01 10:00:05');
        $first = $this->productAt('2037-06-01 10:00:00');

        $body = $this->sync($token, ['updated_since' => self::SINCE])->assertOk()->json();

        $ids = array_column($body['data'], 'id');
        $this->assertSame([$first->id, $second->id], $ids);
        $this->assertNotContains($older->id, $ids);
    }

    public function test_reading_every_page_by_cursor_returns_each_row_once_even_within_the_same_second(): void
    {
        $token = $this->productReader();
        $made = [];
        for ($i = 0; $i < 5; $i++) {
            $made[] = $this->productAt('2037-06-02 08:00:00')->id;
        }

        $seen = $this->readAll($token, ['updated_since' => self::SINCE, 'page' => ['size' => 2]], $pages);

        $this->assertSame(3, $pages);
        $this->assertCount(5, $seen);
        $this->assertEqualsCanonicalizing($made, $seen);
    }

    public function test_a_row_changed_between_two_pages_does_not_hide_the_other_rows(): void
    {
        $token = $this->productReader();
        $made = [];
        foreach (['2037-06-03 08:00:00', '2037-06-03 08:00:01', '2037-06-03 08:00:02', '2037-06-03 08:00:03'] as $at) {
            $made[] = $this->productAt($at)->id;
        }

        $page1 = $this->sync($token, ['updated_since' => self::SINCE, 'page' => ['size' => 2]])->assertOk()->json();
        $this->touchAt($page1['data'][0]['id'], '2037-06-04 00:00:00');
        $page2 = $this->sync($token, ['updated_since' => self::SINCE, 'page' => ['size' => 10, 'after' => $page1['meta']['next_after']]])->assertOk()->json();

        $seen = array_merge(array_column($page1['data'], 'id'), array_column($page2['data'], 'id'));
        $this->assertEqualsCanonicalizing($made, array_values(array_unique($seen)));
        $this->assertSame($page1['data'][0]['id'], end($page2['data'])['id'], 'The changed row comes again, at its new place');
    }

    public function test_the_answer_gives_the_mark_for_the_next_run_and_tells_whether_more_pages_follow(): void
    {
        $token = $this->productReader();
        $this->productAt('2037-06-05 08:00:00');
        $this->productAt('2037-06-05 09:30:00');

        $first = $this->sync($token, ['updated_since' => self::SINCE, 'page' => ['size' => 1]])->assertOk()->json();
        $this->assertTrue($first['meta']['has_more']);
        $this->assertNotEmpty($first['meta']['next_after']);
        $this->assertStringContainsString('page%5Bafter%5D=', (string) $first['links']['next']);

        $last = $this->sync($token, ['updated_since' => self::SINCE, 'page' => ['size' => 1, 'after' => $first['meta']['next_after']]])->assertOk()->json();
        $this->assertFalse($last['meta']['has_more']);
        $this->assertNull($last['links']['next']);
        $this->assertEquals(
            Carbon::parse('2037-06-05 09:30:00', config('app.timezone')),
            Carbon::parse($last['meta']['sync_until'])
        );
        $this->assertArrayNotHasKey('total', $last['meta']);
    }

    public function test_a_bad_mark_a_forged_cursor_or_a_conflicting_sort_or_page_number_is_refused(): void
    {
        $token = $this->productReader();

        foreach ([
            ['updated_since' => 'yesterday'],
            ['updated_since' => self::SINCE, 'page' => ['after' => 'not-a-cursor']],
            ['updated_since' => self::SINCE, 'sort' => '-price'],
            ['updated_since' => self::SINCE, 'page' => ['number' => 2]],
        ] as $query) {
            $this->sync($token, $query)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_without_a_mark_the_list_keeps_its_usual_shape(): void
    {
        $token = $this->productReader();

        $meta = $this->sync($token, [])->assertOk()->json('meta');

        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('last_page', $meta);
        $this->assertArrayNotHasKey('next_after', $meta);
    }

    public function test_syncing_still_shows_only_the_accounts_store_and_still_needs_the_screen_permission(): void
    {
        $storeId = $this->otherStoreId();
        $mine = $this->productAt('2037-06-06 08:00:01', ['store_id' => $storeId]);
        $other = $this->productAt('2037-06-06 08:00:00');
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);

        $ids = array_column($this->sync($this->productReader(), ['updated_since' => self::SINCE])->assertOk()->json('data'), 'id');
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $noPermission = $this->tokenFor($this->makeAdmin(['admin_customer.index']), ['admin']);
        $this->sync($noPermission, ['updated_since' => self::SINCE])->assertStatus(403);
    }

    public function test_every_admin_list_accepts_a_change_mark(): void
    {
        $token = $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);

        foreach (['product', 'category', 'brand', 'supplier', 'customer', 'order', 'banner', 'page'] as $resource) {
            $response = $this->apiCall('GET', $this->v2Core() . '/' . $resource . '/list?' . http_build_query(['updated_since' => self::SINCE]), $token);
            $response->assertOk();
            $this->assertArrayHasKey('has_more', $response->json('meta'), $resource . '/list must answer in sync mode');
        }
    }

    /**
     * Token of an admin allowed to read the product list.
     *
     * @return string
     */
    private function productReader(): string
    {
        return $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
    }

    /**
     * Product whose updated_at is $at (set without touching the timestamp again).
     *
     * @param string $at
     * @param array  $attributes
     * @return ShopProduct
     */
    private function productAt(string $at, array $attributes = []): ShopProduct
    {
        $product = $this->makeProduct($attributes);
        $this->touchAt($product->id, $at);

        return $product;
    }

    /**
     * @param string $id
     * @param string $at
     * @return void
     */
    private function touchAt(string $id, string $at): void
    {
        DB::connection(GP247_DB_CONNECTION)->table((new ShopProduct)->getTable())->where('id', $id)->update(['updated_at' => $at]);
    }

    /**
     * @param string $token
     * @param array  $query
     * @return \Illuminate\Testing\TestResponse
     */
    private function sync(string $token, array $query)
    {
        return $this->apiCall('GET', $this->v2Core() . '/product/list' . ($query ? '?' . http_build_query($query) : ''), $token);
    }

    /**
     * Follow next_after until has_more is false; returns every id seen.
     *
     * @param string   $token
     * @param array    $query
     * @param int|null $pages Number of pages read (out).
     * @return string[]
     */
    private function readAll(string $token, array $query, ?int &$pages = null): array
    {
        $seen = [];
        $pages = 0;
        do {
            $body = $this->sync($token, $query)->assertOk()->json();
            $pages++;
            $seen = array_merge($seen, array_column($body['data'], 'id'));
            $query['page']['after'] = $body['meta']['next_after'];
        } while ($body['meta']['has_more'] && $pages < 20);

        return $seen;
    }
}
