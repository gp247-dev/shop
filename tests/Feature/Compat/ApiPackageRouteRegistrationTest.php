<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Support\ApiRouteRegistrar;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\TestCase;

/**
 * Each package registers its REST API routes through the core seam: core no longer
 * reaches into the folders of shop/front, routes of a package exist only once that
 * package is installed in the database, API mode off registers nothing, and a route
 * file registered through the seam gets the shared API middleware.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-package-route-registration
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiPackageRouteRegistrationTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'gp247api') . '.php';
        file_put_contents($this->file, "<?php\nIlluminate\\Support\\Facades\\Route::get('api/front/zz-seam-probe-" . uniqid() . "', fn () => 'ok')->name('zz.seam.probe');\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_core_no_longer_loads_api_route_files_of_other_packages(): void
    {
        $source = file_get_contents(base_path('vendor/gp247/core/src/routes.php'));

        $this->assertStringNotContainsString('../../shop/src/Routes/Api', $source);
        $this->assertStringNotContainsString('../../front/src/Routes/Api', $source);
    }

    public function test_a_route_file_registered_through_the_seam_gets_the_shared_api_middleware(): void
    {
        ApiRouteRegistrar::register($this->file);
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName('zz.seam.probe');
        $this->assertNotNull($route);
        foreach (GP247_API_MIDDLEWARE as $middleware) {
            $this->assertContains($middleware, $route->middleware());
        }
    }

    public function test_nothing_is_registered_while_the_package_is_not_installed(): void
    {
        ApiRouteRegistrar::register($this->file, 'zz_migration_that_never_ran');
        Route::getRoutes()->refreshNameLookups();

        $this->assertNull(Route::getRoutes()->getByName('zz.seam.probe'));
    }

    public function test_nothing_is_registered_when_api_mode_is_off(): void
    {
        config(['gp247-config.env.GP247_API_MODE' => 0]);

        ApiRouteRegistrar::register($this->file);
        Route::getRoutes()->refreshNameLookups();

        $this->assertNull(Route::getRoutes()->getByName('zz.seam.probe'));
    }

    public function test_shop_and_front_api_routes_are_still_registered_at_the_same_paths(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->all();

        foreach (['api/core/order/list', 'api/front/product/list', 'api/core/banner/list', 'api/front/page/list', 'api/front/member/order/list'] as $uri) {
            $this->assertContains($uri, $uris);
        }
    }
}
