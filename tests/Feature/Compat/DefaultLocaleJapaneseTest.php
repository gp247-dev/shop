<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Database\Seeders\DataDefaultSeeder;
use GP247\Core\Database\Seeders\DataLanguageSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * Japanese is part of the default language set: a new install has it switched
 * on with full translations, an installed site receives it switched off through
 * gp247:update, and the site owner's choices (edited text, deleted or enabled
 * language) survive every later update.
 *
 * Writes only DML inside DatabaseTransactions (no DDL), so the shared test
 * database is rolled back after each test.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-japanese-default-locale
 * @aidlc-adr compat-foundation_locale-data-files
 */
class DefaultLocaleJapaneseTest extends TestCase
{
    use DatabaseTransactions;

    private const CORE_MIGRATION = 'vendor/gp247/core/src/Database/Migrations/upgrade/2026_10_22_201531_add_japanese_locale.php';
    private const FRONT_MIGRATION = 'vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_10_22_201531_seed_japanese_strings.php';
    private const SHOP_MIGRATION = 'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_22_201531_add_jpy_and_japanese_strings.php';

    /**
     * @return ConnectionInterface
     */
    private function db(): ConnectionInterface
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * Load an anonymous upgrade migration by repo-relative path.
     *
     * @param string $path
     * @return object
     */
    private function migration(string $path): object
    {
        return require base_path($path);
    }

    /**
     * Japanese codes a seeder ships (from its locale data file).
     *
     * @param string $seederClass
     * @return array<int, string>
     */
    private function jaCodes(string $seederClass): array
    {
        $rows = array_filter((new $seederClass())->dataLang(), fn ($r) => $r['location'] === 'ja');

        return array_values(array_column($rows, 'code'));
    }

    /**
     * @param array<int, string> $codes
     * @return int
     */
    private function countJa(array $codes): int
    {
        $count = 0;
        foreach (array_chunk($codes, 500) as $chunk) {
            $count += $this->db()->table(GP247_DB_PREFIX.'languages')
                ->where('location', 'ja')->whereIn('code', $chunk)->count();
        }

        return $count;
    }

    /**
     * @param array<int, string> $codes
     */
    private function deleteJa(array $codes): void
    {
        foreach (array_chunk($codes, 500) as $chunk) {
            $this->db()->table(GP247_DB_PREFIX.'languages')
                ->where('location', 'ja')->whereIn('code', $chunk)->delete();
        }
    }

    public function test_new_install_lists_japanese_switched_on_with_its_flag(): void
    {
        $ja = collect((new DataDefaultSeeder())->dataShopLang())->firstWhere('code', 'ja');

        $this->assertNotNull($ja);
        $this->assertSame('1', (string) $ja['status']);
        $this->assertSame('/GP247/Core/language/flag_jp.png', $ja['icon']);
        $this->assertSame('0', (string) $ja['rtl']);
        $this->assertNotContains((string) $ja['id'], array_map('strval', (array) GP247_GUARD_LANGUAGE));
        $this->assertFileExists(base_path('vendor/gp247/core/src/public/GP247/Core/language/flag_jp.png'));
    }

    public function test_new_install_store_has_a_japanese_description(): void
    {
        $seeder = new DataDefaultSeeder();
        $langs = array_column($seeder->dataAdminStoreDescription($seeder->title_en, $seeder->title_vi), 'lang');

        $this->assertContains('ja', $langs);
    }

    public function test_new_install_seeds_the_language_list_with_japanese(): void
    {
        $this->db()->table(GP247_DB_PREFIX.'admin_language')->delete();

        (new DataDefaultSeeder())->seedLanguageList($this->db());

        $this->assertSame(
            ['en', 'vi', 'ja'],
            $this->db()->table(GP247_DB_PREFIX.'admin_language')->orderBy('sort')->pluck('code')->all()
        );
    }

    public function test_core_update_does_not_bring_back_a_language_the_owner_deleted(): void
    {
        $this->db()->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->delete();

        (new DataDefaultSeeder())->seedLanguageList($this->db());

        $this->assertFalse($this->db()->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->exists());
    }

    public function test_installed_site_receives_japanese_switched_off_with_store_descriptions(): void
    {
        $this->db()->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->delete();
        $this->db()->table(GP247_DB_PREFIX.'admin_store_description')->where('lang', 'ja')->delete();

        $this->migration(self::CORE_MIGRATION)->up();

        $ja = $this->db()->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->first();
        $this->assertNotNull($ja);
        $this->assertSame(0, (int) $ja->status);
        $this->assertSame('/GP247/Core/language/flag_jp.png', $ja->icon);

        $stores = $this->db()->table(GP247_DB_PREFIX.'admin_store')->pluck('id');
        foreach ($stores as $storeId) {
            $this->assertTrue(
                $this->db()->table(GP247_DB_PREFIX.'admin_store_description')
                    ->where('store_id', $storeId)->where('lang', 'ja')->exists(),
                "store {$storeId} has a ja description"
            );
        }
    }

    public function test_store_description_in_japanese_starts_as_a_copy_of_english(): void
    {
        $table = GP247_DB_PREFIX.'admin_store_description';
        $en = $this->db()->table($table)->where('store_id', GP247_STORE_ID_ROOT)->where('lang', 'en')->first();
        if (!$en) {
            $this->markTestSkipped('ROOT store has no en description on this database.');
        }
        $this->db()->table($table)->where('lang', 'ja')->delete();

        $this->migration(self::CORE_MIGRATION)->up();

        $ja = $this->db()->table($table)->where('store_id', GP247_STORE_ID_ROOT)->where('lang', 'ja')->first();
        $this->assertSame($en->name, $ja->name);
        $this->assertSame($en->maintain_note, $ja->maintain_note);
    }

    public function test_running_the_core_update_twice_changes_nothing(): void
    {
        $this->db()->table(GP247_DB_PREFIX.'admin_language')->where('code', 'ja')->delete();
        $migration = $this->migration(self::CORE_MIGRATION);

        $migration->up();
        $languages = $this->db()->table(GP247_DB_PREFIX.'admin_language')->count();
        $descriptions = $this->db()->table(GP247_DB_PREFIX.'admin_store_description')->count();
        $migration->up();

        $this->assertSame($languages, $this->db()->table(GP247_DB_PREFIX.'admin_language')->count());
        $this->assertSame($descriptions, $this->db()->table(GP247_DB_PREFIX.'admin_store_description')->count());
    }

    public function test_japanese_the_owner_already_enabled_is_left_alone(): void
    {
        $table = GP247_DB_PREFIX.'admin_language';
        $this->db()->table($table)->where('code', 'ja')->delete();
        $this->db()->table($table)->insert(['name' => 'Nihongo', 'code' => 'ja', 'icon' => '', 'status' => 1, 'rtl' => 0, 'sort' => 9]);

        $this->migration(self::CORE_MIGRATION)->up();

        $ja = $this->db()->table($table)->where('code', 'ja')->first();
        $this->assertSame('Nihongo', $ja->name);
        $this->assertSame(1, (int) $ja->status);
    }

    public function test_upgrade_does_nothing_before_the_language_list_is_seeded(): void
    {
        $this->db()->table(GP247_DB_PREFIX.'admin_language')->delete();

        $this->migration(self::CORE_MIGRATION)->up();

        $this->assertSame(0, $this->db()->table(GP247_DB_PREFIX.'admin_language')->count());
    }

    public function test_core_update_reseed_delivers_core_japanese_strings_without_overwriting_edits(): void
    {
        $codes = $this->jaCodes(DataLanguageSeeder::class);
        $this->assertNotEmpty($codes);
        $this->deleteJa($codes);
        $this->db()->table(GP247_DB_PREFIX.'languages')->insert([
            'code' => $codes[0], 'text' => 'owner text', 'position' => 'x', 'location' => 'ja',
        ]);

        (new DataLanguageSeeder())->run();

        $this->assertSame(count($codes), $this->countJa($codes));
        $this->assertSame('owner text', $this->db()->table(GP247_DB_PREFIX.'languages')
            ->where('location', 'ja')->where('code', $codes[0])->value('text'));
    }

    public function test_front_update_delivers_front_japanese_strings_without_overwriting_edits(): void
    {
        $this->assertStringsDelivered('GP247\\Front\\Admin\\Database\\Seeders\\DataFrontLanguageSeeder', self::FRONT_MIGRATION);
    }

    public function test_shop_update_delivers_shop_japanese_strings_without_overwriting_edits(): void
    {
        $this->assertStringsDelivered('GP247\\Shop\\Admin\\Database\\Seeders\\DataShopLanguageSeeder', self::SHOP_MIGRATION);
    }

    public function test_labels_seeded_by_the_shipping_migration_get_their_japanese_twin(): void
    {
        $table = GP247_DB_PREFIX.'languages';
        $code = 'admin.shipment.status_in_transit';
        $this->db()->table($table)->where('code', $code)->delete();
        $this->db()->table($table)->insert([
            ['code' => $code, 'text' => 'In transit', 'position' => 'admin.shipment', 'location' => 'en'],
        ]);

        $migration = $this->migration('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_23_090000_seed_japanese_migration_labels.php');
        $migration->up();
        $migration->up();

        $ja = $this->db()->table($table)->where('code', $code)->where('location', 'ja')->get();
        $this->assertCount(1, $ja);
        $this->assertSame('admin.shipment', $ja[0]->position);
        $this->assertSame((require base_path('vendor/gp247/shop/src/Admin/Database/Seeders/Locales/ja.php'))[$code], $ja[0]->text);
    }

    /**
     * Delete a package's ja rows, keep one owner-edited row, run its upgrade
     * migration twice and check every code is back while the edit is kept.
     */
    private function assertStringsDelivered(string $seederClass, string $migrationPath): void
    {
        if (!class_exists($seederClass)) {
            $this->markTestSkipped("{$seederClass} is not installed.");
        }
        $codes = $this->jaCodes($seederClass);
        $this->assertNotEmpty($codes);
        $this->deleteJa($codes);
        $this->db()->table(GP247_DB_PREFIX.'languages')->insert([
            'code' => $codes[0], 'text' => 'owner text', 'position' => 'x', 'location' => 'ja',
        ]);

        $migration = $this->migration($migrationPath);
        $migration->up();
        $migration->up();

        $this->assertSame(count($codes), $this->countJa($codes));
        $this->assertSame('owner text', $this->db()->table(GP247_DB_PREFIX.'languages')
            ->where('location', 'ja')->where('code', $codes[0])->value('text'));
    }
}
