<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Front\Commands\FrontInstall;
use GP247\Front\FrontServiceProvider;
use GP247\Shop\ShopServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use GP247\Shop\Tests\TestCase;

/**
 * The default template is served from the packages that ship it; publishing is
 * how a site opts a file out of that.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-template-vendor-resident
 * @aidlc-adr frontend-template-dev_template-vendor-resident-views
 */
class TemplateVendorResidentTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ($this->assetPaths as $path) {
            $this->deleteTree($path);
        }

        parent::tearDown();
    }

    /** Marker views proving each package's copy of the template is reachable. */
    private const FRONT_MARKER = 'GP247Front/layout.blade.php';
    private const SHOP_MARKER = 'GP247Front/screen/shop_cart.blade.php';

    public function test_storefront_renders_template_views_straight_from_the_packages(): void
    {
        $front = base_path('vendor/gp247/front/src/Views/templates');
        $shop = base_path('vendor/gp247/shop/src/Views/templates');

        // The directory must BE the template name: that is what lets the view
        // finder resolve "GP247TemplatePath::GP247Front.<view>" from vendor.
        $this->assertFileExists($front.'/'.self::FRONT_MARKER);
        $this->assertFileExists($shop.'/'.self::SHOP_MARKER);

        $hints = View::getFinder()->getHints()['GP247TemplatePath'] ?? [];
        $normalized = array_map(
            static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/'),
            $hints
        );

        $this->assertContains(str_replace('\\', '/', $front), $normalized, 'front package must serve its template');
        $this->assertContains(str_replace('\\', '/', $shop), $normalized, 'shop package must serve its half of the template');
    }

    public function test_a_published_file_wins_over_the_package_copy(): void
    {
        $hints = array_map(
            static fn (string $p): string => rtrim(str_replace('\\', '/', $p), '/'),
            View::getFinder()->getHints()['GP247TemplatePath'] ?? []
        );

        $appRoot = rtrim(str_replace('\\', '/', app_path('GP247/Templates')), '/');
        $frontRoot = rtrim(str_replace('\\', '/', base_path('vendor/gp247/front/src/Views/templates')), '/');

        $appIndex = array_search($appRoot, $hints, true);
        $frontIndex = array_search($frontRoot, $hints, true);

        $this->assertIsInt($appIndex, 'app/GP247/Templates must be a source root');
        $this->assertIsInt($frontIndex);
        // Laravel returns the first hint that has the file, so ordering IS the
        // override rule: what a site published must beat the package copy.
        $this->assertLessThan($frontIndex, $appIndex, 'published overrides must be searched before the package');
    }

    public function test_a_custom_template_never_falls_back_to_the_default_template(): void
    {
        // A template name is a path segment, and the package roots only contain a
        // GP247Front directory — so a site template can neither inherit nor be
        // overwritten by GP247Front's layout.
        $this->assertFalse(view()->exists('GP247TemplatePath::AidlcNoSuchTheme.layout'));
        $this->assertFalse(view()->exists('GP247TemplatePath::AidlcNoSuchTheme.screen.shop_cart'));
    }

    public function test_layout_block_options_merge_every_source_of_the_template(): void
    {
        $blocks = array_keys(gp247_template_files('GP247Front', 'blocks', '*.blade.php'));
        $names = array_map(static fn (string $f): string => substr($f, 0, -10), $blocks);

        // 3 shipped by gp247/front + 4 by gp247/shop. Listing only app/ would show
        // none of them once the tree is no longer published.
        foreach (['banner_image', 'email_subscribe', 'welcome'] as $fromFront) {
            $this->assertContains($fromFront, $names);
        }
        foreach (['shop_category_home', 'shop_product_promotion', 'shop_product_home', 'shop_product_last_view'] as $fromShop) {
            $this->assertContains($fromShop, $names);
        }
    }

    public function test_installing_the_template_copies_its_shell_but_not_its_blade(): void
    {
        $this->assertContains('gp247:front-template', FrontInstall::PUBLISH_TAGS);
        $this->assertContains('gp247:front-public', FrontInstall::PUBLISH_TAGS);
        $this->assertNotContains(
            'gp247:front-view',
            FrontInstall::PUBLISH_TAGS,
            'copying the Blade tree on install would freeze the storefront at the installed version'
        );

        $shell = ServiceProvider::pathsToPublish(FrontServiceProvider::class, 'gp247:front-template');
        $this->assertNotEmpty($shell);

        foreach ($shell as $source => $destination) {
            $this->assertStringContainsString('GP247/Templates/GP247Front', str_replace('\\', '/', $destination));
            $entry = basename($source);
            $this->assertContains(
                $entry,
                FrontServiceProvider::TEMPLATE_SHELL_ENTRIES,
                $entry.' is not part of the extension shell and must not be published on install'
            );
        }

        // Everything core needs on disk to discover and boot the template.
        $sources = array_map('basename', array_keys($shell));
        $this->assertContains('AppConfig.php', $sources);
        $this->assertContains('gp247.json', $sources);
    }

    public function test_publish_tags_still_target_the_same_folders(): void
    {
        // Destinations are unchanged, which is what keeps already-published sites
        // working exactly as before.
        foreach (['gp247:front-view', 'gp247:front-public'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(FrontServiceProvider::class, $tag);
            $this->assertNotEmpty($paths, $tag.' must still exist for sites that want the whole tree');
            foreach ($paths as $destination) {
                $this->assertStringContainsString('GP247/Templates/GP247Front', str_replace('\\', '/', $destination));
            }
        }

        $shopPaths = ServiceProvider::pathsToPublish(ShopServiceProvider::class, 'gp247:shop-view-front');
        $this->assertNotEmpty($shopPaths);
        foreach ($shopPaths as $source => $destination) {
            $this->assertStringContainsString('Views/templates/GP247Front', str_replace('\\', '/', $source));
            $this->assertStringContainsString('GP247/Templates/GP247Front', str_replace('\\', '/', $destination));
        }
    }

    public function test_tailwind_build_scans_the_packages_not_only_the_published_copy(): void
    {
        $config = file_get_contents(
            base_path('vendor/gp247/front/src/Views/templates/GP247Front/resources/assets/tailwind.config.js')
        );

        // Globs that only matched app/ produced an almost empty stylesheet once the
        // tree stopped being published — a broken storefront with no error message.
        $this->assertStringContainsString('vendor/gp247/front/src/Views/templates/GP247Front/**/*.blade.php', $config);
        $this->assertStringContainsString('vendor/gp247/shop/src/Views/templates/GP247Front/**/*.blade.php', $config);
        $this->assertStringContainsString('app/GP247/Templates/GP247Front/**/*.blade.php', $config);
    }

    /**
     * A storefront build ships with `composer update`, but a browser cannot read
     * vendor/ — so the compiled CSS/JS has to reach public/. Copying it only when
     * absent left every existing site on its old stylesheet: new markup from the
     * package, no matching rules in the bundle, elements silently unstyled.
     *
     * @return void
     */
    public function test_a_new_storefront_build_replaces_the_published_assets(): void
    {
        [$source, $target] = $this->assetFixture();

        // First run: nothing published yet.
        $this->assertTrue($this->syncAssets($source, $target));
        $this->assertSame('BUILD-1', file_get_contents($target.'/css/app.css'));

        // Steady state: same build, no work.
        $this->assertFalse($this->syncAssets($source, $target));

        // The package ships a new build (composer rewrites the file + its mtime).
        file_put_contents($source.'/css/app.css', 'BUILD-2-longer');
        touch($source.'/css/app.css', time() + 60);

        $this->assertTrue($this->syncAssets($source, $target));
        $this->assertSame(
            'BUILD-2-longer',
            file_get_contents($target.'/css/app.css'),
            'the published stylesheet must follow the package build'
        );
    }

    /**
     * The refresh must not depend on the stylesheet alone — every file of the
     * build travels with it.
     *
     * @return void
     */
    public function test_the_whole_asset_folder_travels_with_the_build(): void
    {
        [$source, $target] = $this->assetFixture();
        $this->syncAssets($source, $target);

        file_put_contents($source.'/css/app.css', 'BUILD-2');
        file_put_contents($source.'/js/app.js', 'JS-2');
        touch($source.'/css/app.css', time() + 60);

        $this->syncAssets($source, $target);

        $this->assertSame('JS-2', file_get_contents($target.'/js/app.js'));
    }

    /**
     * Create a throwaway "package public/" + its published mirror.
     *
     * @return array{0: string, 1: string} [source, target]
     */
    private function assetFixture(): array
    {
        $source = storage_path('framework/testing/aidlc-assets-src');
        $target = storage_path('framework/testing/aidlc-assets-pub');

        $this->deleteTree($source);
        $this->deleteTree($target);

        mkdir($source.'/css', 0777, true);
        mkdir($source.'/js', 0777, true);
        file_put_contents($source.'/css/app.css', 'BUILD-1');
        file_put_contents($source.'/js/app.js', 'JS-1');

        $this->assetPaths = [$source, $target];

        return [$source, $target];
    }

    /** @var array<int, string> Fixture folders to clean up. */
    private array $assetPaths = [];

    /**
     * Invoke the provider's asset sync against the fixture folders.
     *
     * @param string $source Fixture package folder.
     * @param string $target Fixture published folder.
     * @return bool Whether files were (re)copied.
     */
    private function syncAssets(string $source, string $target): bool
    {
        $provider = new FrontServiceProvider($this->app);

        $method = (new \ReflectionObject($provider))->getMethod('syncTemplateAssets');
        $method->setAccessible(true);

        return (bool) $method->invoke($provider, $source, $target);
    }

    /**
     * Delete a directory tree if it exists.
     *
     * @param string $path Absolute directory path.
     * @return void
     */
    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.'/'.$entry;
            is_dir($child) ? $this->deleteTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
