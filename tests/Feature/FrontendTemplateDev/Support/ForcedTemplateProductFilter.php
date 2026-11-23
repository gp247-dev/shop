<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev\Support;

use GP247\Shop\Front\Livewire\ProductFilter;

/**
 * Test double that forces which template is "active" for ProductFilter,
 * without needing a database-backed store fixture.
 *
 * This is a TEST-ONLY helper — production code never overrides
 * activeTemplateName(); the real value comes from gp247_store_info('template').
 * It exists to prove MC-015 (creating/activating a template requires no PHP
 * change in vendor/gp247/*) by isolating the one seam BaseFrontComponent
 * exposes for testability.
 */
class ForcedTemplateProductFilter extends ProductFilter
{
    public static string $forcedTemplate = 'GP247Front';

    protected function activeTemplateName(): string
    {
        return self::$forcedTemplate;
    }
}
