<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite\Support;

use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopProduct;
use Illuminate\Support\Collection;
use GP247\Shop\Tests\Feature\ApiSync\Support\EventLogFixtures;

/**
 * Reading the shop events a test caused (with ApiFixtures + DatabaseTransactions): turn the
 * event log on, then list the rows written after a mark, data decoded.
 */
trait ShopEventFixtures
{
    use EventLogFixtures;

    /**
     * Turn the event log on and return the mark after which this test's events start.
     *
     * @return int
     */
    protected function startEventLog(): int
    {
        $this->setEventLog(true);

        return $this->lastEventId();
    }

    /**
     * Put $product in a new category (the admin product form requires one).
     *
     * @param ShopProduct $product
     * @return ShopProduct
     */
    protected function inCategory(ShopProduct $product): ShopProduct
    {
        $category = ShopCategory::create(['alias' => 'zz-ev-cat-' . uniqid(), 'image' => '', 'parent' => null, 'top' => 0, 'status' => 1, 'sort' => 0,
            'store_id' => (string) $product->store_id]);
        $product->categories()->attach($category->id);

        return $product;
    }

    /**
     * Events written after $mark, oldest first, optionally of one type and one subject.
     *
     * @param int         $mark
     * @param string|null $type
     * @param string|null $subjectId
     * @return Collection<int, object>
     */
    protected function eventsAfter(int $mark, ?string $type = null, ?string $subjectId = null): Collection
    {
        $query = $this->events()->where('id', '>', $mark)->orderBy('id');
        if ($type !== null) {
            $query->where('type', $type);
        }
        if ($subjectId !== null) {
            $query->where('subject_id', $subjectId);
        }

        return $query->get()->map(function ($row) {
            $row->data = json_decode((string) $row->data, true);
            return $row;
        });
    }
}
