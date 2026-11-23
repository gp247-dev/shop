<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopProduct;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * Prefixed primary keys (PRD-, ODD-, CUS-…) never collide however large a table grows,
 * keep their readable prefix and fit the char(36) key column; existing short ids still work.
 *
 * WHY 200,000 ids compared upper-cased: the key columns use utf8mb4_unicode_ci, which
 * compares case-insensitively — the old 5-character random suffix collided ~450 times at
 * that volume (benchmark 2026-11-20), every collision being a failed save.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-prefixed-id-collision-safe
 * @aidlc-adr compat-foundation_prefixed-id-ulid
 */
class PrefixedIdCollisionSafeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_prefixed_ids_never_repeat_ignoring_letter_case(): void
    {
        $seen = [];
        $duplicates = 0;
        for ($i = 0; $i < 200000; $i++) {
            $key = strtoupper(gp247_generate_id('PRD'));
            if (isset($seen[$key])) {
                $duplicates++;
            }
            $seen[$key] = true;
        }

        $this->assertSame(0, $duplicates, 'Prefixed ids must not collide under a case-insensitive key column');
    }

    public function test_prefixed_id_keeps_prefix_and_fits_char36_key(): void
    {
        foreach (['PRD', 'ODD', 'STO', 'au', 'BN'] as $prefix) {
            $id = gp247_generate_id($prefix);

            $this->assertStringStartsWith(strtoupper($prefix) . '-', $id);
            $this->assertLessThanOrEqual(36, strlen($id));
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{1,9}-[0-9A-Z]{26}$/', $id);
        }
    }

    public function test_prefix_too_long_for_key_falls_back_to_plain_uuid(): void
    {
        $id = gp247_generate_id('ABCDEFGHIJ');

        $this->assertSame(36, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    public function test_new_prefixed_ids_sort_after_older_ones(): void
    {
        $older = gp247_generate_id('ODD');
        usleep(2000);
        $newer = gp247_generate_id('ODD');

        $this->assertGreaterThan(0, strcmp($newer, $older), 'An id created later must sort after an earlier one');
    }

    public function test_product_saved_through_model_gets_new_id_form(): void
    {
        $product = ShopProduct::create([
            'sku' => 'PIDCS-' . uniqid(),
            'alias' => 'prefixed-id-collision-safe-' . uniqid(),
            'store_id' => GP247_STORE_ID_ROOT,
        ]);

        $this->assertMatchesRegularExpression('/^PRD-[0-9A-Z]{26}$/', $product->id);
        $this->assertNotNull(ShopProduct::find($product->id));
    }

    public function test_existing_short_ids_still_resolve(): void
    {
        DB::table((new ShopProduct)->getTable())->insert([
            'id' => 'PRD-AbC12',
            'sku' => 'PIDCS-legacy-' . uniqid(),
            'alias' => 'prefixed-id-legacy-' . uniqid(),
            'store_id' => GP247_STORE_ID_ROOT,
        ]);

        $this->assertNotNull(ShopProduct::find('PRD-AbC12'));
    }
}
