<?php

namespace GP247\Shop\Commands;

use GP247\Core\Console\GP247Command;

/**
 * Install the GP247 shop (ecommerce) module: uninstall the old shop, recreate
 * tables, seed initialize + root-store defaults, publish shop front views.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005
 * @aidlc-adr system-cli_output-contract
 */
class ShopInstall extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:shop-install {--force=0 : Reinstall over existing shop tables without asking (DESTROYS their data)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'GP247 shop install';

    /**
     * Execute the console command.
     *
     * @return int Exit code.
     */
    protected function handleGp247(): int
    {
        // WHY before the uninstall: a site that already has shop tables loses its products, orders
        // and customers on a reinstall, so it needs consent (--force=1 or "yes"); a fresh site is
        // not asked (US-CLI-003).
        if (($stop = $this->guardReinstall('shop_product', 'shop')) !== null) {
            return $stop;
        }

        // Uninstall gp247 shop before install
        $this->runArtisan('gp247:shop-uninstall');

        // Install gp247 shop
        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '00_00_00_create_tables_shop')->delete();

        // WHY migrateForInstall(): --force so an APP_ENV=production site gets its tables back, and a
        // failed migration stops before the seeders write into tables that are not there.
        if (($stop = $this->migrateForInstall('/vendor/gp247/shop/src/Admin/Database/Migrations/00_00_00_create_tables_shop.php')) !== null) {
            return $stop;
        }
        $this->info('---------------> Migrate schema Shop default done!');

        $this->runArtisan('db:seed', ['--class' => '\GP247\Shop\Admin\Database\Seeders\DataShopInitializeSeeder', '--force' => true]);
        $this->info('---------------> Seeding database Shop default done!');

        $this->runArtisan('db:seed', ['--class' => '\GP247\Shop\Admin\Database\Seeders\DataShopDefaultSeeder', '--force' => true]);
        $this->info('---------------> Seeding database for store root done!');

        // Payment requests live in an upgrade migration (idempotent, add-only) so existing
        // sites get them through gp247:shop-update; a fresh install runs it here. Its
        // tables are not in the shop's create-tables migration on purpose: shop-uninstall
        // drops those, and the money ledger must survive a reinstall.
        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '2026_10_01_200000_install_payment_request')->delete();
        if (($stop = $this->migrateForInstall('/vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_01_200000_install_payment_request.php')) !== null) {
            return $stop;
        }
        $this->info('---------------> Payment requests ready!');

        // Standard shipping layer (shipments, rate snapshot, ward): same reasoning — an
        // idempotent add-only upgrade migration, run here on a fresh install.
        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '2026_10_17_100000_install_shop_shipping')->delete();
        if (($stop = $this->migrateForInstall('/vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_17_100000_install_shop_shipping.php')) !== null) {
            return $stop;
        }
        $this->info('---------------> Shipping layer ready!');

        // Japanese twins of labels the shipping migration just seeded (they are not
        // in the language seeder, so the seeder could not add them).
        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '2026_10_23_090000_seed_japanese_migration_labels')->delete();
        if (($stop = $this->migrateForInstall('/vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_23_090000_seed_japanese_migration_labels.php')) !== null) {
            return $stop;
        }

        // Cart / checkout through the API: order channel column + "ordering through the API" switch
        // (off) + its label — the same idempotent add-only upgrade migration as an upgraded site runs.
        \DB::connection(GP247_DB_CONNECTION)->table('migrations')->where('migration', '2026_10_30_120000_install_api_commerce')->delete();
        if (($stop = $this->migrateForInstall('/vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_30_120000_install_api_commerce.php')) !== null) {
            return $stop;
        }

        // WHY: shop tables are new here, so their text is already stored as typed; without the
        // flag the first gp247:update would decode "&amp;" a user typed on purpose.
        \GP247\Core\Support\StoredTextDecoder::markStoredAsTyped('text_storage_decoded_shop');
        // WHY last: the four upgrade migrations run above already have their rows; the base
        // migration and the seeders did what the others do, so the first gp247:update must not
        // run them again (US-CLI-fresh-install-upgrade-baseline).
        $recorded = \GP247\Core\Support\UpgradeMigrationBaseline::markApplied(dirname(__DIR__) . '/Admin/Database/Migrations/upgrade');
        $this->info('---------------> Shop upgrade migrations recorded: ' . count($recorded));

        // WHY no vendor:publish here any more (modification 20260913T200309):
        // shop's storefront views are served from this package through the
        // GP247TemplatePath hint paths, so an install no longer copies them into
        // app/GP247/Templates/GP247Front. Copying them used to freeze them there
        // forever — no composer update could ever reach the site again. A site
        // that wants to edit one publishes just that file:
        //   php artisan gp247:template-publish GP247Front --file=screen/shop_cart.blade.php
        return $this->respondSuccess(['installed' => true]);
    }
}
