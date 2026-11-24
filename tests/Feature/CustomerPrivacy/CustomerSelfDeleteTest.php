<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Core\Mail\SendMail;
use GP247\Core\Models\AdminConfig;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\Feature\CustomerPrivacy\Support\SeedsCustomerData;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;

/**
 * "Delete my account": the customer confirms with their password, the erasure waits the days
 * set for the site with a notice and a call-off button at the top of the account area and a
 * call-off link in the email; with no wait the account goes at once and the session ends; the app
 * does the same through the API.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-self-delete, US-customer-privacy-api, US-customer-privacy-customer-emails
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class CustomerSelfDeleteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use SeedsCustomerData;

    private const PASSWORD = 'secret123';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        RequestScheduler::forgetReady();
        ErasureService::forgetReady();
        PersonalDataInstaller::ensure(DB::connection(GP247_DB_CONNECTION));
        Cache::flush();
        Mail::fake();
        $this->sendMailSynchronously();
    }

    /**
     * @return void
     */
    private function sendMailSynchronously(): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $table->clone()->where('key', 'email_action_mode')->update(['value' => '1']);
        $table->clone()->where('key', 'email_action_queue')->update(['value' => '0']);
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $prop => $empty) {
            (new \ReflectionProperty(AdminConfig::class, $prop))->setValue(null, $empty);
        }
    }

    /**
     * @param string $email
     * @return ShopCustomer Signed in on the website.
     */
    private function signedInCustomer(string $email = 'an.nguyen@example.com', bool $verified = true): ShopCustomer
    {
        $customer = ShopCustomer::find($this->seedCustomer($email)['customer']);
        if ($verified) {
            $customer->forceFill(['email_verified_at' => now()])->save();
        }
        RateLimiter::clear('personal-data-step-up-customer:' . $customer->id);
        $this->actingAs($customer, 'customer');

        return $customer;
    }

    /**
     * @param string $subjectKey
     * @return SendMail|null The first privacy email whose subject label matches.
     */
    private function mailWith(string $subjectKey): ?SendMail
    {
        $found = null;
        Mail::assertSent(SendMail::class, function (SendMail $mail) use ($subjectKey, &$found) {
            if ($found === null && ($mail->config['subject'] ?? null) === gp247_language_render($subjectKey)) {
                $found = $mail;
            }

            return true;
        });

        return $found;
    }

    public function test_a_wrong_password_deletes_nothing(): void
    {
        $customer = $this->signedInCustomer();

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => 'wrong'])->assertSessionHas('privacy_error');

        $this->assertSame(0, $this->table('personal_data_request')->where('customer_id', $customer->id)->count());
    }

    public function test_the_account_waits_with_a_notice_and_the_email_link_calls_it_off_without_signing_in(): void
    {
        $customer = $this->signedInCustomer();

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD])->assertSessionHas('privacy_success');

        $request = $this->table('personal_data_request')->where('customer_id', $customer->id)->first();
        $this->assertSame('waiting', $request->status);
        $this->get(gp247_route_front('customer.privacy'))->assertSee('data-testid="customer-privacy-delete-banner"', false);
        $mail = $this->mailWith('email.privacy.scheduled.subject');
        $this->assertNotNull($mail, 'the customer is told, with a link to call it off');
        $this->assertSame('an.nguyen@example.com', $mail->config['to']);

        $this->app['auth']->guard('customer')->logout();
        $link = (string) $mail->dataView['url'];
        $this->get($link)->assertOk()->assertSee('data-testid="customer-privacy-cancel-confirm"', false);
        $this->assertSame('waiting', $this->table('personal_data_request')->where('id', $request->id)->value('status'), 'opening the link changes nothing');
        $this->get($link . 'x')->assertForbidden();
        $this->post($link)->assertOk();

        $this->assertSame('cancelled', $this->table('personal_data_request')->where('id', $request->id)->value('status'));
    }

    public function test_an_unverified_address_gets_no_email_about_the_deletion(): void
    {
        $this->signedInCustomer('an.nguyen@example.com', verified: false);

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD])->assertSessionHas('privacy_success');

        $scheduled = Mail::sent(SendMail::class)->filter(fn (SendMail $m) => ($m->config['subject'] ?? null) === gp247_language_render('email.privacy.scheduled.subject'));
        $this->assertCount(0, $scheduled, 'anyone can sign up with that address');
        $this->get(gp247_route_front('customer.privacy'))->assertSee('data-testid="customer-privacy-delete-banner"', false);
    }

    public function test_a_failed_earlier_deletion_is_reported_and_the_customer_stays_signed_in(): void
    {
        $customer = $this->signedInCustomer();
        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD]);
        $this->table('personal_data_request')->where('customer_id', $customer->id)->update(['status' => 'failed']);

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD])->assertSessionHas('privacy_error');

        $this->assertAuthenticated('customer');
        $this->assertNotNull(ShopCustomer::find($customer->id));
    }

    public function test_the_signed_in_customer_calls_it_off_from_the_account(): void
    {
        $customer = $this->signedInCustomer();
        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD]);

        $this->post(gp247_route_front('customer.privacy_delete_cancel'))->assertSessionHas('privacy_success');

        $this->assertSame('cancelled', $this->table('personal_data_request')->where('customer_id', $customer->id)->value('status'));
        $this->get(gp247_route_front('customer.privacy'))->assertDontSee('data-testid="customer-privacy-delete-banner"', false);
    }

    public function test_with_no_wait_the_account_goes_at_once_the_session_ends_and_the_email_can_sign_up_again(): void
    {
        $this->table('admin_config')->where('key', 'personal_data_erase_wait_days')->update(['value' => '0']);
        $customer = $this->signedInCustomer();
        $this->table('shop_order')->where('customer_id', $customer->id)->update(['status' => ShopOrderStatus::DONE]);

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD])->assertRedirect(gp247_route_front('front.home'));
        for ($i = 0; $i < 20 && ShopCustomer::find($customer->id) !== null; $i++) {
            app(ErasureService::class)->resumeOne();
        }

        $this->assertNull(ShopCustomer::find($customer->id));
        $this->assertGuest('customer');
        $again = ShopCustomer::createCustomer([
            'first_name' => 'New', 'last_name' => 'Owner', 'email' => 'an.nguyen@example.com', 'password' => bcrypt('another1'), 'status' => 1, 'store_id' => GP247_STORE_ID_ROOT,
        ]);
        $this->assertNotNull($again->id);
    }

    public function test_an_open_order_holds_the_deletion_until_it_closes(): void
    {
        $this->table('admin_config')->where('key', 'personal_data_erase_wait_days')->update(['value' => '0']);
        $customer = ShopCustomer::find($this->seedCustomer('an.nguyen@example.com', ShopOrderStatus::NEW)['customer']);
        $this->actingAs($customer, 'customer');

        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD])
            ->assertSessionHas('privacy_success', gp247_language_render('customer.privacy.delete_on_hold'));

        $this->assertSame('on_hold', $this->table('personal_data_request')->where('customer_id', $customer->id)->value('status'));
        $this->assertNotNull(ShopCustomer::find($customer->id));
    }

    public function test_the_app_deletes_the_account_and_calls_it_off_through_the_api(): void
    {
        $customer = ShopCustomer::find($this->seedCustomer('an.nguyen@example.com')['customer']);
        RateLimiter::clear('personal-data-step-up-customer:' . $customer->id);
        $token = $this->tokenFor($customer, ['user']);
        $api = $this->v2Front();

        $this->apiCall('POST', $api . '/member/delete', $token, ['password' => 'wrong'])->assertStatus(422);
        $this->apiCall('POST', $api . '/member/delete', $token, ['password' => self::PASSWORD])->assertStatus(202)->assertJsonPath('data.status', 'waiting');
        $this->apiCall('GET', $api . '/member/delete', $token)->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->apiCall('DELETE', $api . '/member/delete', $token)->assertOk();
        $this->apiCall('GET', $api . '/member/delete', $token)->assertOk()->assertJsonPath('data', null);
    }

    public function test_the_signed_link_of_the_email_uses_the_configured_domain(): void
    {
        $this->signedInCustomer();
        config(['app.url' => 'https://shop.example']);
        $this->post(gp247_route_front('customer.privacy_delete'), ['password' => self::PASSWORD]);

        $url = (string) $this->mailWith('email.privacy.scheduled.subject')->dataView['url'];

        $this->assertStringNotContainsString('evil.example', $url);
        $this->assertTrue(URL::hasValidSignature(\Illuminate\Http\Request::create($url)), 'a link the site itself accepts');
    }
}
