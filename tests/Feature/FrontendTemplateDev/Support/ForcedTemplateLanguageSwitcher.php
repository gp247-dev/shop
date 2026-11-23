<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev\Support;

use GP247\Front\TemplateComponents\LanguageSwitcher;

/**
 * Test double that forces which template is "active" for LanguageSwitcher,
 * mirroring Support\ForcedTemplateProductFilter for the Livewire case
 * (US-TPL-008, ADR-013) — proves the resolver picks up a Blade-component
 * template override the same way it does for Livewire components.
 */
class ForcedTemplateLanguageSwitcher extends LanguageSwitcher
{
    public static string $forcedTemplate = 'GP247Front';

    protected function activeTemplateName(): string
    {
        return self::$forcedTemplate;
    }

    /** Expose the protected resolver for test assertions. */
    public function resolvedViewForTest(): string
    {
        return $this->resolveTemplateViewFor($this->templateViewKey(), $this->defaultViewNamespace());
    }
}
