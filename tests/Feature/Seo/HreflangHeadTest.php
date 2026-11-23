<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminLanguage;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\TestCase;

/**
 * hreflang in <head> is generated from the route being rendered, with no per-controller
 * wiring.
 *
 * WHY separate processes: GP247_SEO_LANG is a PHP constant defined once at boot from
 * config, and the suite runs with it off (tests/phpunit.xml). Each test here boots a
 * fresh process with the environment variable switched on before the application is
 * created, so the rest of the suite is unaffected.
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-003
 * @aidlc-adr ADR-seo-indexing-signals
 */
#[RunTestsInSeparateProcesses]
class HreflangHeadTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;

    /**
     * Turn the language prefix on for this process, then boot and activate the store.
     */
    protected function setUp(): void
    {
        putenv('GP247_SEO_LANG=1');
        $_ENV['GP247_SEO_LANG'] = '1';
        $_SERVER['GP247_SEO_LANG'] = '1';

        parent::setUp();
        (new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore'))->setValue(null, []);
        $this->setUpFrontStore();
    }

    /**
     * Restore the store's active flag.
     */
    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Active language codes; the test needs at least two to mean anything.
     *
     * @return array<int, string>
     */
    private function activeLanguages(): array
    {
        $codes = array_keys(AdminLanguage::getListActive()->all());
        $this->assertGreaterThanOrEqual(2, count($codes), 'The test DB must have at least two active languages.');

        return $codes;
    }

    public function test_every_active_language_gets_an_alternate_link_plus_x_default(): void
    {
        $this->assertTrue(GP247_SEO_LANG == 1, 'GP247_SEO_LANG must be on in this process.');
        $codes = $this->activeLanguages();

        $response = $this->get(gp247_route_front('product.all'));

        $response->assertOk();
        foreach ($codes as $code) {
            $url = gp247_route_front('product.all', ['lang' => $code]);
            $response->assertSee('<link rel="alternate" hreflang="' . $code . '" href="' . $url . '" />', escape: false);
        }
        $response->assertSee('hreflang="x-default"', escape: false);
    }

    public function test_x_default_points_at_the_store_default_language(): void
    {
        $this->activeLanguages();
        $default = (string) gp247_store_info('language');

        $response = $this->get(gp247_route_front('product.all'));

        // WHY assertOk first: on a 500 assertSee prints the whole debug page, whose inlined renderer script
        // holds U+FFFF — invalid in XML, so paratest could not merge the junit report.
        $response->assertOk();
        $response->assertSee(
            '<link rel="alternate" hreflang="x-default" href="' . gp247_route_front('product.all', ['lang' => $default]) . '" />',
            escape: false
        );
    }

    public function test_a_noindex_page_has_no_hreflang(): void
    {
        $this->activeLanguages();

        $this->get(gp247_route_front('cart'))->assertOk()->assertDontSee('hreflang=', escape: false);
    }

    public function test_a_route_without_a_language_segment_has_no_hreflang(): void
    {
        $this->activeLanguages();

        $this->get('/')->assertOk()->assertDontSee('hreflang=', escape: false);
    }
}
