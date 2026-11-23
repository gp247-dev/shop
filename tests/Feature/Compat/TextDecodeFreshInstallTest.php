<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Support\StoredTextDecoder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * A site installed fresh already stores text as typed, so its first `gp247:update` must not run
 * the one-time decode on it. Found while trying out the 3.12.0 release: `gp247:install` set no
 * `text_storage_decoded_*` flag, so the first update turned an "&amp;" a user typed on purpose
 * into "&". Each package's install command now marks its own package; the seeders that
 * `gp247:update` also runs never do (they run before the front/shop update).
 * Only DML inside the test transaction; the real install commands drop tables, so their wiring
 * is checked in the source.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-text-stored-as-typed
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
class TextDecodeFreshInstallTest extends TestCase
{
    use DatabaseTransactions;

    /** Flag of each package => its decode migration. */
    private const PACKAGES = [
        'text_storage_decoded_core' => 'vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_12_230000_decode_stored_text_core.php',
        'text_storage_decoded_front' => 'vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_11_12_230100_decode_stored_text_front.php',
        'text_storage_decoded_shop' => 'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_11_12_230200_decode_stored_text_shop.php',
    ];

    /** Install command source => the flag it must set. */
    private const INSTALLERS = [
        'vendor/gp247/core/src/Commands/Install.php' => 'text_storage_decoded_core',
        'vendor/gp247/front/src/Commands/FrontInstall.php' => 'text_storage_decoded_front',
        'vendor/gp247/shop/src/Commands/ShopInstall.php' => 'text_storage_decoded_shop',
    ];

    /** Seeders that gp247:update also runs — they must never set a flag. */
    private const SHARED_SEEDERS = [
        'vendor/gp247/core/src/Database/Seeders/DataDefaultSeeder.php',
        'vendor/gp247/core/src/Database/Seeders/DataLanguageSeeder.php',
        'vendor/gp247/core/src/Database/Seeders/DataLocaleSeeder.php',
        'vendor/gp247/front/src/Admin/Database/Seeders/DataFrontDefaultSeeder.php',
        'vendor/gp247/front/src/Admin/Database/Seeders/DataFrontLanguageSeeder.php',
        'vendor/gp247/shop/src/Admin/Database/Seeders/DataShopDefaultSeeder.php',
        'vendor/gp247/shop/src/Admin/Database/Seeders/DataShopLanguageSeeder.php',
    ];

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * Remove the three flags inside the transaction (a site that has never been decoded).
     *
     * @return void
     */
    private function forgetFlags(): void
    {
        $this->db()->table(GP247_DB_PREFIX . 'admin_config')
            ->whereIn('key', array_keys(self::PACKAGES))->delete();
    }

    /**
     * Rows a user typed on a fresh site, with an entity written on purpose, one per package.
     *
     * @return array<string, array{0: string, 1: int}> table => [column, id]
     */
    private function typedRows(): array
    {
        $typed = 'Write &amp; to show an ampersand';
        $store = (string) GP247_STORE_ID_ROOT;
        $p = GP247_DB_PREFIX;

        return [
            'admin_country' => ['name', (int) $this->db()->table("{$p}admin_country")->insertGetId(['code' => 'ZQ', 'name' => $typed])],
            'front_link_group' => ['name', (int) $this->db()->table("{$p}front_link_group")->insertGetId(['code' => 'zz_typed_' . uniqid(), 'name' => $typed, 'store_id' => $store])],
            'shop_tax' => ['name', (int) $this->db()->table("{$p}shop_tax")->insertGetId(['name' => $typed, 'value' => 0, 'store_id' => $store])],
        ];
    }

    /**
     * @param string $table
     * @param string $column
     * @param int $id
     * @return string|null
     */
    private function read(string $table, string $column, int $id): ?string
    {
        return $this->db()->table(GP247_DB_PREFIX . $table)->where('id', $id)->value($column);
    }

    public function test_a_package_marked_as_installed_with_text_stored_as_typed_is_not_decoded_by_its_upgrade_migration(): void
    {
        $this->forgetFlags();
        $rows = $this->typedRows();

        foreach (array_keys(self::PACKAGES) as $flag) {
            StoredTextDecoder::markStoredAsTyped($flag);
        }
        foreach (self::PACKAGES as $migration) {
            (require base_path($migration))->up();
        }

        foreach ($rows as $table => [$column, $id]) {
            $this->assertSame('Write &amp; to show an ampersand', $this->read($table, $column, $id), "$table.$column was decoded on a fresh site");
        }
        foreach (array_keys(self::PACKAGES) as $flag) {
            $this->assertSame(1, $this->db()->table(GP247_DB_PREFIX . 'admin_config')->where('key', $flag)
                ->where('store_id', (string) GP247_STORE_ID_GLOBAL)->count(), "$flag is stored once, on the global store");
        }
    }

    public function test_marking_twice_keeps_a_single_flag_row(): void
    {
        $this->forgetFlags();

        StoredTextDecoder::markStoredAsTyped('text_storage_decoded_core');
        StoredTextDecoder::markStoredAsTyped('text_storage_decoded_core');

        $this->assertSame(1, $this->db()->table(GP247_DB_PREFIX . 'admin_config')->where('key', 'text_storage_decoded_core')->count());
    }

    public function test_every_install_command_marks_its_own_package_after_seeding(): void
    {
        foreach (self::INSTALLERS as $path => $flag) {
            $source = (string) file_get_contents(base_path($path));
            $call = "StoredTextDecoder::markStoredAsTyped('{$flag}')";
            $at = strpos($source, $call);

            $this->assertNotFalse($at, "$path must mark $flag");
            $this->assertGreaterThan(strrpos($source, "'db:seed'"), $at, "$path must mark $flag after its last seeding step");
            foreach (array_diff(array_keys(self::PACKAGES), [$flag]) as $other) {
                $this->assertStringNotContainsString($other, $source, "$path must not mark another package ($other)");
            }
        }
    }

    public function test_the_seeders_that_gp247_update_also_runs_never_set_the_flags(): void
    {
        foreach (self::SHARED_SEEDERS as $path) {
            $this->assertStringNotContainsString('text_storage_decoded_', (string) file_get_contents(base_path($path)), $path);
            $this->assertStringNotContainsString('markStoredAsTyped', (string) file_get_contents(base_path($path)), $path);
        }
    }
}
