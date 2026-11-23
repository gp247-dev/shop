<?php

namespace GP247\Shop\Tests\Feature\FrontendTemplateDev;

use GP247\Front\Livewire\BaseFrontComponent;
use GP247\Front\Support\ResolvesTemplateView;
use GP247\Front\TemplateComponents\BaseFrontViewComponent;
use GP247\Front\TemplateComponents\Breadcrumb;
use GP247\Front\TemplateComponents\CurrencySwitcher;
use GP247\Front\TemplateComponents\FooterLinks;
use GP247\Front\TemplateComponents\LanguageSwitcher;
use GP247\Front\TemplateComponents\MetaHead;
use GP247\Front\TemplateComponents\Notice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\FrontendTemplateDev\Support\ForcedTemplateLanguageSwitcher;
use GP247\Shop\Tests\Feature\FrontendTemplateDev\Support\ForcedTemplateMetaHead;
use GP247\Shop\Tests\TestCase;

/**
 * Proves US-TPL-008/ADR-013: cross-cutting static UI (language/currency
 * switcher, footer links, breadcrumb, notice, meta head) is resolved through
 * the same algorithm as ADR-011's Livewire components, shared via
 * ResolvesTemplateView — a template only needs to write the Blade view under
 * app/GP247/Templates/<Template>/components/, not re-derive the data.
 *
 * @aidlc-unit frontend-template-dev
 * @aidlc-story US-TPL-008
 * @aidlc-adr ADR-011, ADR-013
 */
class SharedViewComponentTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Both component kinds (Livewire, static Blade) share one resolver
     * algorithm — no duplicated view-resolution logic between them.
     */
    public function test_both_component_bases_use_the_shared_resolver_trait(): void
    {
        $this->assertContains(ResolvesTemplateView::class, class_uses(BaseFrontComponent::class));
        $this->assertContains(ResolvesTemplateView::class, class_uses(BaseFrontViewComponent::class));
    }

    public function test_all_six_shared_components_extend_base_front_view_component(): void
    {
        foreach ([LanguageSwitcher::class, CurrencySwitcher::class, FooterLinks::class, Breadcrumb::class, Notice::class, MetaHead::class] as $class) {
            $this->assertTrue(is_subclass_of($class, BaseFrontViewComponent::class), "{$class} must extend BaseFrontViewComponent");
        }
    }

    /**
     * Blade only passes a tag attribute to a view component's constructor when
     * Str::camel(attribute) equals a parameter name; a snake_case parameter
     * never matches, so its value silently lands in $attributes. That is how
     * MetaHead's `$og_image` made every page share the store image
     * (RISK-TECH-blade-component-snake-param, ADR-013 amend 2026-10-25).
     */
    public function test_every_view_component_constructor_parameter_is_camel_case(): void
    {
        $components = $this->viewComponentClassesOfGp247Packages();
        $this->assertContains(MetaHead::class, $components, 'Scanner must find the shared components, otherwise this guard is vacuous');

        $offenders = [];
        foreach ($components as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                if (\Illuminate\Support\Str::camel($parameter->getName()) !== $parameter->getName()) {
                    $offenders[] = $class . '::$' . $parameter->getName();
                }
            }
        }

        $this->assertSame([], $offenders, 'View component constructor parameters must be camelCase');
    }

    /**
     * Collect every concrete Illuminate\View\Component subclass shipped by
     * gp247/core, gp247/front and gp247/shop.
     *
     * @return array<int, class-string>
     */
    private function viewComponentClassesOfGp247Packages(): array
    {
        $classes = [];
        foreach (['core', 'front', 'shop'] as $package) {
            $root = base_path("vendor/gp247/{$package}/src");
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                // WHY: only consider PSR-4 class files that could be a component, so
                // autoloading never pulls in route/config/migration files.
                if (!str_contains($source, 'Component')
                    || !preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                    || !preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $source, $cls)) {
                    continue;
                }
                $class = $ns[1] . '\\' . $cls[1];
                if (class_exists($class) && is_subclass_of($class, \Illuminate\View\Component::class)
                    && !(new \ReflectionClass($class))->isAbstract()) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * Registering the component namespace makes every default package view
     * resolvable — this is what <x-gp247-front::*> depends on.
     */
    public function test_package_default_views_are_registered_and_resolve(): void
    {
        foreach (['language-switcher', 'currency-switcher', 'footer-links', 'breadcrumb', 'notice', 'meta-head'] as $key) {
            $this->assertTrue(
                view()->exists("gp247-front::gp247_components.{$key}"),
                "gp247-front::gp247_components.{$key} does not resolve — check Blade::componentNamespace() registration"
            );
        }
    }

    /**
     * A template with no published/override file for this component must
     * fall back to the package default — proves the fallback direction still
     * works after the refactor. A template name with no directory under
     * app/GP247/Templates is used to exercise the true fallback path.
     * (Path corrected 2026-07-05: the
     * package's shared-component view directory is `gp247_components/`, not
     * `components/` — this assertion had drifted from the real
     * `LanguageSwitcher::templateViewKey()` value and was failing
     * independently of the `Default` removal.)
     */
    public function test_language_switcher_falls_back_to_package_default_when_template_has_no_override(): void
    {
        ForcedTemplateLanguageSwitcher::$forcedTemplate = 'NoOverrideTemplate';
        $component = new ForcedTemplateLanguageSwitcher();

        $this->assertSame('gp247-front::gp247_components.language-switcher', $component->resolvedViewForTest());
    }

    /**
     * MetaHead is the 6th component to gain the resolver (ADR-013 decision 5 amend #2) — proves it falls back
     * the same way as the other five for a template with no override file.
     * (Path corrected 2026-07-05 — see the
     * language-switcher test above.)
     */
    public function test_meta_head_falls_back_to_package_default_when_template_has_no_override(): void
    {
        ForcedTemplateMetaHead::$forcedTemplate = 'NoOverrideTemplate';
        $component = new ForcedTemplateMetaHead();

        $this->assertSame('gp247-front::gp247_components.meta-head', $component->resolvedViewForTest());
    }

    /**
     * `GP247Front` (the sole/default template since `Default` was removed —
     * ADR-014 Amend #1) ships a real
     * published copy of every component view under
     * app/GP247/Templates/GP247Front/gp247_components/ — it must resolve to
     * its own file, not silently fall through to the package default. Proves
     * both resolution directions stay covered.
     */
    public function test_language_switcher_resolves_gp247front_templates_own_published_file(): void
    {
        ForcedTemplateLanguageSwitcher::$forcedTemplate = 'GP247Front';
        $component = new ForcedTemplateLanguageSwitcher();

        $this->assertSame('GP247TemplatePath::GP247Front.gp247_components.language-switcher', $component->resolvedViewForTest());
    }

    /**
     * ADR-013 decision 4 amend (2026-07-03):
     * the package default must be framework-neutral (`gp247-*` classes),
     * carrying none of `Default`'s rd-navbar/Bootstrap-specific classes —
     * otherwise a template that omits an override silently inherits
     * `Default`'s nav framework and its layout breaks.
     *
     * Asserts against the RENDERED output, not the raw .blade.php source:
     * the source files carry `{{-- --}}` comments that name the old
     * rd-nav, contacts-creative, breadcrumbs-custom and data-dismiss classes
     * for documentation purposes (see the file headers) — Blade strips
     * comments at compile time, so only the rendered HTML reflects the true
     * markup.
     */
    public function test_package_default_views_use_neutral_gp247_classes_not_defaults_framework_classes(): void
    {
        $forbidden = ['rd-nav-item', 'rd-nav-link', 'rd-menu', 'rd-navbar-dropdown', 'rd-dropdown-item', 'rd-dropdown-link', 'contacts-creative', 'breadcrumbs-custom', 'alert alert-', 'alert-dismissible', 'data-dismiss'];

        $fixtures = [
            'language-switcher' => ['languages' => [['code' => 'en', 'name' => 'English', 'icon' => 'flag-en.png', 'active' => true, 'url' => '#']]],
            'currency-switcher' => ['currencies' => [['code' => 'USD', 'name' => 'USD', 'url' => '#']], 'activeCode' => 'USD'],
            // The shape is what FooterLinks::__construct() builds, and 'type' is
            // always one of 'link'/'collection' there — the blade branches on it with
            // ===, so a fixture without the key fatals rather than testing anything.
            // Both branches are covered: the collection one renders its own markup,
            // which the forbidden-class sweep below would otherwise never see.
            'footer-links' => ['links' => [
                ['type' => 'link', 'name' => 'Terms', 'url' => '#', 'target' => '_self'],
                ['type' => 'collection', 'name' => 'Support', 'children' => [
                    ['name' => 'FAQ', 'url' => '#', 'target' => '_self'],
                    ['name' => 'Contact', 'url' => '#', 'target' => '_blank'],
                ]],
            ]],
            'breadcrumb' => ['items' => [['title' => 'Sample Page', 'url' => '#', 'active' => true]]],
        ];

        foreach ($fixtures as $key => $data) {
            $html = view("gp247-front::gp247_components.{$key}", $data)->render();

            $this->assertStringContainsString("gp247-{$key}", $html, "package default for {$key} must render a gp247-{$key} class");

            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString($pattern, $html, "package default for {$key} still renders a forbidden framework class/attribute '{$pattern}'");
            }
        }
    }

    /**
     * Notice was consolidated onto the SweetAlert2 toast: it no longer renders a `.gp247-notice` banner —
     * that markup fired a duplicate message alongside common/js.blade.php's
     * own toast for the same session keys. Notice is now the sole trigger
     * for flash-driven toasts, so this asserts the alertJs() call sites
     * instead of the retired banner class.
     */
    public function test_package_default_notice_view_triggers_toast_for_each_flash_key(): void
    {
        session(['success' => 'Saved', 'error' => 'Failed', 'warning' => 'Careful']);

        $html = view('gp247-front::gp247_components.notice')->render();

        $this->assertStringContainsString("alertJs('success', 'Saved')", $html);
        $this->assertStringContainsString("alertJs('error', 'Failed')", $html);
        $this->assertStringContainsString("alertJs('warning', 'Careful')", $html);
        $this->assertStringNotContainsString('gp247-notice', $html, 'package default notice must no longer render the retired banner markup');
    }

    /**
     * `GP247Front` (sole/default template since `Default` was removed —
     * ADR-014 Amend #1) ships its own
     * override of every shared component under
     * app/GP247/Templates/GP247Front/gp247_components/ — must also use the
     * neutral `gp247-*` class contract, not any Bootstrap/rd-navbar leftover.
     * Replaces the old `Default`-specific version of this guard.
     */
    public function test_gp247front_template_views_use_neutral_gp247_classes(): void
    {
        $forbidden = ['rd-nav-item', 'rd-nav-link', 'rd-menu', 'rd-navbar-dropdown', 'rd-dropdown-item', 'rd-dropdown-link', 'contacts-creative', 'breadcrumbs-custom', 'alert alert-', 'alert-dismissible', 'data-dismiss'];

        $fixtures = [
            'language-switcher' => ['languages' => [['code' => 'en', 'name' => 'English', 'icon' => 'flag-en.png', 'active' => true, 'url' => '#']]],
            'currency-switcher' => ['currencies' => [['code' => 'USD', 'name' => 'USD', 'url' => '#']], 'activeCode' => 'USD'],
            // The shape is what FooterLinks::__construct() builds, and 'type' is
            // always one of 'link'/'collection' there — the blade branches on it with
            // ===, so a fixture without the key fatals rather than testing anything.
            // Both branches are covered: the collection one renders its own markup,
            // which the forbidden-class sweep below would otherwise never see.
            'footer-links' => ['links' => [
                ['type' => 'link', 'name' => 'Terms', 'url' => '#', 'target' => '_self'],
                ['type' => 'collection', 'name' => 'Support', 'children' => [
                    ['name' => 'FAQ', 'url' => '#', 'target' => '_self'],
                    ['name' => 'Contact', 'url' => '#', 'target' => '_blank'],
                ]],
            ]],
            'breadcrumb' => ['items' => [['title' => 'Sample Page', 'url' => '#', 'active' => true]]],
        ];

        foreach ($fixtures as $key => $data) {
            $path = $this->templateFile('GP247Front', "gp247_components/{$key}.blade.php");
            $this->assertFileExists($path);

            $html = view("GP247TemplatePath::GP247Front.gp247_components.{$key}", $data)->render();

            $this->assertStringContainsString("gp247-{$key}", $html, "GP247Front/gp247_components/{$key}.blade.php must render a gp247-{$key} class");

            foreach ($forbidden as $pattern) {
                $this->assertStringNotContainsString($pattern, $html, "GP247Front/gp247_components/{$key}.blade.php renders a forbidden framework class/attribute '{$pattern}'");
            }
        }
    }

    /**
     * `GP247Front`'s own notice.blade.php override — see
     * test_package_default_notice_view_triggers_toast_for_each_flash_key.
     * Replaces the old `Default`-specific version of this guard.
     */
    public function test_gp247front_template_notice_view_triggers_toast_for_each_flash_key(): void
    {
        $path = $this->templateFile('GP247Front', 'gp247_components/notice.blade.php');
        $this->assertFileExists($path);

        session(['success' => 'Saved', 'error' => 'Failed', 'warning' => 'Careful']);

        $html = view('GP247TemplatePath::GP247Front.gp247_components.notice')->render();

        $this->assertStringContainsString("alertJs('success', 'Saved')", $html);
        $this->assertStringContainsString("alertJs('error', 'Failed')", $html);
        $this->assertStringContainsString("alertJs('warning', 'Careful')", $html);
        $this->assertStringNotContainsString('gp247-notice', $html, 'GP247Front/gp247_components/notice.blade.php must no longer render the retired banner markup');
    }

    /**
     * MC-018 regression guard: the cross-cutting helper calls this refactor
     * eliminated from templates must not creep back into layout/breadcrumb/
     * notice files for either template.
     */
    public function test_no_cross_cutting_helper_calls_remain_in_template_layout_files(): void
    {
        // block_menu.blade.php legitimately still calls gp247_link_collection()
        // for the main site nav (out of US-TPL-008 scope — only the footer
        // link list and language/currency switcher loops were extracted).
        $filesToForbidden = [
            'layout/block_menu.blade.php' => ['gp247_language_all(', 'gp247_currency_all('],
            'layout/block_footer.blade.php' => ['gp247_link_collection('],
            'gp247_components/breadcrumb.blade.php' => ['count($breadcrumbs)'],
            'gp247_components/notice.blade.php' => ["Session::has(", "Session::get("],
        ];

        foreach (['GP247Front'] as $template) {
            foreach ($filesToForbidden as $relative => $forbidden) {
                $path = $this->templateFile($template, $relative);
                $this->assertFileExists($path);
                $content = file_get_contents($path);

                foreach ($forbidden as $pattern) {
                    $this->assertStringNotContainsString(
                        $pattern,
                        $content,
                        "{$template}/{$relative} still contains '{$pattern}' — should delegate to <x-gp247-front::*> instead (MC-018)"
                    );
                }
            }
        }
    }

    /**
     * Absolute path of a template file, resolved the way rendering resolves it:
     * first source root that has it (site's published override under app/, then
     * the package's own copy).
     *
     * WHY: a template's Blade is served from the
     * package unless the site published that file to edit it, so hard-coding
     * app/GP247/Templates would fail on a fresh install.
     *
     * @param string $template Template name, e.g. "GP247Front".
     * @param string $relative Path inside the template.
     * @return string Absolute path (falls back to the app/ path when nothing provides it).
     */
    private function templateFile(string $template, string $relative): string
    {
        foreach (\GP247\Core\Support\TemplateSourceAudit::roots() as $root) {
            $candidate = $root . '/' . $template . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return base_path("app/GP247/Templates/{$template}/{$relative}");
    }
}
