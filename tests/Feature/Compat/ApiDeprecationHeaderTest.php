<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A route marked as deprecated tells every caller so in machine-readable headers — when it
 * was deprecated (RFC 9745), when it stops (RFC 8594) and where the migration guide is —
 * on success and error alike. GET logout in v1 keeps the header it always had. Exercised
 * on probe routes declared by this test.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-deprecation-policy
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiDeprecationHeaderTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(GP247_API_MIDDLEWARE)->prefix(trim(GP247_API_V2_FRONT_PREFIX, '/'))->group(function () {
            Route::get('zz-deprecated-probe', fn () => response()->json(['data' => ['ok' => true]]))
                ->middleware('api.deprecated:2026-11-01,2027-12-31,zz-anchor');
            Route::get('zz-deprecated-missing', fn () => abort(404))
                ->middleware('api.deprecated:2026-11-01,2027-12-31,zz-anchor');
            Route::get('zz-deprecated-open', fn () => response()->json(['data' => ['ok' => true]]))
                ->middleware('api.deprecated:2026-11-01');
        });
    }

    public function test_a_deprecated_route_announces_the_date_the_sunset_and_the_guide(): void
    {
        $response = $this->getJson($this->v2Front() . '/zz-deprecated-probe')->assertOk();

        $this->assertSame('@' . gmmktime(0, 0, 0, 11, 1, 2026), $response->headers->get('Deprecation'));
        $this->assertSame('Fri, 31 Dec 2027 00:00:00 GMT', $response->headers->get('Sunset'));
        $link = (string) $response->headers->get('Link');
        $this->assertStringContainsString('#zz-anchor>', $link);
        $this->assertStringContainsString('rel="deprecation"', $link);
    }

    public function test_errors_carry_the_headers_too_and_no_sunset_date_means_no_sunset_header(): void
    {
        $this->getJson($this->v2Front() . '/zz-deprecated-missing')->assertNotFound()->assertHeader('Deprecation');

        $open = $this->getJson($this->v2Front() . '/zz-deprecated-open')->assertOk();
        $open->assertHeader('Deprecation')->assertHeaderMissing('Sunset');
    }

    public function test_v1_get_logout_keeps_its_original_deprecation_header(): void
    {
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->apiCall('GET', $this->frontApi() . '/logout', $token)->assertHeader('Deprecation', 'true');
    }
}
