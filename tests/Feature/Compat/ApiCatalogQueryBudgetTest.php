<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Listing products through the API costs the same number of queries for a page of 2 as
 * for a page of many — with or without fields/include — and choosing only light fields
 * skips loading the heavy relations behind the others (variants, categories). This is the
 * automatic guard behind the p95 target; the time itself is measured by hand.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-sparse-fieldsets, US-CMP-api-include-relations
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiCatalogQueryBudgetTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private string $sku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sku = 'ZZBUDGET-' . strtoupper(uniqid());
        for ($i = 0; $i < 8; $i++) {
            $this->makeProduct(['sku' => $this->sku . '-' . $i]);
        }
    }

    /**
     * Number of queries one GET runs on the GP247 connection.
     *
     * @param string $query Query string after "?".
     * @return int
     */
    private function queriesFor(string $query): int
    {
        $uri = $this->v2Front() . '/product/list?q=' . $this->sku . '&' . $query;
        $connection = DB::connection(GP247_DB_CONNECTION);
        $connection->flushQueryLog();
        $connection->enableQueryLog();
        $this->getJson($uri)->assertOk();
        $count = count($connection->getQueryLog());
        $connection->disableQueryLog();
        $connection->flushQueryLog();
        return $count;
    }

    public function test_the_query_count_does_not_grow_with_the_page_size(): void
    {
        // WHY a warm-up call: the first request fills per-process memos (config, languages).
        $this->getJson($this->v2Front() . '/product/list?q=' . $this->sku)->assertOk();

        $this->assertSame($this->queriesFor('page[size]=2'), $this->queriesFor('page[size]=8'));
        $this->assertSame(
            $this->queriesFor('page[size]=2&fields[product]=name,price_final&include=brand'),
            $this->queriesFor('page[size]=8&fields[product]=name,price_final&include=brand')
        );
    }

    public function test_choosing_light_fields_skips_the_heavy_relations(): void
    {
        $this->getJson($this->v2Front() . '/product/list?q=' . $this->sku)->assertOk();

        $full = $this->queriesFor('page[size]=8');
        $light = $this->queriesFor('page[size]=8&fields[product]=name,price_final');

        $this->assertLessThan($full, $light);
    }
}
