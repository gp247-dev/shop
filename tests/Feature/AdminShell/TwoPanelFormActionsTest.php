<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\ApiConnectionManager;
use GP247\Core\AdminShell\Http\Livewire\MenuManager;
use GP247\Core\AdminShell\Http\Livewire\UserManager;
use GP247\Core\AdminShell\Infrastructure\ResourcePanel;
use GP247\Core\Models\AdminUser;
use GP247\Front\Admin\Livewire\PageManager;
use GP247\Front\Models\FrontPage;
use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Models\ShopBrand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Two-column admin screens (form + list): while a record is being edited, the head of
 * the form offers "+ Create new", which returns to an empty create form and leaves the
 * list where it was; the foot of the form holds only the submit button — no Reset in
 * create mode, no Cancel in edit mode.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-two-panel-state-preservation
 * @aidlc-adr ADR-admin-shell-rbac-two-panel-state-preservation
 */
class TwoPanelFormActionsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** Keyword isolating this test's rows from the live catalogue. */
    private const TOKEN = 'zqformactions';

    /**
     * The 24 two-column views of core / front / shop that carry the shared button.
     *
     * @var array<int, string>
     */
    private const VIEWS = [
        'core/src/Views/admin/livewire/user-manager.blade.php',
        'core/src/Views/admin/livewire/role-manager.blade.php',
        'core/src/Views/admin/livewire/permission-manager.blade.php',
        'core/src/Views/admin/livewire/menu-manager.blade.php',
        'core/src/Views/admin/livewire/language-manager.blade.php',
        'core/src/Views/admin/livewire/home-layout-manager.blade.php',
        'core/src/Views/admin/livewire/api-connection-manager.blade.php',
        'core/src/Views/admin/livewire/api-webhook-manager.blade.php',
        'front/src/Views/admin/layout-block-manager.blade.php',
        'front/src/Views/admin/link-manager.blade.php',
        'front/src/Views/admin/page-manager.blade.php',
        'front/src/Views/admin/seo-redirect-manager.blade.php',
        'shop/src/Views/admin/attribute-group-manager.blade.php',
        'shop/src/Views/admin/brand-manager.blade.php',
        'shop/src/Views/admin/category-manager.blade.php',
        'shop/src/Views/admin/currency-manager.blade.php',
        'shop/src/Views/admin/customer-manager.blade.php',
        'shop/src/Views/admin/livewire/payment-request-manager.blade.php',
        'shop/src/Views/admin/product-manager.blade.php',
        'shop/src/Views/admin/product-tag-manager.blade.php',
        'shop/src/Views/admin/status-manager.blade.php',
        'shop/src/Views/admin/subscribe-manager.blade.php',
        'shop/src/Views/admin/supplier-manager.blade.php',
        'shop/src/Views/admin/tax-manager.blade.php',
    ];

    /**
     * Representative screens: one per package plus the non-ResourcePanel shapes.
     *
     * @return array<string, array{0: class-string, 1: array<int, string>, 2: string, 3: string}>
     *               component, permission slugs, "create new" test id, old footer test id
     */
    public static function screens(): array
    {
        return [
            'shop brand' => [BrandManager::class, ['admin_brand'], 'admin-brand-form-new', 'admin-brand-form-cancel'],
            'front page' => [PageManager::class, ['admin_page'], 'admin-page-form-new', 'admin-page-form-cancel'],
            'core user' => [UserManager::class, ['admin_user'], 'admin-user-form-new', 'admin-user-form-cancel'],
            'core menu (tree)' => [MenuManager::class, ['admin_menu'], 'admin-menu-form-new', 'admin-menu-form-cancel'],
            'core api connection' => [ApiConnectionManager::class, ['admin_api_connection'], 'api-connection-form-new', 'api-connection-form-reset'],
        ];
    }

    /**
     * @param class-string       $component
     * @param array<int, string> $slugs
     * @param string             $newId
     * @param string             $oldId
     * @return void
     */
    #[DataProvider('screens')]
    public function test_an_edit_form_shows_create_new_in_its_header_and_no_cancel_button(string $component, array $slugs, string $newId, string $oldId): void
    {
        $this->asUser(slugs: $slugs);

        $this->openForEdit(Livewire::test($component))
            ->assertSeeHtml('data-testid="' . $newId . '"')
            ->assertDontSeeHtml('data-testid="' . $oldId . '"');
    }

    /**
     * Put the form in edit mode the way the screen does. ResourcePanel locks
     * `editingId` against client writes, so open a real listed row through editRow();
     * the other shapes (tree, API connection) keep their own editable id.
     *
     * @param \Livewire\Features\SupportTesting\Testable $test
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function openForEdit($test)
    {
        if (!$test->instance() instanceof ResourcePanel) {
            return $test->set('editingId', 1);
        }

        // WHY: UserManager refuses to open guarded admins and the signed-in user.
        $protected = method_exists($test->instance(), 'protectedIds') ? $test->instance()->protectedIds() : [];
        $row = $test->viewData('rows')->first(fn ($r) => !in_array((string) $r->getKey(), $protected, true));
        if ($row === null && $test->instance() instanceof BrandManager) {
            $row = ShopBrand::create(['name' => 'ZQ edit brand', 'alias' => self::TOKEN . '-edit-' . uniqid(), 'image' => '', 'sort' => 0, 'status' => 1, 'store_id' => GP247_STORE_ID_ROOT]);
        }
        if ($row === null && $test->instance() instanceof PageManager) {
            $row = FrontPage::create(['image' => '', 'alias' => self::TOKEN . '-edit-' . uniqid(), 'status' => 1, 'store_id' => GP247_STORE_ID_ROOT]);
        }
        if ($row === null && $test->instance() instanceof UserManager) {
            $suffix = substr(uniqid(), -8);
            $row = AdminUser::create([
                'username' => self::TOKEN . $suffix, 'password' => bcrypt('secret-' . $suffix),
                'name' => 'ZQ edit user', 'email' => self::TOKEN . $suffix . '@example.test', 'status' => 1,
            ]);
        }
        $this->assertNotNull($row, 'no listed row to open for edit');

        return $test->call('editRow', (string) $row->getKey())->assertSet('editingId', (string) $row->getKey());
    }

    /**
     * @param class-string       $component
     * @param array<int, string> $slugs
     * @param string             $newId
     * @param string             $oldId
     * @return void
     */
    #[DataProvider('screens')]
    public function test_a_create_form_has_only_the_submit_button(string $component, array $slugs, string $newId, string $oldId): void
    {
        $this->asUser(slugs: $slugs);

        $html = Livewire::test($component)->html();

        $this->assertStringNotContainsString('data-testid="' . $newId . '"', $html);
        $this->assertStringNotContainsString('data-testid="' . $oldId . '"', $html);
        $this->assertDoesNotMatchRegularExpression('/wire:click="(cancelEdit|resetForm)"/', $html);
    }

    public function test_create_new_returns_to_an_empty_create_form_and_keeps_the_list_page(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $brands = [];
        for ($i = 1; $i <= 20; $i++) {
            $label = sprintf('%s-%02d', self::TOKEN, $i);
            $brands[] = ShopBrand::create(['name' => $label, 'alias' => $label, 'image' => 'path/seed.png', 'sort' => 0, 'status' => 1]);
        }

        $component = Livewire::test(BrandManager::class)
            ->set('keyword', self::TOKEN)
            ->call('gotoPage', 2);
        // Default order is id desc: the oldest seeded brand sits on page 2 of 20 rows.
        $component->call('editRow', (string) $brands[0]->id)
            ->assertSet('editingId', (string) $brands[0]->id)
            ->assertSeeHtml('data-testid="admin-brand-form-new"');

        $component->call('cancelEdit')
            ->assertSet('editingId', null)
            ->assertSet('form.name', '')
            ->assertSet('keyword', self::TOKEN)
            ->assertDontSeeHtml('data-testid="admin-brand-form-new"');
        $this->assertSame(2, (int) (($component->get('paginators') ?? [])['page'] ?? 1));
    }

    public function test_no_two_panel_view_keeps_a_reset_or_cancel_footer_button(): void
    {
        foreach (self::VIEWS as $view) {
            $source = file_get_contents(base_path('vendor/gp247/' . $view));

            $this->assertStringContainsString('<x-gp247::form-new-button', $source, $view . ' must use the shared "create new" button');
            $this->assertDoesNotMatchRegularExpression(
                '/<x-gp247::button[^>]*wire:click="(cancelEdit|resetForm)"/s',
                $source,
                $view . ' must not keep its own Reset / Cancel / back button'
            );
        }
    }

    public function test_the_webhook_form_offers_create_new_through_reset_form(): void
    {
        // WHY source-level: the webhook table ships with a later core migration that
        // some development databases have not run yet; the view contract is what matters.
        $source = file_get_contents(base_path('vendor/gp247/core/src/Views/admin/livewire/api-webhook-manager.blade.php'));

        $this->assertMatchesRegularExpression(
            '/<x-gp247::form-new-button[^>]*:editing="\$editingId"[^>]*action="resetForm"[^>]*data-testid="api-sync-webhook-form-new"/s',
            $source
        );
    }

    public function test_the_add_string_dialog_closes_with_a_cancel_label(): void
    {
        $source = file_get_contents(base_path('vendor/gp247/core/src/Views/admin/livewire/language-string-manager.blade.php'));

        $this->assertMatchesRegularExpression(
            "/wire:click=\"closeAdd\"[^>]*>\\s*\\{\\{\\s*gp247_language_render\\('admin\\.cancel'\\)/s",
            $source
        );
    }

    public function test_the_create_new_button_renders_only_while_editing_and_calls_its_action(): void
    {
        $this->assertSame('', trim(Blade::render('<x-gp247::form-new-button :editing="null" data-testid="x-new" />')));

        $html = Blade::render('<x-gp247::form-new-button editing="7" data-testid="x-new" />');
        $this->assertStringContainsString('wire:click="cancelEdit"', $html);
        $this->assertStringContainsString('data-testid="x-new"', $html);
        $this->assertStringContainsString(gp247_language_render('action.create'), $html);

        $html = Blade::render('<x-gp247::form-new-button editing="7" action="resetForm" label="Own label" />');
        $this->assertStringContainsString('wire:click="resetForm"', $html);
        $this->assertStringContainsString('Own label', $html);
    }

    public function test_the_card_renders_header_actions_next_to_its_title(): void
    {
        $html = Blade::render('<x-gp247::card title="Edit"><x-slot name="actions"><span data-testid="card-action"></span></x-slot>
 Body</x-gp247::card>');
        $this->assertMatchesRegularExpression('/Edit<\/h3>.*data-testid="card-action".*<div class="p-5">\s*Body/s', $html);

        $plain = Blade::render('<x-gp247::card title="List">Body</x-gp247::card>');
        $this->assertStringContainsString('List</h3>', $plain);
        $this->assertStringNotContainsString('card-action', $plain);
    }
}
