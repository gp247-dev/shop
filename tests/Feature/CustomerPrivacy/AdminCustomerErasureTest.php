<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Admin\Livewire\CustomerManager;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Privacy\ErasedCustomer;
use GP247\Shop\Privacy\OpenOrderBlocker;
use GP247\Shop\Tests\Feature\CustomerPrivacy\Support\SeedsCustomerData;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;

/**
 * Deleting a customer on the admin Customers screen erases their personal data everywhere the
 * shop keeps it, keeps their orders for the accounts without name, contact or address, refuses
 * while an order is still in progress, and asks the administrator to confirm who they are.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-admin-delete-complete, US-customer-privacy-declare-shop-data
 * @aidlc-adr customer-privacy_admin-delete-flow, personal-data-registry_shared-step-up
 */
#[Group('customer-privacy')]
class AdminCustomerErasureTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use SeedsCustomerData;

    /** Tables whose rows of the customer are deleted, with the column linking them. */
    private const DELETED = [
        'shop_customer' => 'id', 'shop_customer_address' => 'customer_id', 'shop_shoppingcart' => 'identifier',
        'shop_sessions' => 'customer_id', 'admin_custom_field_detail' => 'rel_id',
    ];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        session()->start();
    }

    /**
     * @param string $customerId
     * @return \Livewire\Features\SupportTesting\Testable The screen after a confirmed erasure ran to the end.
     */
    private function eraseOnScreen(string $customerId)
    {
        $user = $this->signInAdministrator();
        RateLimiter::clear('personal-data-step-up:' . $user->id);
        $screen = Livewire::test(CustomerManager::class)
            ->call('delete', $customerId)
            ->assertSet('erasureOpen', true)
            ->call('confirmErasure')
            ->assertSet('stepUpOpen', true)
            ->set('stepUpPassword', self::ADMIN_PASSWORD)
            ->call('confirmStepUp');
        for ($i = 0; $i < 20 && $screen->get('erasureStatus') !== 'done'; $i++) {
            $screen->call('tickErasure');
        }

        return $screen;
    }

    public function test_deleting_a_customer_leaves_no_account_address_cart_sign_in_contact_or_newsletter_row(): void
    {
        $email = 'an.erase-' . uniqid() . '@example.com';
        $ids = $this->seedCustomer($email);

        $this->eraseOnScreen($ids['customer'])->assertSet('erasureStatus', 'done');

        foreach (self::DELETED as $table => $column) {
            $this->assertSame(0, $this->table($table)->where($column, $ids['customer'])->count(), $table);
        }
        $this->assertSame(0, $this->table('shop_password_resets')->where('email', $email)->count());
        $this->assertSame(0, $this->table('front_contact')->where('email', $email)->count());
        $this->assertSame(0, $this->table('front_subscribe')->where('email', $email)->count());
        $this->assertSame(0, $this->table('api_session')->where('tokenable_id', $ids['customer'])->count(), 'app sign-ins are revoked');
        $this->assertSame(0, $this->table('api_refresh_token')->where('session_id', $ids['session'])->count());
        $this->assertSame(0, DB::connection(GP247_DB_CONNECTION)->table('personal_access_tokens')->where('tokenable_id', $ids['customer'])->count());
    }

    public function test_deleting_a_customer_is_recorded_as_a_completed_request_of_an_administrator(): void
    {
        $ids = $this->seedCustomer('an.request-' . uniqid() . '@example.com');

        $this->eraseOnScreen($ids['customer'])->assertSet('erasureStatus', 'done');

        $request = $this->table('personal_data_request')->where('customer_id', $ids['customer'])->first();
        $this->assertNotNull($request);
        $this->assertSame('admin', $request->source);
        $this->assertSame('completed', $request->status);
        $this->assertNotNull($request->erasure_id);
    }

    public function test_orders_are_kept_for_the_accounts_without_name_contact_or_address(): void
    {
        $email = 'an.keep-' . uniqid() . '@example.com';
        $ids = $this->seedCustomer($email);
        $money = $this->moneySnapshot($ids);

        $this->eraseOnScreen($ids['customer']);

        $order = $this->table('shop_order')->where('id', $ids['order'])->first();
        $this->assertSame(ColumnRule::MARKER, $order->first_name);
        foreach (['last_name', 'email', 'phone', 'address1', 'city', 'postcode', 'ip', 'user_agent', 'comment'] as $column) {
            $this->assertNull($order->{$column}, $column);
        }
        $this->assertSame($ids['customer'], (string) $order->customer_id, 'the order still says which account it belonged to');
        $this->assertEquals($money, $this->moneySnapshot($ids), 'no amount, quantity or status changed');
        $this->assertTrue(ErasedCustomer::is(trim($order->first_name . ' ' . $order->last_name)), 'admin screens show "Deleted customer"');
    }

    public function test_free_text_about_the_customer_is_scrubbed_in_history_payments_and_shipments(): void
    {
        $ids = $this->seedCustomer('an.text-' . uniqid() . '@example.com');

        $this->eraseOnScreen($ids['customer']);

        $texts = [
            $this->table('shop_order_history')->where('order_id', $ids['order'])->value('content'),
            $this->table('shop_order_transaction')->where('order_id', $ids['order'])->value('note'),
            $this->table('payment_request')->where('id', $ids['request'])->value('description'),
            $this->table('payment_movement')->where('request_id', $ids['request'])->value('note'),
            $this->table('shop_shipment_event')->where('shipment_id', $ids['shipment'])->value('message'),
            $this->table('shop_shipment')->where('id', $ids['shipment'])->value('request'),
        ];
        foreach ($texts as $text) {
            $this->assertStringNotContainsString(self::PHONE, (string) $text);
            $this->assertStringNotContainsString(self::STREET, (string) $text);
            $this->assertStringNotContainsStringIgnoringCase('Van An', (string) $text);
        }
        $this->assertStringContainsString('1700000', (string) $texts[0], 'amounts in the history stay readable');
        $request = json_decode((string) $texts[5], true);
        $this->assertSame('Shop warehouse', $request['origin']['name'], 'the shop\'s own address is not the customer\'s');
        $this->assertSame('VN', $request['destination']['country']);
        $payment = $this->table('payment_request')->where('id', $ids['request'])->first();
        $this->assertSame(ColumnRule::MARKER, $payment->party_name);
        $this->assertNull($payment->party_email);
        $metadata = json_decode((string) $payment->metadata, true);
        $this->assertSame('gw-tok-1', $metadata['access_token'], 'gateway keys and values that are not the customer\'s stay');
        $this->assertSame(1700000, $metadata['amount']);
        $this->assertStringNotContainsString(self::PHONE, (string) $payment->metadata);
        $meta = (string) $this->table('shop_shipment')->where('id', $ids['shipment'])->value('meta');
        $this->assertStringNotContainsString(self::PHONE, $meta);
        $this->assertSame('GHN-1', json_decode($meta, true)['carrier_order']);
    }

    public function test_an_email_typed_on_an_order_is_not_used_to_erase_someone_elses_data(): void
    {
        $ids = $this->seedCustomer('an.typo-' . uniqid() . '@example.com');
        $someoneElse = 'binh.other-' . uniqid() . '@example.com';
        $this->table('shop_order')->where('id', $ids['order'])->update(['email' => $someoneElse]);
        $theirOrder = $this->insertOrder('0', $someoneElse, ShopOrderStatus::DONE);
        $this->table('front_subscribe')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'email' => $someoneElse, 'status' => 1, 'store_id' => GP247_STORE_ID_ROOT]);

        $this->eraseOnScreen($ids['customer'])->assertSet('erasureStatus', 'done');

        $this->assertSame(self::PHONE, $this->table('shop_order')->where('id', $theirOrder)->value('phone'));
        $this->assertSame(1, $this->table('front_subscribe')->where('email', $someoneElse)->count());
        $this->assertNull($this->table('shop_order')->where('id', $ids['order'])->value('email'), 'the customer\'s own order is still made anonymous');
    }

    public function test_a_second_erasure_of_the_same_customer_follows_the_running_one(): void
    {
        $ids = $this->seedCustomer('an.twice-' . uniqid() . '@example.com');
        $running = app(ErasureService::class)->request(DataSubject::customer($ids['customer']), 'admin', runNow: false);

        $this->signInAdministrator();
        $screen = Livewire::test(CustomerManager::class)->call('delete', $ids['customer'])->call('confirmErasure')
            ->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');

        $this->assertSame($running->jobId, $screen->get('erasureJobId'));
        $this->assertSame(1, $this->table('personal_data_job')->where('subject_customer_id', $ids['customer'])->count());
    }

    public function test_the_box_cannot_drive_the_erasure_job_of_another_customer(): void
    {
        $mine = $this->seedCustomer('an.mine-' . uniqid() . '@example.com');
        $other = $this->seedCustomer('an.other-' . uniqid() . '@example.com');
        $job = app(ErasureService::class)->request(DataSubject::customer($other['customer']), 'admin', runNow: false);
        $this->signInAdministrator();

        Livewire::test(CustomerManager::class)->call('delete', $mine['customer'])
            ->set('erasureJobId', $job->jobId)->set('erasureStatus', 'queued')
            ->call('tickErasure')
            ->assertSet('erasureOpen', false);

        $this->assertSame('queued', $this->table('personal_data_job')->where('id', $job->jobId)->value('status'));
    }

    public function test_re_confirmations_are_logged_and_the_progress_poll_is_not(): void
    {
        $ids = $this->seedCustomer('an.log-' . uniqid() . '@example.com');
        $user = $this->signInAdministrator();

        Livewire::test(CustomerManager::class)->call('delete', $ids['customer'])->call('confirmErasure')
            ->set('stepUpPassword', 'not the password')->call('confirmStepUp');

        $row = $this->table('admin_log')->where('user_id', $user->id)->where('method', 'PRIVACY')->orderByDesc('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('refused', (string) $row->input);
        $this->assertStringNotContainsString('not the password', (string) $row->input);
        $this->assertContains('tickErasure', array_map('trim', explode(',', (string) config('gp247-config.admin.admin_log_livewire_except'))));
    }

    public function test_a_guest_order_placed_with_the_same_email_is_made_anonymous_too_and_other_customers_are_untouched(): void
    {
        $ids = $this->seedCustomer('an.guest-' . uniqid() . '@example.com');
        $other = $this->insertOrder('0', 'someone.else-' . uniqid() . '@example.com', ShopOrderStatus::DONE);

        $this->eraseOnScreen($ids['customer']);

        $this->assertNull($this->table('shop_order')->where('id', $ids['guest_order'])->value('email'));
        $this->assertSame(ColumnRule::MARKER, $this->table('shop_order')->where('id', $ids['guest_order'])->value('first_name'));
        $this->assertSame(self::PHONE, $this->table('shop_order')->where('id', $other)->value('phone'));
    }

    public function test_a_customer_with_an_order_in_progress_is_not_erased_and_the_order_is_named(): void
    {
        $ids = $this->seedCustomer('an.open-' . uniqid() . '@example.com', ShopOrderStatus::PROCESSING);
        $this->signInAdministrator();

        $screen = Livewire::test(CustomerManager::class)->call('delete', $ids['customer']);

        $blockers = $screen->get('erasureBlockers');
        $this->assertSame([OpenOrderBlocker::OPEN_ORDER], array_values(array_unique(array_column($blockers, 'reason'))));
        $this->assertSame([$ids['order']], array_column($blockers, 'ref'));
        $screen->call('confirmErasure')->set('stepUpPassword', self::ADMIN_PASSWORD)->call('confirmStepUp');
        $this->assertSame(1, $this->table('shop_customer')->where('id', $ids['customer'])->count());
        $this->assertSame(self::PHONE, $this->table('shop_order')->where('id', $ids['order'])->value('phone'));
    }

    public function test_a_wrong_password_erases_nothing(): void
    {
        $ids = $this->seedCustomer('an.wrong-' . uniqid() . '@example.com');
        $this->signInAdministrator();

        Livewire::test(CustomerManager::class)
            ->call('delete', $ids['customer'])
            ->call('confirmErasure')
            ->set('stepUpPassword', 'not the password')
            ->call('confirmStepUp')
            ->assertSet('stepUpOpen', true)
            ->assertSet('erasureJobId', null);

        $this->assertSame(1, $this->table('shop_customer')->where('id', $ids['customer'])->count());
    }

    public function test_an_administrator_without_the_delete_right_cannot_open_or_confirm_the_erasure(): void
    {
        $ids = $this->seedCustomer('an.denied-' . uniqid() . '@example.com');
        $this->asUser(viewAll: true);

        $this->assertDenied(fn () => Livewire::test(CustomerManager::class)->call('delete', $ids['customer']));
        $this->assertDenied(fn () => Livewire::test(CustomerManager::class)->set('erasureCustomerId', $ids['customer'])->call('confirmErasure'));
        $this->assertSame(1, $this->table('shop_customer')->where('id', $ids['customer'])->count());
    }

    /**
     * @param array<string, string> $ids
     * @return array<string, mixed> Money, quantities and statuses of the customer's order rows.
     */
    private function moneySnapshot(array $ids): array
    {
        $pick = fn ($row, array $cols) => array_intersect_key((array) $row, array_flip($cols));

        return [
            'order' => $pick($this->table('shop_order')->where('id', $ids['order'])->first(), ['subtotal', 'shipping', 'discount', 'tax', 'total', 'received', 'balance', 'status', 'payment_status', 'currency']),
            'lines' => $this->table('shop_order_detail')->where('order_id', $ids['order'])->get(['name', 'qty', 'price', 'total_price'])->map(fn ($r) => (array) $r)->all(),
            'totals' => $this->table('shop_order_total')->where('order_id', $ids['order'])->pluck('value', 'code')->all(),
            'transaction' => $this->table('shop_order_transaction')->where('order_id', $ids['order'])->get(['amount', 'amount_base', 'type'])->map(fn ($r) => (array) $r)->all(),
            'request' => $pick($this->table('payment_request')->where('id', $ids['request'])->first(), ['amount', 'settled_amount', 'status']),
            'movement' => $this->table('payment_movement')->where('request_id', $ids['request'])->get(['amount', 'reference'])->map(fn ($r) => (array) $r)->all(),
        ];
    }
}
