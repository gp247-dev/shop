<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Front\FrontServiceProvider;
use GP247\Shop\ShopServiceProvider;
use Illuminate\Support\ServiceProvider;
use GP247\Shop\Tests\TestCase;

/**
 * `Default` was removed entirely (ADR-014
 * Amend #1) — the 3 publish tags that used to seed a fresh install with
 * `Default` now seed it with `GP247Front` instead: same tag names, new
 * source content (ported from `app/GP247/Templates/GP247Front/`) and new
 * destination folder name.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-009
 * @aidlc-adr ADR-014
 */
class PublishTargetsGp247FrontTest extends TestCase
{
    public function test_front_view_and_public_tags_publish_to_gp247front_folder(): void
    {
        $paths = ServiceProvider::pathsToPublish(FrontServiceProvider::class, 'gp247:front-view');
        $this->assertNotEmpty($paths);
        foreach ($paths as $destination) {
            $this->assertStringContainsString('GP247/Templates/GP247Front', $destination);
            $this->assertStringNotContainsString('GP247/Templates/Default', $destination);
        }

        $publicPaths = ServiceProvider::pathsToPublish(FrontServiceProvider::class, 'gp247:front-public');
        $this->assertNotEmpty($publicPaths);
        foreach ($publicPaths as $destination) {
            $this->assertStringContainsString('GP247/Templates/GP247Front', $destination);
        }
    }

    public function test_shop_view_front_tag_publishes_to_gp247front_folder(): void
    {
        $paths = ServiceProvider::pathsToPublish(ShopServiceProvider::class, 'gp247:shop-view-front');
        $this->assertNotEmpty($paths);
        foreach ($paths as $destination) {
            $this->assertStringContainsString('GP247/Templates/GP247Front', $destination);
            $this->assertStringNotContainsString('GP247/Templates/Default', $destination);
        }
    }

    public function test_front_view_scaffold_source_is_populated_not_empty(): void
    {
        $source = base_path('vendor/gp247/front/src/Views/templates/GP247Front');

        $this->assertTrue(is_dir($source));
        $this->assertNotEmpty(glob($source.'/*'));
        $this->assertFileExists($source.'/layout.blade.php');
        $this->assertFileExists($source.'/gp247_components/language-switcher.blade.php');
        // Shop-domain screens must NOT leak into the front package's scaffold.
        $this->assertFileDoesNotExist($source.'/screen/shop_cart.blade.php');
    }

    public function test_shop_view_front_scaffold_source_is_populated_not_empty(): void
    {
        $source = base_path('vendor/gp247/shop/src/Views/templates/GP247Front');

        $this->assertTrue(is_dir($source));
        $this->assertFileExists($source.'/screen/shop_cart.blade.php');
        $this->assertFileExists($source.'/livewire/shop_cart-manager.blade.php');
        // Front-domain (non-shop) screens must NOT leak into the shop package's scaffold.
        $this->assertFileDoesNotExist($source.'/screen/home.blade.php');
    }

    public function test_front_public_scaffold_keeps_shared_sweetalert_asset(): void
    {
        // WHY: ensureSharedAssetsPublished() copies this file on every boot
        // regardless of active template (ADR-013) — must survive the asset swap.
        $this->assertFileExists(base_path('vendor/gp247/front/src/public/js/sweetalert2.all.min.js'));
    }
}
