<?php

namespace GP247\Shop\Commands;

use GP247\Core\Console\GP247Command;
use Throwable;

/**
 * Non-destructive upgrade for an existing GP247 shop install.
 *
 * Unlike gp247:shop-install (which drops and recreates every shop table), this
 * command only applies incremental, idempotent schema/config changes so a live
 * site keeps its data. It runs the upgrade migrations by --path (the shop's
 * create-tables migration is deliberately NOT auto-discovered), then adds the
 * shop language rows the site is missing (seeder, insertOrIgnore) and reminds the
 * operator that gp247:language-update overwrites edited translations.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CLI-005, US-CLI-upgrade-migration-delivery, US-CMP-language-rows-via-seeder
 * @aidlc-adr system-cli_output-contract, compat-foundation_public-release-migration-policy
 */
class ShopUpdate extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:shop-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Non-destructive upgrade of the GP247 shop schema/config for an existing install';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        try {
            // WHY: run only the upgrade/ folder, never the sibling create-tables
            // migration which would wipe the database.
            $this->runArtisan('migrate', [
                '--path'  => '/vendor/gp247/shop/src/Admin/Database/Migrations/upgrade',
                '--force' => true,
            ]);
            $this->info('---------------> Shop upgrade migrations done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            return $this->respondFailure('upgrade_failed', 'Shop upgrade failed: '.$e->getMessage());
        }

        // WHY after the migrations: new labels ship in the language seeder, not in upgrade
        // migrations; insertOrIgnore adds the missing ones and keeps a translation the site
        // owner edited (US-CMP-language-rows-via-seeder).
        try {
            $this->runArtisan('db:seed', [
                '--class' => '\GP247\Shop\Admin\Database\Seeders\DataShopLanguageSeeder',
                '--force' => true,
            ]);
            $this->info('---------------> Shop language rows done!');
        } catch (Throwable $e) {
            gp247_report($e->getMessage());
            return $this->respondFailure('seed_failed', 'Shop language rows failed: '.$e->getMessage());
        }

        // Rewording of an existing label only reaches the site through the upsert command,
        // so a site owner's edited translations are overwritten only when they opt in.
        $this->line('');
        $this->info('Next step (optional): run "php artisan gp247:language-update" to refresh language rows for any new or renamed keys (upsert — overwrites edited translations).');

        return $this->respondSuccess([
            'upgraded'  => true,
            'languages_seeded' => true,
            'next_step' => 'gp247:language-update',
        ]);
    }
}
