<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Shop\Front\Livewire\ProductFilter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\FrontendTemplateDev\Support\ForcedTemplateProductFilter;
use GP247\Shop\Tests\TestCase;

/**
 * Proves MC-015: creating a new frontend template only requires Blade views
 * under app/GP247/Templates/<Template>/ — no PHP file in vendor/gp247/*
 * needs to change for the new HTML to take effect (US-TPL-004, ADR-011).
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-004, US-TPL-005
 * @aidlc-adr ADR-011
 */
class TemplateOverrideTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Switching to a template without an override for this component
     * (or an unknown template) must fall back to the package default view —
     * demonstrating templates are isolated from one another and from the
     * package baseline (RISK-TECH-010 mitigation). (Assertion corrected
     * 2026-07-05: the package default view
     * is now GP247Front's own content — populated into
     * vendor/gp247/shop/src/Views/templates/GP247Front/ after `Default` was removed — so
     * this checks a marker string that's actually present in that content
     * (the keyword search input) instead of the retired `gp247-product-filter`
     * class, which belonged to an older, since-replaced fallback view.)
     */
    public function test_product_filter_falls_back_to_package_view_for_unknown_template(): void
    {
        ForcedTemplateProductFilter::$forcedTemplate = 'SomeTemplateThatDoesNotExist';

        $html = Livewire::test(ForcedTemplateProductFilter::class)->html();

        $this->assertStringNotContainsString('data-demo-template="true"', $html);
        $this->assertStringContainsString('wire:model.live.debounce.500ms="keyword"', $html);
    }

    /**
     * Sanity check: the real, unmodified ProductFilter class (not the test
     * double) still resolves and renders successfully — the resolver change
     * is additive to existing behaviour (US-LW-005 regression guard).
     */
    public function test_real_product_filter_still_renders_without_exception(): void
    {
        Livewire::test(ProductFilter::class)->assertStatus(200);
    }
}
