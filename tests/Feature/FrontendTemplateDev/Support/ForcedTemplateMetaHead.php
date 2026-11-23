<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev\Support;

use GP247\Front\TemplateComponents\MetaHead;

/**
 * Test double that forces which template is "active" for MetaHead,
 * mirroring ForcedTemplateLanguageSwitcher (US-TPL-008, ADR-013 decision 5
 * amend #2) — proves the resolver picks up a
 * Blade-component template override for meta-head the same way it does for
 * the other five cross-cutting components.
 */
class ForcedTemplateMetaHead extends MetaHead
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
