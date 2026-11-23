<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\ProductManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Feature-Test — the product screen's "reference store" (where categories,
 * brands and taxes of a product are looked up and checked) is the product's
 * owner store unless a registered resolver says otherwise. Resolvers come from
 * `gp247-config.shop.product_reference_store_resolvers`, the same registration
 * shape as the price resolvers; the first non-null answer wins and a broken
 * resolver is skipped.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-store-single-owner
 * @aidlc-adr shop-admin_product-reference-store
 */
class ProductReferenceStoreTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private const KEY = 'gp247-config.shop.product_reference_store_resolvers';

    /** Current resolvers, restored after each case (plugins register theirs at boot). */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = (array) config(self::KEY, []);
        $this->asUser(administrator: true);
    }

    protected function tearDown(): void
    {
        config([self::KEY => $this->original]);
        parent::tearDown();
    }

    /** The screen's reference store and owner store, read from a live component. */
    private function stores(): array
    {
        $component = Livewire::test(ProductManager::class)->instance();
        $owner = (new \ReflectionMethod($component, 'currentStore'))->invoke($component);
        $reference = (new \ReflectionMethod($component, 'referenceStoreId'))->invoke($component);

        return [$owner, $reference];
    }

    public function test_without_resolvers_the_reference_store_is_the_owner_store(): void
    {
        config([self::KEY => []]);

        [$owner, $reference] = $this->stores();

        $this->assertSame($owner, $reference);
    }

    public function test_the_first_resolver_with_an_answer_decides_the_reference_store(): void
    {
        config([self::KEY => [
            ['key' => 'ZzNoOpinion', 'callback' => static fn (string $owner): ?string => null],
            ['key' => 'ZzFirst', 'callback' => static fn (string $owner): ?string => 'STO-zz-first'],
            ['key' => 'ZzSecond', 'callback' => static fn (string $owner): ?string => 'STO-zz-second'],
        ]]);

        [, $reference] = $this->stores();

        $this->assertSame('STO-zz-first', $reference);
    }

    public function test_a_broken_resolver_is_skipped_and_the_owner_store_is_kept(): void
    {
        config([self::KEY => [
            ['key' => 'ZzBroken', 'callback' => static function (string $owner): ?string {
                throw new \RuntimeException('boom');
            }],
            ['key' => 'ZzNotCallable', 'callback' => 'zz_no_such_function'],
        ]]);

        [$owner, $reference] = $this->stores();

        $this->assertSame($owner, $reference);
    }
}
