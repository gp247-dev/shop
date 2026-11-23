<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Front\Admin\Livewire\BannerList;
use GP247\Shop\Admin\Livewire\ProductManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * A developer can override any screen, storefront page, API endpoint or webhook
 * of gp247/core, gp247/front and gp247/shop by dropping a same-path subclass into
 * app/GP247/ — without touching vendor/.
 *
 * WHY a runtime guard over every registered route (not a hand-written list): the
 * seam only works when each route calls gp247_namespace(); a route written as a
 * bare `X::class` keeps working, so nothing else ever turns red. That is exactly
 * how 28 front/shop admin screens silently lost overriding in the 2026-07-06
 * cutover (RISK-MAINT-override-seam-silent-regression).
 *
 * Probe files are real subclasses of the vendor class (never empty stubs) so a
 * probe left behind by a crashed run changes no behaviour on the dev site; every
 * file/directory the test creates is removed in finally + on shutdown.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-developer-route-override
 * @aidlc-adr compat-foundation_developer-override-seam
 */
class DeveloperRouteOverrideTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** Marker line written into every probe so cleanup never deletes a real override. */
    private const PROBE_MARKER = '// gp247-override-probe: DeveloperRouteOverrideTest (safe to delete)';

    /**
     * Closed exception list of NFR-MAINT-developer-override-seam: route targets that
     * are deliberately not resolved through the seam.
     *
     * @var string[]
     */
    private const ALLOWLIST = [
        // Empty since the web installer (InstallerWizard, registered before the helpers
        // load) was removed.
    ];

    /** @var string[] Absolute paths of probe files created by this test. */
    private array $createdFiles = [];

    /** @var string[] Absolute paths of directories created by this test (deepest first on cleanup). */
    private array $createdDirs = [];

    protected function tearDown(): void
    {
        $this->removeProbes();

        parent::tearDown();
    }

    public function test_every_package_route_switches_to_the_app_override_when_one_exists(): void
    {
        $vendorClasses = $this->vendorRouteTargets();
        $this->assertNotEmpty($vendorClasses, 'No GP247 route targets found — route loading is broken.');

        foreach ($vendorClasses as $class) {
            $this->writeProbe($class);
        }
        $this->refreshApplication();

        $stillVendor = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $class = $this->targetClass($route->getActionName());
            if ($class !== null && in_array($class, $vendorClasses, true)) {
                $stillVendor[] = ($route->getName() ?? $route->uri()) . ' => ' . $class;
            }
        }

        $this->assertSame(
            [],
            $stillVendor,
            "These routes ignore the developer override (wrap the target in gp247_namespace()):\n" . implode("\n", $stillVendor)
        );
    }

    public function test_every_package_controller_route_points_to_an_existing_method(): void
    {
        // WHY: an override subclass inherits the vendor method set, so a route whose
        // method was renamed away is broken for overridden and stock sites alike.
        $missing = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_contains($action, '@') || !preg_match('/^\\\\?GP247\\\\(Core|Front|Shop)\\\\/', $action)) {
                continue;
            }
            [$class, $method] = explode('@', ltrim($action, '\\'));
            if (!method_exists($class, $method)) {
                $missing[] = implode('|', $route->methods()) . ' ' . $route->uri() . ' => ' . $action;
            }
        }

        $this->assertSame([], $missing, "Routes target methods that do not exist:\n" . implode("\n", $missing));
    }

    public function test_overridden_front_and_shop_admin_screens_run_on_the_override_class(): void
    {
        foreach ([BannerList::class => 'admin_banner', ProductManager::class => 'admin_product'] as $vendorClass => $slug) {
            $override = $this->writeProbe($vendorClass);
            $this->assertTrue(class_exists($override), "Probe {$override} must autoload from app/GP247/.");

            // WHY: livewire/update only carries the component name; it must resolve back
            // to the override class or every action after mount would run vendor code.
            $finder = app('livewire.finder');
            $this->assertSame($override, $finder->resolveClassComponentClassName($finder->normalizeName($override)));

            $this->asUser(slugs: [$slug]);
            $component = Livewire::test($override);
            $this->assertInstanceOf($override, $component->instance());
            $this->assertInstanceOf($vendorClass, $component->instance());
        }
    }

    public function test_package_code_never_instantiates_a_package_controller_directly(): void
    {
        $controllers = $this->packageControllerClasses();
        $this->assertNotEmpty($controllers);

        $offenders = [];
        foreach ($this->scannedSourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            foreach ($controllers as $fqcn) {
                $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
                $importsIt = preg_match('/^use\s+\\\\?' . preg_quote($fqcn, '/') . '\s*;/m', $source) === 1;
                $sameNamespace = preg_match('/^namespace\s+' . preg_quote(substr($fqcn, 0, strrpos($fqcn, '\\')), '/') . '\s*;/m', $source) === 1;

                $patterns = ['/new\s+\\\\?' . preg_quote($fqcn, '/') . '\s*[(;)]/'];
                if ($importsIt || $sameNamespace) {
                    $patterns[] = '/new\s+' . preg_quote($short, '/') . '\s*[(;)]/';
                }
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $source)) {
                        $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file) . ' → new ' . $short;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($offenders)),
            "Resolve package controllers through gp247_namespace() (or \$this) instead of `new`:\n" . implode("\n", $offenders)
        );
    }

    /**
     * Vendor classes targeted by registered routes, minus the allowlist.
     *
     * @return string[]
     */
    private function vendorRouteTargets(): array
    {
        $classes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $class = $this->targetClass($route->getActionName());
            if ($class !== null
                && preg_match('/^GP247\\\\(Core|Front|Shop)\\\\/', $class)
                && !in_array($class, self::ALLOWLIST, true)) {
                $classes[$class] = true;
            }
        }

        return array_keys($classes);
    }

    /**
     * Class part of a route action name, or null for closures.
     *
     * @param string $action Route action name, e.g. "X@method", "X" or "Closure".
     * @return string|null
     */
    private function targetClass(string $action): ?string
    {
        if ($action === 'Closure') {
            return null;
        }

        return ltrim(explode('@', $action)[0], '\\');
    }

    /**
     * Write a same-path subclass of $vendorClass under app/GP247/ unless a real
     * override already exists there.
     *
     * @param string $vendorClass Fully-qualified GP247\* class.
     * @return string The override FQCN (App\GP247\...).
     */
    private function writeProbe(string $vendorClass): string
    {
        $relative = substr($vendorClass, strlen('GP247\\'));
        $path = app_path('GP247/' . str_replace('\\', '/', $relative) . '.php');
        $override = 'App\\GP247\\' . $relative;

        if (file_exists($path)) {
            return $override;
        }

        $this->makeDirectory(dirname($path));
        $namespace = substr($override, 0, strrpos($override, '\\'));
        $short = substr($override, strrpos($override, '\\') + 1);
        file_put_contents($path, "<?php\n" . self::PROBE_MARKER . "\nnamespace {$namespace};\n\nclass {$short} extends \\{$vendorClass}\n{\n}\n");
        $this->createdFiles[] = $path;

        if (count($this->createdFiles) === 1) {
            // WHY: a fatal error skips tearDown(); a leftover probe is harmless (it only
            // extends the vendor class) but should still not litter the dev tree.
            register_shutdown_function(fn () => $this->removeProbes());
        }

        return $override;
    }

    /**
     * mkdir -p that records every directory it actually creates.
     *
     * @param string $dir Absolute directory path.
     * @return void
     */
    private function makeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        $this->makeDirectory(dirname($dir));
        mkdir($dir);
        $this->createdDirs[] = $dir;
    }

    /**
     * Remove only the probe files and directories this test created.
     *
     * @return void
     */
    private function removeProbes(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file) && str_contains((string) file_get_contents($file), self::PROBE_MARKER)) {
                unlink($file);
            }
        }
        foreach (array_reverse($this->createdDirs) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                rmdir($dir);
            }
        }
        $this->createdFiles = [];
        $this->createdDirs = [];
    }

    /**
     * Controller classes shipped by the three packages (storefront, admin, API).
     *
     * @return string[]
     */
    private function packageControllerClasses(): array
    {
        $classes = [];
        foreach (['core', 'front', 'shop'] as $package) {
            $src = base_path("vendor/gp247/{$package}/src");
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', $file->getPathname());
                if (!str_ends_with($path, 'Controller.php') || str_contains($path, '/Format/')) {
                    continue;
                }
                $source = (string) file_get_contents($path);
                if (preg_match('/^namespace\s+(GP247\\\\[^;]+);/m', $source, $ns)
                    && preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $source, $cls)
                    && !str_starts_with($cls[0], 'abstract')) {
                    $classes[] = $ns[1] . '\\' . $cls[1];
                }
            }
        }

        return $classes;
    }

    /**
     * PHP sources that may call package controllers: the three packages and the
     * first-party plugins/templates under app/GP247.
     *
     * @return string[]
     */
    private function scannedSourceFiles(): array
    {
        $roots = [
            base_path('vendor/gp247/core/src'),
            base_path('vendor/gp247/front/src'),
            base_path('vendor/gp247/shop/src'),
            app_path('GP247/Plugins'),
            app_path('GP247/Templates'),
        ];
        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = $file->getPathname();
                $normalized = str_replace('\\', '/', substr($path, strlen($root)));
                // WHY: skip scaffolding templates and third-party code bundled inside plugins.
                if (str_ends_with($path, '.php')
                    && !str_contains($normalized, '/Format/')
                    && !str_contains($normalized, '/vendor/')
                    && !str_contains($normalized, '/.git/')
                    && !str_contains($normalized, '/node_modules/')) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }
}
