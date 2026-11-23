<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev\Support;

use GP247\Shop\Tests\TestCase;

/**
 * Shared structural checks for "does this template have real content for every
 * data-map screen" (MC-016). Concrete subclasses only need to name the template
 * directory under app/GP247/Templates/<Template>.
 *
 * Corrected 2026-07-02: `Default` is split
 * across 2 packages —
 *  - `gp247/front` (F01-F04) has NO fallback; controllers hardcode
 *    `GP247TemplatePath::<Active>.screen.*`, so these MUST exist locally.
 *  - `gp247/shop` (S01-S09/A01-A07/U01-U04 + their shared partials/emails) HAS
 *    a fallback via `gp247_shop_process_view()`: local override first, else
 *    `gp247-shop-front::*` (the package's own default view, which itself
 *    `@extends($GP247TemplatePath.'.layout')` so it still renders inside the
 *    active template's own shell/CSS). These screens do NOT need a local
 *    file — only a successful *resolution* (override-or-fallback).
 * The previous version of this test required every screen to exist as a
 * local file, which was factually wrong for `Default` and already failing.
 *
 * Mitigates RISK-MAINT-003/RISK-MAINT-006: the 27+ Blade files added earlier
 * shipped with zero test coverage,
 * and a later vendor rename (`gp247/shop` commits 66ba7a4/25edcc0) silently
 * broke `Default`'s Livewire overrides with nothing to catch it.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-006, US-TPL-007
 */
abstract class TemplateContentCompletenessTestCase extends TestCase
{
    abstract protected function templateName(): string;

    /** Screens owned by `gp247/front` (F01-F04) — no package fallback, MUST exist locally. */
    private const FRONT_SCREENS = [
        'screen/home.blade.php',
        'screen/page_detail.blade.php',
        'screen/front_search.blade.php',
        'screen/404.blade.php',
    ];

    /**
     * Screens/partials/emails owned by `gp247/shop` — resolved via
     * gp247_shop_process_view(): local override key => package default
     * namespace + subPath. A local file is OPTIONAL as long as resolution
     * succeeds (either the override or the vendor's own default renders).
     */
    private const SHOP_RESOLVABLE = [
        'screen.shop_product_list' => 'gp247-shop-front',
        'screen.shop_product_detail' => 'gp247-shop-front',
        'screen.shop_search' => 'gp247-shop-front',
        'screen.shop_cart' => 'gp247-shop-front',
        'screen.shop_checkout' => 'gp247-shop-front',
        // screen.shop_checkout_confirm is deliberately absent: confirm is step 4 of
        // the CheckoutWizard Livewire component rendered by screen.shop_checkout, so
        // no separate screen exists for GP247Front. A template MAY still ship one —
        // the confirm route renders it when present and falls back to the wizard when
        // not (ShopCartController::_getCheckoutConfirm). Requiring it here asserted a
        // screen the 2.0 port intentionally dropped.
        'screen.shop_order_success' => 'gp247-shop-front',
        'screen.shop_wishlist' => 'gp247-shop-front',
        'screen.shop_compare' => 'gp247-shop-front',
        'account.shop_index' => 'gp247-shop-front',
        'account.shop_order_list' => 'gp247-shop-front',
        'account.shop_order_detail' => 'gp247-shop-front',
        'account.shop_address_list' => 'gp247-shop-front',
        'account.shop_update_address' => 'gp247-shop-front',
        'account.shop_change_infomation' => 'gp247-shop-front',
        'account.shop_change_password' => 'gp247-shop-front',
        'account.shop_verify' => 'gp247-shop-front',
        'auth.shop_login' => 'gp247-shop-front',
        'auth.shop_register' => 'gp247-shop-front',
        'auth.shop_forgot' => 'gp247-shop-front',
        'auth.shop_reset' => 'gp247-shop-front',
        'account.shop_layout' => 'gp247-shop-front',
        'account.shop_nav_customer' => 'gp247-shop-front',
        'common.shop_show_price' => 'gp247-shop-front',
        'common.shop_show_price_detail' => 'gp247-shop-front',
        'common.shop_render_attribute' => 'gp247-shop-front',
        // shop_render_total / shop_checkout_list were partials OF the 1.x confirm
        // screen and went with it in the core 2.0 port; nothing in core/front/shop or
        // app/ includes either name any more. Kept out for the same reason as
        // screen.shop_checkout_confirm above — the wizard's confirm step renders the
        // totals and the line list itself.
        'common.shop_display_store' => 'gp247-shop-front',
        'email.shop_layout' => 'gp247-shop-front',
        'email.shop_welcome_customer' => 'gp247-shop-front',
        'email.shop_customer_verify' => 'gp247-shop-front',
        'email.shop_forgot_password' => 'gp247-shop-front',
        'email.shop_order_success_to_customer' => 'gp247-shop-front',
        'email.shop_order_success_to_admin' => 'gp247-shop-front',
    ];

    /** Common partials owned by `gp247/front` itself (F01-F04's dependencies) — no fallback, MUST exist locally. */
    private const FRONT_COMMON_PARTIALS = [
        'common/item_single.blade.php',
        'common/pagination.blade.php',
        'common/pagination_result.blade.php',
        'common/render_form_custom_field.blade.php',
    ];

    /**
     * Livewire override views resolved by BaseFrontComponent (ADR-011).
     * Intentionally local-only (no fallback checked here) — these ARE the
     * override proof this Unit's AC requires; a silent fallback here would
     * mean the template lost its customization (RISK-MAINT-006).
     */
    private const LIVEWIRE_VIEWS = [
        'livewire/shop_cart-manager.blade.php',
        'livewire/shop_checkout-wizard.blade.php',
        'livewire/shop_product-filter.blade.php',
    ];

    /**
     * Absolute path of one template file, resolved the way rendering resolves
     * it: the first source root that has it (published override under app/,
     * then each package's own copy).
     *
     * WHY not base_path('app/GP247/Templates/...') any more: a template's Blade is served from the package that
     * ships it and only lands under app/ when the site publishes that file to
     * edit it. Asserting on app/ would fail on every site that never published
     * — including a fresh install.
     *
     * @param string $relative Path inside the template, e.g. "screen/home.blade.php".
     * @return string Absolute path, or the app/ path when nothing provides it (so
     *         assertFileExists reports the place a reader expects to look).
     */
    protected function templateFile(string $relative): string
    {
        foreach (\GP247\Core\Support\TemplateSourceAudit::roots() as $root) {
            $candidate = $root . '/' . $this->templateName() . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path('app/GP247/Templates/' . $this->templateName() . '/' . $relative);
    }

    /**
     * F01-F04 have a real local Blade file — `gp247/front` has no fallback
     * (MC-016).
     */
    public function test_front_screens_have_blade_files(): void
    {
        foreach (self::FRONT_SCREENS as $relative) {
            $this->assertFileExists(
                $this->templateFile($relative),
                "{$this->templateName()} is missing front-package screen [{$relative}] (MC-016) — gp247/front has no fallback"
            );
        }
    }

    /**
     * F01-F04 resolve through the real GP247TemplatePath namespace used by
     * controllers at render time — not just present on disk.
     */
    public function test_front_screens_resolve_as_views(): void
    {
        foreach (self::FRONT_SCREENS as $relative) {
            $key = str_replace(['/', '.blade.php'], ['.', ''], $relative);

            $this->assertTrue(
                view()->exists("GP247TemplatePath::{$this->templateName()}.{$key}"),
                "GP247TemplatePath::{$this->templateName()}.{$key} does not resolve as a view"
            );
        }
    }

    /**
     * S01-S09/A01-A07/U01-U04 + their shared partials/emails must resolve —
     * via a local override OR gp247_shop_process_view()'s fallback to the
     * package's own default view (MC-016, corrected).
     */
    public function test_shop_screens_resolve_via_override_or_fallback(): void
    {
        foreach (self::SHOP_RESOLVABLE as $subPath => $defaultNamespace) {
            $localKey = "GP247TemplatePath::{$this->templateName()}.{$subPath}";
            $fallbackKey = "{$defaultNamespace}::{$subPath}";

            $this->assertTrue(
                view()->exists($localKey) || view()->exists($fallbackKey),
                "{$subPath} does not resolve for {$this->templateName()} via local override [{$localKey}] or fallback [{$fallbackKey}]"
            );
        }
    }

    /**
     * Common partials required by F01-F04 (no fallback, must exist locally).
     */
    public function test_front_common_partials_exist(): void
    {
        foreach (self::FRONT_COMMON_PARTIALS as $relative) {
            $this->assertFileExists(
                $this->templateFile($relative),
                "{$this->templateName()} is missing required front partial [{$relative}]"
            );
        }
    }

    /**
     * The 3 headless components (ADR-011) have a template-specific override
     * view — proves the resolver has real HTML to pick up for this template,
     * and catches the RISK-MAINT-006 class of bug (vendor renames the
     * default view key, template's override silently orphaned).
     */
    public function test_livewire_override_views_exist_and_resolve(): void
    {
        foreach (self::LIVEWIRE_VIEWS as $relative) {
            $this->assertFileExists($this->templateFile($relative));

            $key = str_replace(['/', '.blade.php'], ['.', ''], $relative);
            $this->assertTrue(
                view()->exists("GP247TemplatePath::{$this->templateName()}.{$key}"),
                "GP247TemplatePath::{$this->templateName()}.{$key} does not resolve as a view"
            );
        }
    }

    /**
     * Screen files must stay presentation-only: no direct DB/model-write calls
     * embedded in the view (business logic belongs in controllers/components,
     * not Blade — see Unit boundary in frontend-template-dev.md). Checked for
     * whichever screens this template actually keeps locally.
     */
    public function test_screens_contain_no_embedded_business_logic(): void
    {
        $forbidden = ['DB::table(', '::create(', '::insert(', '::update(', '::delete(', 'Schema::'];

        $localRelatives = array_merge(
            self::FRONT_SCREENS,
            array_map(
                fn (string $subPath) => str_replace('.', '/', $subPath) . '.blade.php',
                array_keys(self::SHOP_RESOLVABLE)
            )
        );

        foreach ($localRelatives as $relative) {
            $path = $this->templateFile($relative);
            if (!file_exists($path)) {
                continue;
            }

            $content = file_get_contents($path);
            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString(
                    $pattern,
                    $content,
                    "{$relative} embeds business logic ('{$pattern}') — belongs in a controller/component, not the template view"
                );
            }
        }
    }
}
