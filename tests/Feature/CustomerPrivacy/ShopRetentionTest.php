<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use Carbon\CarbonImmutable;
use GP247\Core\Mail\SendMail;
use GP247\Core\Models\AdminConfig;
use GP247\Core\PersonalData\Application\Erasure\ErasureJobRunner;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\PrivacyRequests;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Application\Retention\RetentionPolicies;
use GP247\Core\PersonalData\Application\Retention\RetentionSweeper;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Infrastructure\JobRepository;
use GP247\Core\PersonalData\Infrastructure\PersonalDataSchema;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Core\PersonalData\Infrastructure\Retention\RetentionStateRepository;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Privacy\CustomerActivity;
use GP247\Shop\Privacy\ShopRetention;
use GP247\Shop\Tests\Feature\CustomerPrivacy\Support\SeedsCustomerData;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

/**
 * How long the shop keeps customers' data: carts untouched for 180 days and expired reset codes
 * are cleaned; closed orders past their period lose the buyer's details while amounts, lines and
 * totals stay; accounts unused for the period are told, kept by signing in again, held while an
 * order is open.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-personal-data-retention-policies, US-personal-data-retention-order-anonymise, US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_record-anonymise, personal-data-retention_inactive-accounts
 */
#[Group('personal-data-retention')]
class ShopRetentionTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsCustomerData;

    private CarbonImmutable $now;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        RequestScheduler::forgetReady();
        ErasureService::forgetReady();
        RetentionStateRepository::forgetReady();
        CustomerActivity::forget();
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        Cache::flush();
        Mail::fake();
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $table->clone()->where('key', 'email_action_mode')->update(['value' => '1']);
        $table->clone()->where('key', 'email_action_queue')->update(['value' => '0']);
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $prop => $empty) {
            (new \ReflectionProperty(AdminConfig::class, $prop))->setValue(null, $empty);
        }
        $this->now = CarbonImmutable::now()->startOfSecond();
        app(RetentionSweeper::class)->states($this->now);
        $this->table(PersonalDataSchema::RETENTION)->update(['first_allowed_at' => $this->now->subMinute()]);
    }

    public function test_carts_untouched_for_180_days_and_expired_reset_codes_are_cleaned_but_recent_ones_stay(): void
    {
        $old = $this->seedCustomer('old.cart@example.com')['customer'];
        $recent = $this->seedCustomer('recent.cart@example.com')['customer'];
        $this->table('shop_shoppingcart')->where('identifier', $old)->update(['updated_at' => $this->now->subDays(200)]);
        $this->table('shop_shoppingcart')->where('identifier', $recent)->update(['updated_at' => $this->now->subDays(20)]);
        $this->table('shop_password_resets')->where('email', 'old.cart@example.com')->update(['created_at' => $this->now->subHours(3)]);

        $this->slice('shop.carts');
        $this->slice('shop.password_resets');

        $this->assertFalse($this->table('shop_shoppingcart')->where('identifier', $old)->exists());
        $this->assertTrue($this->table('shop_shoppingcart')->where('identifier', $recent)->exists());
        $this->assertFalse($this->table('shop_password_resets')->where('email', 'old.cart@example.com')->exists());
        $this->assertTrue($this->table('shop_password_resets')->where('email', 'recent.cart@example.com')->exists(), 'a code still valid stays');
    }

    public function test_closed_orders_past_their_period_lose_the_buyers_details_while_amounts_lines_and_totals_stay(): void
    {
        $ids = $this->seedCustomer('old.order@example.com', ShopOrderStatus::DONE);
        $this->table('shop_order')->where('id', $ids['order'])->update(['created_at' => $this->now->subYears(11)]);
        $open = $this->insertOrder($ids['customer'], 'old.order@example.com', ShopOrderStatus::PROCESSING);
        $this->table('shop_order')->where('id', $open)->update(['created_at' => $this->now->subYears(11)]);
        $item = PersonalDataRegistry::retentionItem('shop.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);
        $before = [(string) $this->table('shop_order')->where('id', $ids['order'])->value('total'), $this->table('shop_order_detail')->where('order_id', $ids['order'])->count(), (string) $this->table('shop_order_total')->where('order_id', $ids['order'])->sum('value')];

        $this->assertSame(1, $this->slice('shop.orders')['rows'], 'the order not closed is not picked');
        $job = app(JobRepository::class)->activeOfKind(JobRepository::KIND_RETENTION);
        $this->assertSame(JobRepository::DONE, app(ErasureJobRunner::class)->runSlice((string) $job, 60));

        $order = $this->table('shop_order')->where('id', $ids['order'])->first();
        $this->assertSame(ColumnRule::MARKER, $order->first_name);
        $this->assertNull($order->email);
        $this->assertNull($order->phone);
        $this->assertNull($order->address1);
        $this->assertSame($before, [(string) $order->total, $this->table('shop_order_detail')->where('order_id', $ids['order'])->count(), (string) $this->table('shop_order_total')->where('order_id', $ids['order'])->sum('value')]);
        $history = (string) $this->table('shop_order_history')->where('order_id', $ids['order'])->value('content');
        $this->assertStringNotContainsString(self::PHONE, $history);
        $this->assertStringContainsString('1700000', $history);
        $this->assertSame(ColumnRule::MARKER, $this->table('payment_request')->where('id', $ids['request'])->value('party_name'));
        $this->assertSame('Nguyen', $this->table('shop_order')->where('id', $open)->value('first_name'), 'an order still in progress keeps its buyer');
        $this->assertTrue($this->table('shop_customer')->where('id', $ids['customer'])->exists(), 'anonymising old orders is not erasing the customer');
        $this->assertSame(0, app(RetentionSweeper::class)->dueCount($item), 'an anonymised order is not picked again');
    }

    public function test_a_closed_order_whose_shipment_or_payment_is_still_open_is_not_anonymised(): void
    {
        $ids = $this->seedCustomer('still.moving@example.com', ShopOrderStatus::DONE);
        $this->table('shop_order')->where('id', $ids['order'])->update(['created_at' => $this->now->subYears(11)]);
        $this->table('shop_shipment')->where('id', $ids['shipment'])->update(['status' => 'in_transit']);
        $item = PersonalDataRegistry::retentionItem('shop.orders');
        app(RetentionPolicies::class)->save($item, true, 3650);

        $this->assertSame(0, app(RetentionSweeper::class)->dueCount($item));

        $this->table('shop_shipment')->where('id', $ids['shipment'])->update(['status' => 'delivered']);
        $this->table('payment_request')->where('id', $ids['request'])->update(['status' => 'open']);
        $this->assertSame(0, app(RetentionSweeper::class)->dueCount($item));

        $this->table('payment_request')->where('id', $ids['request'])->update(['status' => 'settled']);
        $this->assertSame(1, app(RetentionSweeper::class)->dueCount($item));
    }

    public function test_an_account_used_in_the_app_during_the_notice_is_kept_when_the_wait_is_over(): void
    {
        $customer = $this->customer('app.user@example.com', created: $this->now->subYears(5), lastLogin: $this->now->subYears(4));
        app(CustomerErasures::class)->scheduleForRetention($customer, '1', $this->now->subMinute(), 1095);
        $request = $this->table(PersonalDataSchema::REQUEST)->where('customer_id', $customer)->first();
        $this->table(PersonalDataSchema::REQUEST)->where('id', $request->id)->update(['received_at' => $this->now->subDays(31)]);
        $this->table('api_session')->insert(['id' => (string) Str::uuid(), 'tokenable_type' => (new ShopCustomer())->getMorphClass(), 'tokenable_id' => $customer,
            'audience' => 'customer', 'refreshable' => 1, 'last_used_at' => $this->now->subDays(3)]);

        $result = app(PrivacyRequests::class)->execute((string) $request->id, ['type' => 'system']);

        $this->assertSame('cancelled', $result['status']);
        $this->assertTrue($this->table('shop_customer')->where('id', $customer)->exists());
    }

    public function test_the_preview_counts_unused_accounts_in_the_database(): void
    {
        $item = PersonalDataRegistry::retentionItem('shop.inactive_accounts');
        $before = app(RetentionSweeper::class)->dueCount($item, $this->now, 1095);
        $this->customer('count.me@example.com', created: $this->now->subYears(5), lastLogin: $this->now->subYears(4));

        $this->assertSame($before + 1, app(RetentionSweeper::class)->dueCount($item, $this->now, 1095));
    }

    public function test_unused_accounts_are_found_by_their_last_sign_in_orders_and_app_use(): void
    {
        $since = $this->now->subYears(3);
        $signedInLongAgo = $this->customer('a@example.com', created: $this->now->subYears(5), lastLogin: $this->now->subYears(4));
        $recentOrder = $this->customer('b@example.com', created: $this->now->subYears(5), lastLogin: $this->now->subYears(4));
        $this->insertOrder($recentOrder, 'b@example.com', ShopOrderStatus::DONE);
        $neverSignedIn = $this->customer('c@example.com', created: $this->now->subYears(4), lastLogin: null);
        $appUser = $this->customer('d@example.com', created: $this->now->subYears(5), lastLogin: $this->now->subYears(4));
        $this->table('api_session')->insert(['id' => (string) Str::uuid(), 'tokenable_type' => (new ShopCustomer())->getMorphClass(), 'tokenable_id' => $appUser,
            'audience' => 'customer', 'refreshable' => 0, 'last_used_at' => $this->now->subMonth()]);
        $newAccount = $this->customer('e@example.com', created: $this->now->subYear(), lastLogin: null);

        $found = array_column(ShopRetention::unusedAccounts($since, null, 100000), 'id');

        $this->assertContains($signedInLongAgo, $found);
        $this->assertContains($neverSignedIn, $found);
        $this->assertNotContains($recentOrder, $found);
        $this->assertNotContains($appUser, $found);
        $this->assertNotContains($newAccount, $found);
    }

    public function test_an_unused_account_is_told_and_signing_in_on_the_website_keeps_it(): void
    {
        $customer = ShopCustomer::find($this->seedCustomer('keep.me@example.com')['customer']);
        $customer->forceFill(['email_verified_at' => now()])->save();
        $this->assertTrue(app(CustomerErasures::class)->scheduleForRetention((string) $customer->id, '1', $this->now->addDays(30), 1095));
        $request = $this->table(PersonalDataSchema::REQUEST)->where('customer_id', $customer->id)->first();
        $this->assertSame('retention', $request->source);
        $notices = Mail::sent(SendMail::class)->filter(fn (SendMail $m) => ($m->config['subject'] ?? null) === gp247_language_render('email.privacy.inactive.subject'));
        $this->assertCount(1, $notices);
        $this->assertSame('keep.me@example.com', $notices->first()->config['to']);

        $this->post(gp247_route_front('customer.postLogin'), ['email' => 'keep.me@example.com', 'password' => 'secret123']);

        $this->assertSame('cancelled', $this->table(PersonalDataSchema::REQUEST)->where('id', $request->id)->value('status'));
        $this->assertNotNull($this->table('shop_customer')->where('id', $customer->id)->value('last_login_at'));
    }

    public function test_an_unused_account_with_an_order_still_open_is_held_not_erased(): void
    {
        $ids = $this->seedCustomer('busy@example.com', ShopOrderStatus::PROCESSING);
        // WHY old dates: an order placed during the notice would count as activity and keep the account.
        $this->table('shop_order')->where('customer_id', $ids['customer'])->update(['created_at' => $this->now->subYears(4)]);
        $this->table('shop_customer')->where('id', $ids['customer'])->update(['created_at' => $this->now->subYears(5)]);
        app(CustomerErasures::class)->scheduleForRetention($ids['customer'], '1', $this->now->subMinute(), 1095);
        $id = (string) $this->table(PersonalDataSchema::REQUEST)->where('customer_id', $ids['customer'])->value('id');

        $result = app(PrivacyRequests::class)->execute($id, ['type' => 'system']);

        $this->assertSame('on_hold', $result['status']);
        $this->assertTrue($this->table('shop_customer')->where('id', $ids['customer'])->exists());
    }

    /**
     * @param string $key
     * @return array{rows: int, more: bool, error: string|null}
     */
    private function slice(string $key): array
    {
        $result = app(RetentionSweeper::class)->slice(PersonalDataRegistry::retentionItem($key), $this->now);
        $this->assertNull($result['error'], $key);

        return $result;
    }

    /**
     * @param string               $email
     * @param CarbonImmutable      $created
     * @param CarbonImmutable|null $lastLogin
     * @return string Customer id.
     */
    private function customer(string $email, CarbonImmutable $created, ?CarbonImmutable $lastLogin): string
    {
        $id = (string) Str::uuid();
        $this->table('shop_customer')->insert([
            'id' => $id, 'first_name' => 'Test', 'email' => 'retention.' . $email, 'password' => 'x', 'store_id' => GP247_STORE_ID_ROOT, 'status' => 1,
            'created_at' => $created, 'updated_at' => $created, 'last_login_at' => $lastLogin,
        ]);

        return $id;
    }
}
