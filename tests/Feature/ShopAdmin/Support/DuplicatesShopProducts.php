<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Core\Models\Languages;
use GP247\Shop\Admin\Database\Seeders\DataShopLanguageSeeder;
use GP247\Shop\Models\ShopProduct;

/**
 * Shared helpers of the product-duplicate feature tests: the labels the feature ships
 * (seeded from the shop's language seeder, so a test DB built before them still reads
 * real text) and the copy the Product screen opened after duplicating.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-duplicate
 */
trait DuplicatesShopProducts
{
    /**
     * Insert the `admin.product.duplicate*` rows of DataShopLanguageSeeder (rolled back with
     * the test) and forget the per-process label memo.
     *
     * @return array<string, array<string, string>> code => location => text
     */
    private function seedDuplicateLabels(): array
    {
        $rows = array_values(array_filter(
            (new DataShopLanguageSeeder)->dataLang(),
            static fn (array $row): bool => str_starts_with((string) $row['code'], 'admin.product.duplicate'),
        ));
        Languages::insertOrIgnore($rows);
        (new \ReflectionProperty(Languages::class, 'getList'))->setValue(null, []);

        $texts = [];
        foreach ($rows as $row) {
            $texts[$row['code']][$row['location']] = $row['text'];
        }

        return $texts;
    }

    /**
     * The product the screen is editing after a duplicate, asserting it is a new product.
     *
     * @param \Livewire\Features\SupportTesting\Testable $component
     * @param ShopProduct $source
     * @return ShopProduct
     */
    private function openedCopy($component, ShopProduct $source): ShopProduct
    {
        $id = (string) $component->get('editingId');
        $this->assertNotSame('', $id, 'the screen must open the copy for editing');
        $this->assertNotSame((string) $source->id, $id, 'the screen must open the copy, not the original');
        $copy = ShopProduct::find($id);
        $this->assertNotNull($copy);

        return $copy;
    }
}
