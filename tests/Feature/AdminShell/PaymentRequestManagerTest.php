<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Shop\Admin\Livewire\PaymentRequestManager;
use GP247\Core\Models\AdminLog;
use GP247\Core\Models\AdminMenu;
use GP247\Core\Models\AdminPermission;
use GP247\Shop\Payment\Models\PaymentRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\AdminShell\Support\PaymentRequestFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The admin manages payment requests on one screen: list, create, record money by
 * hand, cancel — and only someone holding the money-out permission can record
 * money going out.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-admin-screen
 * @aidlc-adr payment-request_generic-money-request
 */
class PaymentRequestManagerTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentRequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentRequest();
    }

    public function test_the_screen_is_routed_and_seeded_with_its_menu_and_permissions(): void
    {
        $this->assertTrue(Route::has('admin.payment_request.index'));
        $this->assertStringEndsWith('/payment_request', parse_url(route('admin.payment_request.index'), PHP_URL_PATH));

        // Menu and permissions come with the shop's payment request migration (fresh install and upgrade).
        $migration = require base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_01_200000_install_payment_request.php');
        $permissions = collect($migration::permissions($this->adminPrefix()))->keyBy('slug');
        $this->assertTrue($permissions->has('payment.request'));
        $this->assertTrue($permissions->has('payment.request.settle_out'));
        $this->assertSame('POST::' . $this->adminPrefix() . '/payment_request_settle_out', $permissions['payment.request.settle_out']['http_uri']);
        $this->assertStringNotContainsString('payment_request_settle_out', $permissions['payment.request']['http_uri'], 'the screen permission must not cover money out');

        $this->assertSame('admin::payment_request', $migration::menu()['uri'], 'menu entry seeded');
    }

    public function test_an_admin_creates_a_request_from_the_form(): void
    {
        $this->asScreenUser();

        Livewire::test(PaymentRequestManager::class)
            ->set('form.direction', 'in')
            ->set('form.purpose', 'free')
            ->set('form.amount', '120.5')
            ->set('form.currency', 'USD')
            ->set('form.party_name', 'ZZ Buyer')
            ->set('form.party_email', 'buyer@example.com')
            ->set('form.description', 'Deposit')
            ->call('save')
            ->assertHasNoErrors();

        $row = PaymentRequest::where('party_name', 'ZZ Buyer')->first();
        $this->assertNotNull($row);
        $this->assertSame(PaymentRequest::STATUS_OPEN, $row->status);
        $this->assertEqualsWithDelta(120.5, (float) $row->amount, 0.001);
    }

    public function test_the_form_refuses_a_purpose_that_does_not_allow_the_direction(): void
    {
        $this->asScreenUser();

        Livewire::test(PaymentRequestManager::class)
            ->set('form.direction', 'out')
            ->set('form.purpose', 'test.in-only')
            ->set('form.amount', '10')
            ->set('form.currency', 'USD')
            ->set('form.party_name', 'X')
            ->call('save')
            ->assertHasErrors('form.purpose');
    }

    public function test_the_list_shows_requests_and_the_detail_shows_movements_and_the_subject(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest(['purpose' => 'test.recording', 'subject_type' => 'commission', 'subject_id' => 'C-1', 'party_name' => 'ZZ Listed']);
        app(\GP247\Shop\Payment\PaymentRequestService::class)->recordManual($request, 30, 'UNC-30');

        $component = Livewire::test(PaymentRequestManager::class)
            ->assertSee('ZZ Listed')
            ->call('editRow', $request->id);

        $html = $component->html();
        $this->assertStringContainsString('data-testid="payment-request-list-item"', $html);
        $this->assertStringContainsString('data-testid="payment-request-movement-item"', $html);
        $this->assertStringContainsString('UNC-30', $html);
        $this->assertStringContainsString('Subject #C-1', $html, 'describeSubject() of the purpose owner is shown');
        $this->assertStringContainsString('data-testid="payment-request-record-manual"', $html);
    }

    public function test_recording_money_in_by_hand_needs_only_the_screen_permission(): void
    {
        // A real signed-in admin, so the operation-log hook has someone to attribute the action to.
        config(['gp247-config.admin.admin_log' => 1]);
        $this->actingAs(\GP247\Core\Models\AdminUser::create([
            'id' => 'AU-PR' . substr(uniqid(), -4),
            'username' => 'prlog_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Payment Request Probe',
            'email' => 'prlog-' . uniqid() . '@test.local',
            'status' => 1,
        ]), 'admin');
        $this->asScreenUser();
        $request = $this->makeRequest(['amount' => 100]);
        $before = AdminLog::count();

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->set('manual.amount', '100')
            ->set('manual.reference', 'UNC-IN')
            ->call('recordManual')
            ->assertHasNoErrors();

        $this->assertSame(PaymentRequest::STATUS_SETTLED, $request->fresh()->status);
        $this->assertSame($before + 2, AdminLog::count(), 'editRow + recordManual are both logged actions');
    }

    public function test_recording_money_out_is_refused_without_the_settle_out_permission(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest(['direction' => 'out', 'amount' => 100]);

        try {
            Livewire::test(PaymentRequestManager::class)
                ->call('editRow', $request->id)
                ->set('manual.amount', '100')
                ->set('manual.reference', 'BANK-1')
                ->call('recordManual');
            $this->fail('money out without the permission must be denied');
        } catch (\Throwable $e) {
            $current = $e;
            while ($current !== null && !$current instanceof AuthorizationException) {
                $current = $current->getPrevious();
            }
            $this->assertInstanceOf(AuthorizationException::class, $current);
        }

        $this->assertSame(0, $this->movementsOf($request));
        $this->assertSame(PaymentRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_recording_money_out_works_with_the_settle_out_permission_and_for_administrators(): void
    {
        $this->asSettleOutUser();
        $request = $this->makeRequest(['direction' => 'out', 'amount' => 100]);

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->set('manual.amount', '100')
            ->set('manual.reference', 'BANK-1')
            ->call('recordManual')
            ->assertHasNoErrors();
        $this->assertSame(PaymentRequest::STATUS_SETTLED, $request->fresh()->status);

        $this->asAdministrator();
        $other = $this->makeRequest(['direction' => 'out', 'amount' => 50]);
        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $other->id)
            ->set('manual.amount', '50')
            ->set('manual.reference', 'BANK-2')
            ->call('recordManual')
            ->assertHasNoErrors();
        $this->assertSame(PaymentRequest::STATUS_SETTLED, $other->fresh()->status);
    }

    public function test_the_money_out_button_is_hidden_from_a_user_without_the_permission(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest(['direction' => 'out']);

        $html = Livewire::test(PaymentRequestManager::class)->call('editRow', $request->id)->html();
        $this->assertStringNotContainsString('data-testid="payment-request-record-manual"', $html);

        $this->asSettleOutUser();
        $html = Livewire::test(PaymentRequestManager::class)->call('editRow', $request->id)->html();
        $this->assertStringContainsString('data-testid="payment-request-record-manual"', $html);
    }

    public function test_recording_more_than_the_open_amount_is_a_validation_error_not_a_crash(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest(['amount' => 100]);

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->set('manual.amount', '150')
            ->set('manual.reference', 'X')
            ->call('recordManual')
            ->assertHasErrors('manual.amount');

        $this->assertSame(0, $this->movementsOf($request));
    }

    public function test_cancelling_is_allowed_only_while_no_money_has_moved(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest();

        Livewire::test(PaymentRequestManager::class)->call('editRow', $request->id)->call('cancelRequest');
        $this->assertSame(PaymentRequest::STATUS_CANCELLED, $request->fresh()->status);

        $paid = $this->makeRequest();
        app(\GP247\Shop\Payment\PaymentRequestService::class)->recordManual($paid, 10, 'UNC');
        Livewire::test(PaymentRequestManager::class)->call('editRow', $paid->id)->call('cancelRequest');
        $this->assertSame(PaymentRequest::STATUS_PARTIAL, $paid->fresh()->status, 'a request holding money is never cancelled');
    }

    public function test_amount_and_currency_are_read_only_once_money_has_moved(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest(['amount' => 100]);
        app(\GP247\Shop\Payment\PaymentRequestService::class)->recordManual($request, 10, 'UNC');

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->set('form.amount', '999')
            ->set('form.description', 'renamed')
            ->call('save');

        $fresh = $request->fresh();
        $this->assertEqualsWithDelta(100, (float) $fresh->amount, 0.001, 'amount untouched');
        $this->assertSame('renamed', $fresh->description, 'other fields still editable');
    }

    public function test_the_currency_is_picked_from_the_system_currencies(): void
    {
        $this->asScreenUser();
        config([
            'gp247-config.payment.currencies' => [\GP247\Shop\Tests\Feature\AdminShell\Support\FakeCurrencySeam::class, 'catalog'],
            'gp247-config.payment.currency_labels' => [\GP247\Shop\Tests\Feature\AdminShell\Support\FakeCurrencySeam::class, 'labels'],
        ]);

        $component = Livewire::test(PaymentRequestManager::class)
            ->assertSeeHtml('<select wire:model="form.currency" data-testid="payment-request-form-currency"')
            ->assertSeeHtml('value="VND"')
            ->assertSee('VND — ZZ Dong')
            ->assertDontSeeHtml('value="EUR"');

        $component->set('form.purpose', 'free')->set('form.amount', '50')->set('form.currency', 'EUR')->call('save')
            ->assertHasErrors('form.currency');
        $component->set('form.currency', 'VND')->call('save')->assertHasNoErrors();
        $this->assertSame('VND', PaymentRequest::orderByDesc('id')->first()->currency);
    }

    public function test_without_a_currency_list_the_code_is_typed(): void
    {
        $this->asScreenUser();
        config(['gp247-config.payment.currencies' => null, 'gp247-config.payment.currency_labels' => null]);

        Livewire::test(PaymentRequestManager::class)
            ->assertDontSeeHtml('<select wire:model="form.currency"')
            ->assertSeeHtml('data-testid="payment-request-form-currency"');
    }

    public function test_a_purpose_that_needs_a_record_names_the_field_and_links_it(): void
    {
        $this->asScreenUser();

        $component = Livewire::test(PaymentRequestManager::class)
            ->assertSee('Linked record')
            ->set('form.purpose', 'test.subject')
            ->assertSee('ZZ Thing ID')
            ->assertSee('ZZ where to find the id')
            ->assertSeeHtml('data-testid="payment-request-form-subject-type"')
            ->assertSet('form.subject_type', 'zz_thing');

        $component->set('form.amount', '10')->set('form.currency', 'USD')->set('form.subject_id', '')->call('save')
            ->assertHasErrors(['form.subject_id' => 'required']);

        $component->set('form.subject_type', 'zz_other')->set('form.subject_id', 'ok-1')->call('save')->assertHasNoErrors();
        $created = PaymentRequest::orderByDesc('id')->first();
        $this->assertSame('test.subject', $created->purpose);
        $this->assertSame('zz_other', $created->subject_type);
        $this->assertSame('ok-1', $created->subject_id);
    }

    public function test_an_owner_refusal_is_shown_on_the_field_it_is_about(): void
    {
        $this->asScreenUser();

        Livewire::test(PaymentRequestManager::class)
            ->set('form.purpose', 'test.subject')
            ->set('form.amount', '10')
            ->set('form.currency', 'USD')
            ->set('form.subject_id', 'missing')
            ->call('save')
            ->assertHasErrors('form.subject_id')
            ->assertHasNoErrors('form.amount')
            ->assertSee('ZZ no such thing');
    }

    public function test_a_free_purpose_keeps_the_optional_free_text_link(): void
    {
        $this->asScreenUser();

        Livewire::test(PaymentRequestManager::class)
            ->set('form.purpose', 'test.subject')
            ->set('form.purpose', 'free')
            ->assertSet('form.subject_type', '')
            ->assertDontSeeHtml('data-testid="payment-request-form-subject-type"')
            ->assertSee('Optional: order number');
    }

    public function test_an_open_record_shows_who_created_it_and_when(): void
    {
        $this->asScreenUser();
        $admin = \GP247\Core\Models\AdminUser::createUser([
            'name' => 'ZZ Creator', 'username' => 'zzprcreator' . uniqid(),
            'email' => 'zzprcreator' . uniqid() . '@example.com', 'password' => bcrypt('Password123!'), 'status' => 1,
        ]);
        $request = $this->makeRequest(['created_by' => (string) $admin->id]);

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->assertSeeHtml('data-testid="payment-request-audit"')
            ->assertSee('ZZ Creator')
            ->assertSee($request->created_at->format('Y-m-d H:i'));
    }

    public function test_an_open_record_offers_a_way_back_to_a_new_request(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest();

        Livewire::test(PaymentRequestManager::class)
            ->assertDontSeeHtml('data-testid="payment-request-back"')
            ->call('editRow', $request->id)
            ->assertSeeHtml('data-testid="payment-request-back"')
            ->call('cancelEdit')
            ->assertSet('editingId', null)
            ->assertDontSeeHtml('data-testid="payment-request-back"');
    }

    public function test_the_list_shows_when_each_request_was_created(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest();

        Livewire::test(PaymentRequestManager::class)
            ->assertSeeHtml('data-testid="payment-request-list-created"')
            ->assertSee($request->created_at->format('Y-m-d H:i'));
    }

    public function test_the_list_shows_who_created_each_request(): void
    {
        $this->asScreenUser();
        $admin = \GP247\Core\Models\AdminUser::createUser([
            'name' => 'ZZ List Creator', 'username' => 'zzprlist' . uniqid(),
            'email' => 'zzprlist' . uniqid() . '@example.com', 'password' => bcrypt('Password123!'), 'status' => 1,
        ]);
        $this->makeRequest(['created_by' => (string) $admin->id]);

        Livewire::test(PaymentRequestManager::class)
            ->assertSeeHtml('data-testid="payment-request-list-creator"')
            ->assertSee('ZZ List Creator');
    }

    public function test_the_hand_entry_button_says_the_money_has_arrived_or_left_and_explains_it(): void
    {
        $this->asSettleOutUser();
        $in = $this->makeRequest(['direction' => 'in']);
        $out = $this->makeRequest(['direction' => 'out']);

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $in->id)
            ->assertSee(gp247_language_render('admin.payment_request.manual_confirm_in'))
            ->assertSeeHtml('data-testid="payment-request-manual-hint"')
            ->assertSee(gp247_language_render('admin.payment_request.manual_hint_in'))
            ->call('editRow', $out->id)
            ->assertSee(gp247_language_render('admin.payment_request.manual_confirm_out'))
            ->assertSee(gp247_language_render('admin.payment_request.manual_hint_out'));
    }
}

