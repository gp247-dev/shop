<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Core\Models\Languages;
use GP247\Front\Models\FrontLayoutBlock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use GP247\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The home promotion strip says what it lists.
 *
 * The block renders ShopProduct::getProductPromotion() — products carrying an
 * active price promotion whose `date_end` is routinely weeks out. Calling it
 * "Flash Sale" and topping it with a live countdown (it read "1266:51:16")
 * claimed an urgency the data does not have, and collided with the separate
 * ProductFlashSale plugin, which is the real time-boxed mechanism.
 *
 * The block key was renamed too: the Layout Block
 * screen offers blocks BY FILE NAME, so `shop_flash_sale` sat in the picker next to
 * the plugin's `product_flash_sale` with nothing to tell them apart. The blade moved
 * to `shop_product_promotion` and an upgrade migration carries installed sites over,
 * including any published override under app/.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-009
 */
#[Group('serial')]
class PromotionBlockLabelTest extends TestCase
{
    use DatabaseTransactions;

    /** The strip's blade, owned by gp247/shop (it reads shop models). */
    private function blockSource(): string
    {
        $path = base_path('vendor/gp247/shop/src/Views/templates/GP247Front/blocks/shop_product_promotion.blade.php');
        $this->assertFileExists($path, 'the block file name is what the Layout Block picker offers — it must say what the strip lists');

        return file_get_contents($path);
    }

    public function test_the_old_block_file_name_is_gone(): void
    {
        $this->assertFileDoesNotExist(
            base_path('vendor/gp247/shop/src/Views/templates/GP247Front/blocks/shop_flash_sale.blade.php'),
            'leaving the old file behind would put both names in the block picker'
        );
    }

    public function test_the_strip_heading_uses_the_promotion_language_code(): void
    {
        $source = $this->blockSource();

        $this->assertStringContainsString("gp247_language_quickly('front.promotion_products'", $source);
        $this->assertStringNotContainsString("gp247_language_quickly('front.flash_sale'", $source);
    }

    public function test_the_strip_renders_no_countdown(): void
    {
        $source = $this->blockSource();

        $this->assertStringNotContainsString('gp247frontCountdown', $source);
        // The deadline the timer counted down to is no longer computed at all.
        $this->assertStringNotContainsString('$flashSaleEndsAt', $source);
        $this->assertStringNotContainsString("pluck('promotionPrice.date_end')", $source);
    }

    public function test_a_fresh_install_seeds_the_promotion_label(): void
    {
        // Fresh installs get the heading from DataShopLanguageSeeder; the upgrade
        // migration below is what carries it to sites installed earlier.
        $seeder = file_get_contents(base_path('vendor/gp247/shop/src/Admin/Database/Seeders/DataShopLanguageSeeder.php'));

        $this->assertStringContainsString("'code' => 'front.promotion_products','text' => 'Sản phẩm khuyến mãi'", $seeder);
        $this->assertStringContainsString("'code' => 'front.promotion_products','text' => 'Promotion products'", $seeder);
    }

    public function test_a_fresh_install_names_the_block_after_what_it_lists(): void
    {
        $appConfig = file_get_contents(
            base_path('vendor/gp247/front/src/Views/templates/GP247Front/AppConfig.php')
        );

        $this->assertStringContainsString("'name'     => 'Promotion products ('.\$this->configKey.')'", $appConfig);
        // The key the row resolves the blade by is what the picker shows, so it says it too.
        $this->assertStringContainsString("'text'     => 'shop_product_promotion'", $appConfig);
        $this->assertStringNotContainsString("'text'     => 'shop_flash_sale'", $appConfig);
    }

    public function test_upgrade_seeds_the_promotion_label_for_installed_sites(): void
    {
        Languages::where('code', 'front.promotion_products')->delete();

        $this->languageMigration()->up();

        $seeded = Languages::where('code', 'front.promotion_products')
            ->pluck('text', 'location')
            ->all();

        $this->assertSame('Sản phẩm khuyến mãi', $seeded['vi'] ?? null);
        $this->assertSame('Promotion products', $seeded['en'] ?? null);
    }

    public function test_upgrade_keeps_a_label_the_site_owner_already_edited(): void
    {
        Languages::where('code', 'front.promotion_products')->delete();
        Languages::insert([
            ['code' => 'front.promotion_products', 'text' => 'Hàng giảm giá', 'position' => 'front', 'location' => 'vi'],
        ]);

        $this->languageMigration()->up();

        $this->assertSame(
            'Hàng giảm giá',
            Languages::where('code', 'front.promotion_products')->where('location', 'vi')->value('text')
        );
    }

    public function test_upgrade_renames_the_block_without_touching_its_key(): void
    {
        $block = $this->seedBlock('Flash sale (GP247Front)');

        $this->blockMigration()->up();

        $fresh = $block->fresh();
        $this->assertSame('Promotion products (GP247Front)', $fresh->name);
        // That migration renames the label only; the key moves in the later one.
        $this->assertSame('shop_flash_sale', $fresh->text);
    }

    public function test_upgrade_preserves_the_template_name_in_the_suffix(): void
    {
        $block = $this->seedBlock('Flash sale (MyTheme)', 'MyTheme');

        $this->blockMigration()->up();

        $this->assertSame('Promotion products (MyTheme)', $block->fresh()->name);
    }

    public function test_upgrade_leaves_a_renamed_block_alone_on_a_second_run(): void
    {
        $block = $this->seedBlock('Flash sale (GP247Front)');

        $this->blockMigration()->up();
        $this->blockMigration()->up();

        $this->assertSame('Promotion products (GP247Front)', $block->fresh()->name);
    }

    public function test_upgrade_does_not_touch_a_block_a_site_owner_renamed(): void
    {
        $block = $this->seedBlock('Khuyến mãi hot');

        $this->blockMigration()->up();

        $this->assertSame('Khuyến mãi hot', $block->fresh()->name);
    }

    public function test_upgrade_moves_installed_blocks_to_the_new_key(): void
    {
        $block = $this->seedBlock('Promotion products (GP247Front)');

        $this->keyMigration()->up();

        $this->assertSame('shop_product_promotion', $block->fresh()->text);
    }

    public function test_upgrade_leaves_the_moved_key_alone_on_a_second_run(): void
    {
        $block = $this->seedBlock('Promotion products (GP247Front)');

        $this->keyMigration()->up();
        $this->keyMigration()->up();

        $this->assertSame('shop_product_promotion', $block->fresh()->text);
    }

    public function test_upgrade_does_not_touch_other_blocks(): void
    {
        $other = $this->seedBlock('Product home (GP247Front)');
        $other->update(['text' => 'shop_product_home']);

        $this->keyMigration()->up();

        $this->assertSame('shop_product_home', $other->fresh()->text);
    }

    public function test_upgrade_carries_a_published_override_to_the_new_file_name(): void
    {
        $dir = app_path('GP247/Templates/ZzKeyRenameProbe/blocks');
        $old = $dir.'/shop_flash_sale.blade.php';
        $new = $dir.'/shop_product_promotion.blade.php';

        File::ensureDirectoryExists($dir);
        File::put($old, '{{-- site override --}}');

        try {
            $this->keyMigration()->up();

            // Without this, the row would resolve to a file the site's own copy
            // does not have and the override would be dropped silently.
            $this->assertFileDoesNotExist($old);
            $this->assertSame('{{-- site override --}}', File::get($new));
        } finally {
            File::deleteDirectory(app_path('GP247/Templates/ZzKeyRenameProbe'));
        }
    }

    /** The gp247/shop upgrade migration seeding the heading. */
    private function languageMigration(): object
    {
        return require base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_09_21_090000_seed_promotion_products_label.php'
        );
    }

    /** The gp247/front upgrade migration renaming the block row's label. */
    private function blockMigration(): object
    {
        return require base_path(
            'vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_09_21_090100_rename_flash_sale_block_label.php'
        );
    }

    /** The gp247/front upgrade migration moving the block key itself. */
    private function keyMigration(): object
    {
        return require base_path(
            'vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_09_22_060000_rename_promotion_block_key.php'
        );
    }

    private function seedBlock(string $name, string $template = 'GP247Front'): FrontLayoutBlock
    {
        return FrontLayoutBlock::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'position' => 'bottom',
            'page' => 'front_home',
            'type' => 'view',
            'text' => 'shop_flash_sale',
            'sort' => 15,
            'status' => 1,
            'template' => $template,
            'store_id' => config('app.storeId') ?: '1',
        ]);
    }
}
