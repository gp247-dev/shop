<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Shop\Tests\TestCase;

/**
 * Storefront extension points: the mechanism that lets a plugin render into a
 * storefront screen without every site hand-editing its template.
 *
 * The failure this guards against is specific: these renderers run inside a
 * product page a shopper is looking at, so one broken plugin must cost its own
 * block and nothing more.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-storefront-plugin-hooks
 * @aidlc-adr front_storefront-plugin-hooks
 */
class PluginHookTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['gp247-config.front.plugin_hooks' => []]);

        parent::tearDown();
    }

    /**
     * A screen that calls a hook nobody registered against renders nothing —
     * which is what lets a template adopt the call before any plugin exists.
     */
    public function test_an_unregistered_hook_renders_nothing(): void
    {
        config(['gp247-config.front.plugin_hooks' => []]);

        $this->assertSame('', gp247_render_plugin_hook('zz_nobody_home', ['product' => null]));
    }

    /**
     * Every registered renderer contributes, in registration order, and each one
     * receives the data the screen handed over.
     */
    public function test_registered_renderers_all_contribute_and_receive_the_screen_data(): void
    {
        config(['gp247-config.front.plugin_hooks' => [
            'zz_hook' => [
                ['key' => 'First', 'callback' => fn (array $data) => '[first:' . ($data['label'] ?? '?') . ']'],
                ['key' => 'Second', 'callback' => fn (array $data) => '[second:' . ($data['label'] ?? '?') . ']'],
            ],
        ]]);

        $this->assertSame(
            '[first:ZZ][second:ZZ]',
            gp247_render_plugin_hook('zz_hook', ['label' => 'ZZ'])
        );
    }

    /**
     * A renderer that throws must not take the page down with it: the other
     * plugins on the same hook still render.
     */
    public function test_a_throwing_renderer_does_not_break_the_page(): void
    {
        config(['gp247-config.front.plugin_hooks' => [
            'zz_hook' => [
                ['key' => 'Broken', 'callback' => function (array $data) {
                    throw new \RuntimeException('ZZ plugin exploded');
                }],
                ['key' => 'Healthy', 'callback' => fn (array $data) => '[healthy]'],
            ],
        ]]);

        $this->assertSame('[healthy]', gp247_render_plugin_hook('zz_hook', []));
    }

    /**
     * A malformed entry is skipped rather than fataling — a plugin can be
     * half-uninstalled (class gone, config row still there).
     */
    public function test_a_non_callable_entry_is_skipped(): void
    {
        config(['gp247-config.front.plugin_hooks' => [
            'zz_hook' => [
                ['key' => 'Gone', 'callback' => ['App\\No\\Such\\Class', 'render']],
                ['key' => 'Healthy', 'callback' => fn (array $data) => '[healthy]'],
            ],
        ]]);

        $this->assertSame('[healthy]', gp247_render_plugin_hook('zz_hook', []));
    }

    /**
     * The shop product-detail screen must actually call the hook — the mechanism
     * is worthless if the shipped template forgot to host it.
     *
     * Asserted against the PACKAGE source, not the published copy: the published
     * copy under app/GP247/Templates is derived output that `vendor:publish`
     * overwrites, so only the package file proves what ships.
     */
    public function test_the_shop_product_detail_screen_hosts_the_hook(): void
    {
        $screen = base_path('vendor/gp247/shop/src/Views/templates/GP247Front/screen/shop_product_detail.blade.php');

        $this->assertFileExists($screen);
        $this->assertStringContainsString(
            "gp247_render_plugin_hook('shop_product_detail_bottom'",
            file_get_contents($screen),
            'The shipped product-detail screen must host the storefront extension point'
        );
    }
}
