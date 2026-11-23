<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Shop\Tests\Feature\FrontendTemplateDev\Support\TemplateContentCompletenessTestCase;

/**
 * Proves MC-016 for the `GP247Front` template: every data-map screen has real
 * Blade content or resolves via the gp247_shop_process_view() fallback
 * (US-TPL-009, Phase 6 — the final account/auth screens A01-A07/U01-U04).
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-009
 */
class GP247FrontTemplateContentTest extends TemplateContentCompletenessTestCase
{
    protected function templateName(): string
    {
        return 'GP247Front';
    }
}
