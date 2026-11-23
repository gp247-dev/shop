<?php

namespace GP247\Shop\Tests\Unit\Compat;

use GP247\Core\Database\Seeders\DataLanguageSeeder;
use GP247\Core\Database\Seeders\LocaleRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guard for the default locale set (en, vi, ja): every language code shipped by
 * the core/front/shop seeders exists in all three locales with the same
 * placeholders, and the per-locale data file is turned into seeder rows that
 * borrow their position from the 'en' row.
 *
 * Pure data checks — no database.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-japanese-default-locale
 * @aidlc-adr compat-foundation_locale-data-files
 */
class DefaultLocaleKeyParityTest extends TestCase
{
    /**
     * Seeder classes that own the default language strings, keyed by package.
     *
     * @return array<string, array{0: string}>
     */
    public static function seederProvider(): array
    {
        return [
            'core' => [DataLanguageSeeder::class],
            'front' => ['GP247\\Front\\Admin\\Database\\Seeders\\DataFrontLanguageSeeder'],
            'shop' => ['GP247\\Shop\\Admin\\Database\\Seeders\\DataShopLanguageSeeder'],
        ];
    }

    /**
     * Group seeder rows into code => text maps per location.
     *
     * @param string $class Seeder class name.
     * @return array<string, array<string, string>>
     */
    private function byLocation(string $class): array
    {
        if (!class_exists($class)) {
            $this->markTestSkipped("{$class} is not installed.");
        }
        $map = [];
        foreach ((new $class())->dataLang() as $row) {
            $map[$row['location']][$row['code']] = (string) $row['text'];
        }

        return $map;
    }

    /**
     * Placeholder tokens (":name") used by a translation, sorted and unique.
     *
     * @param string $text Translation text.
     * @return array<int, string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:[A-Za-z_]+/', $text, $m);
        $tokens = array_values(array_unique($m[0]));
        sort($tokens);

        return $tokens;
    }

    #[DataProvider('seederProvider')]
    public function test_every_english_code_is_translated_to_vietnamese_and_japanese(string $class): void
    {
        $map = $this->byLocation($class);
        $en = array_keys($map['en'] ?? []);
        $this->assertNotEmpty($en, 'seeder ships English strings');

        foreach (['vi', 'ja'] as $locale) {
            $have = array_keys($map[$locale] ?? []);
            $this->assertSame([], array_values(array_diff($en, $have)), "codes missing in {$locale}");
            $this->assertSame([], array_values(array_diff($have, $en)), "codes in {$locale} without an en row");
        }
    }

    #[DataProvider('seederProvider')]
    public function test_japanese_keeps_the_placeholders_of_english(string $class): void
    {
        $map = $this->byLocation($class);
        $mismatch = [];
        foreach ($map['en'] ?? [] as $code => $text) {
            if (isset($map['ja'][$code]) && $this->placeholders($text) !== $this->placeholders($map['ja'][$code])) {
                $mismatch[] = $code;
            }
        }
        $this->assertSame([], $mismatch);
    }

    /**
     * Upgrade-migration folders per package, with the seeder and locale file
     * that hold the package's Japanese text.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function migrationProvider(): array
    {
        // WHY APP_BASE_PATH: the packages read are the ones installed in the host application
        // (tests/bootstrap.php sets it), not siblings of this checkout.
        $root = ($_ENV['APP_BASE_PATH'] ?? $_SERVER['APP_BASE_PATH'] ?? (getenv('APP_BASE_PATH') ?: dirname(__DIR__, 6))) . '/vendor/gp247';

        return [
            'core' => [$root.'/core/src/Database/Migrations/upgrade', DataLanguageSeeder::class, $root.'/core/src/Database/Seeders/Locales/ja.php'],
            'front' => [$root.'/front/src/Admin/Database/Migrations/upgrade', 'GP247\\Front\\Admin\\Database\\Seeders\\DataFrontLanguageSeeder', $root.'/front/src/Admin/Database/Seeders/Locales/ja.php'],
            'shop' => [$root.'/shop/src/Admin/Database/Migrations/upgrade', 'GP247\\Shop\\Admin\\Database\\Seeders\\DataShopLanguageSeeder', $root.'/shop/src/Admin/Database/Seeders/Locales/ja.php'],
        ];
    }

    /**
     * A label an upgrade migration seeds (helper `$add('code', …)` / `$l('code', …)`
     * or a literal English row) must have Japanese text too — the seeder parity
     * above cannot see labels that only a migration writes.
     */
    #[DataProvider('migrationProvider')]
    public function test_every_label_an_upgrade_migration_seeds_has_japanese_text(string $dir, string $seeder, string $localeFile): void
    {
        if (!is_dir($dir) || !class_exists($seeder)) {
            $this->markTestSkipped('package not installed');
        }
        $japanese = array_flip(array_column(
            array_filter((new $seeder())->dataLang(), fn ($r) => $r['location'] === 'ja'),
            'code'
        ));
        $japanese += is_file($localeFile) ? (require $localeFile) : [];

        $missing = [];
        foreach (glob($dir.'/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all('/\$(?:add|l)\(\s*\'([a-z][A-Za-z0-9_.]+)\'\s*,/', $src, $helper);
            preg_match_all('/\'code\'\s*=>\s*\'([a-z][A-Za-z0-9_.]+)\'[^\]\n]*\'location\'\s*=>\s*\'en\'/', $src, $rows);
            foreach (array_unique(array_merge($helper[1], $rows[1])) as $code) {
                if (!array_key_exists($code, $japanese)) {
                    $missing[] = basename($file).': '.$code;
                }
            }
        }
        $this->assertSame([], $missing);
    }

    public function test_locale_rows_take_position_from_the_english_row(): void
    {
        $file = $this->tempLocaleFile(['a.title' => 'タイトル', 'orphan.code' => '孤児']);
        $base = [
            ['code' => 'a.title', 'text' => 'Title', 'position' => 'a', 'location' => 'en'],
            ['code' => 'a.title', 'text' => 'Tiêu đề', 'position' => 'a', 'location' => 'vi'],
        ];

        $rows = LocaleRows::fromFile($file, 'ja', $base);

        $this->assertSame(
            [['code' => 'a.title', 'text' => 'タイトル', 'position' => 'a', 'location' => 'ja']],
            $rows,
            'code without an en row is dropped; position copied from en'
        );
    }

    public function test_locale_rows_are_empty_when_the_data_file_is_missing(): void
    {
        $this->assertSame([], LocaleRows::fromFile(sys_get_temp_dir().'/gp247-no-such-locale.php', 'ja', []));
    }

    /**
     * Write a throwaway locale data file.
     *
     * @param array<string, string> $map
     * @return string Absolute path.
     */
    private function tempLocaleFile(array $map): string
    {
        $file = tempnam(sys_get_temp_dir(), 'gp247-locale').'.php';
        file_put_contents($file, '<?php return '.var_export($map, true).';');

        return $file;
    }
}
