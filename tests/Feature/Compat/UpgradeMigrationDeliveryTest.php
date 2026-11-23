<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Front\Admin\Database\Seeders\DataFrontLanguageSeeder;
use GP247\Shop\Admin\Database\Seeders\DataShopLanguageSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\TestCase;

/**
 * `gp247:front-update` and `gp247:shop-update` bring default language rows a site is missing,
 * the way `gp247:core-update` already did, so a new label needs no upgrade migration. A text the
 * site owner edited is kept (only `gp247:language-update` overwrites).
 *
 * The commands also run `migrate --path …/upgrade`; on a test database where every upgrade
 * migration has run that is a no-op, so only DML happens inside the test transaction. A pending
 * upgrade migration would run DDL (auto-commit), so the test is skipped then.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CLI-upgrade-migration-delivery
 * @aidlc-story US-CMP-language-rows-via-seeder
 * @aidlc-adr compat-foundation_public-release-migration-policy
 */
class UpgradeMigrationDeliveryTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function languages()
    {
        return DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages');
    }

    /**
     * Skip when the command would run a pending upgrade migration (DDL breaks the transaction).
     *
     * @param string $folder upgrade/ folder relative to the site root.
     * @return void
     */
    private function requireNoPendingUpgrade(string $folder): void
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $pending = [];
        foreach (glob(base_path($folder) . '/*_*.php') ?: [] as $file) {
            if (!in_array(basename($file, '.php'), $ran, true)) {
                $pending[] = basename($file);
            }
        }
        if ($pending !== []) {
            $this->markTestSkipped('Run gp247:update on the test database first; pending: ' . implode(', ', $pending));
        }
    }

    /**
     * Remove one default row of the package and edit another, run its update command, and check.
     *
     * @param array  $defaults Rows of the package language seeder.
     * @param string $command  Update command.
     * @return void
     */
    private function assertUpdateRestoresMissingAndKeepsEdited(array $defaults, string $command): void
    {
        $existing = [];
        foreach ($defaults as $row) {
            if ($row['location'] !== 'en') {
                continue;
            }
            if ($this->languages()->where('code', $row['code'])->where('location', 'en')->exists()) {
                $existing[] = $row;
            }
            if (count($existing) === 2) {
                break;
            }
        }
        $this->assertCount(2, $existing, 'the test database must hold the package default labels');
        [$missing, $edited] = $existing;

        $this->languages()->where('code', $missing['code'])->where('location', 'en')->delete();
        $ownerText = 'Edited by the site owner ' . bin2hex(random_bytes(3));
        $this->languages()->where('code', $edited['code'])->where('location', 'en')->update(['text' => $ownerText]);

        $exit = Artisan::call($command);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertTrue(
            $this->languages()->where('code', $missing['code'])->where('location', 'en')->exists(),
            "{$command} must bring back the missing default label {$missing['code']}"
        );
        $this->assertSame(
            $ownerText,
            $this->languages()->where('code', $edited['code'])->where('location', 'en')->value('text'),
            "{$command} must keep a label the site owner edited"
        );
    }

    public function test_the_shop_update_command_adds_missing_default_labels_without_overwriting_edited_ones(): void
    {
        $this->requireNoPendingUpgrade('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade');

        $this->assertUpdateRestoresMissingAndKeepsEdited((new DataShopLanguageSeeder())->dataLang(), 'gp247:shop-update');
    }

    public function test_the_front_update_command_adds_missing_default_labels_without_overwriting_edited_ones(): void
    {
        $this->requireNoPendingUpgrade('vendor/gp247/front/src/Admin/Database/Migrations/upgrade');

        $this->assertUpdateRestoresMissingAndKeepsEdited((new DataFrontLanguageSeeder())->dataLang(), 'gp247:front-update');
    }
}
