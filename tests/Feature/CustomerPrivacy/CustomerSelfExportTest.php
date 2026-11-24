<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\Feature\CustomerPrivacy\Support\SeedsCustomerData;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "My data": a signed-in customer confirms their password and downloads, once, a package of
 * everything the shop holds about them — on the website and through the API — and never
 * someone else's.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-self-export, US-customer-privacy-api
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class CustomerSelfExportTest extends TestCase
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
        if (!ExportService::zipAvailable()) {
            $this->markTestSkipped('The PHP zip extension is missing on this machine.');
        }
        RequestScheduler::forgetReady();
        Cache::flush();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->table('personal_data_export')->pluck('id') as $id) {
            ExportStorage::remove((string) $id);
        }
        parent::tearDown();
    }

    /**
     * @param string $email
     * @return ShopCustomer A seeded customer, signed in on the website.
     */
    private function signedInCustomer(string $email = 'an.nguyen@example.com'): ShopCustomer
    {
        $customer = ShopCustomer::find($this->seedCustomer($email)['customer']);
        RateLimiter::clear('personal-data-step-up-customer:' . $customer->id);
        $this->actingAs($customer, 'customer');

        return $customer;
    }

    /**
     * @return array<string, mixed> Status answer once the package is no longer being built.
     */
    private function waitReady(): array
    {
        $state = [];
        for ($i = 0; $i < 20; $i++) {
            $state = $this->getJson(gp247_route_front('customer.privacy_export_status'))->assertOk()->json('data');
            if ($state['status'] !== 'building') {
                break;
            }
        }

        return $state;
    }

    /**
     * @param string $zip ZIP bytes.
     * @return array<string, string>
     */
    private function filesOf(string $zip): array
    {
        $path = tempnam(sys_get_temp_dir(), 'pdz');
        file_put_contents($path, $zip);
        $archive = new \ZipArchive();
        $archive->open($path);
        $files = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $files[(string) $archive->getNameIndex($i)] = (string) $archive->getFromIndex($i);
        }
        $archive->close();
        @unlink($path);

        return $files;
    }

    public function test_the_account_page_offers_my_data_and_a_wrong_password_prepares_nothing(): void
    {
        $this->signedInCustomer();

        $this->get(gp247_route_front('customer.privacy'))->assertOk()->assertSee('data-testid="customer-privacy-export-form"', false);
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => 'wrong'])->assertRedirect();

        $this->assertSame(0, $this->table('personal_data_export')->count());
    }

    public function test_a_customer_downloads_their_data_with_the_button_and_it_holds_their_orders_but_no_secret(): void
    {
        $customer = $this->signedInCustomer();
        $this->table('shop_customer')->where('id', $customer->id)->update(['remember_token' => 'remember-me-secret', 'email_verified_at' => now()]);
        $customer->refresh();

        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD])->assertRedirect(gp247_route_front('customer.privacy'));
        $state = $this->waitReady();
        $this->assertSame('ready', $state['status']);
        $this->get(gp247_route_front('customer.privacy'))->assertSee('data-testid="customer-privacy-download"', false);

        $zip = $this->post(gp247_route_front('customer.privacy_export_download'));
        $zip->assertOk();
        $files = $this->filesOf((string) $zip->streamedContent());
        $tables = array_column(json_decode($files['data.json'], true)['sources'], 'rows', 'table');
        $this->assertCount(2, $tables['shop_order'] ?? [], 'the account order and, the email being verified, the guest order with that email');
        $all = implode("
", $files);
        foreach (['remember-me-secret', '$2y$', 'gw-tok-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertSame(1, $this->table('personal_data_request')->where('customer_id', $customer->id)->where('kind', 'export')->where('status', 'completed')->count());
    }

    public function test_a_broken_download_can_be_tried_again_a_few_times_then_the_package_is_gone(): void
    {
        $this->signedInCustomer();
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD]);
        $this->waitReady();

        for ($i = 0; $i < 3; $i++) {
            $this->post(gp247_route_front('customer.privacy_export_download'))->assertOk();
        }

        $this->post(gp247_route_front('customer.privacy_export_download'))->assertRedirect(gp247_route_front('customer.privacy'));
    }

    public function test_an_account_whose_email_is_not_verified_does_not_get_guest_orders_placed_with_that_email(): void
    {
        $customer = $this->signedInCustomer();
        $this->table('shop_customer')->where('id', $customer->id)->update(['email_verified_at' => null]);

        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD]);
        $this->waitReady();
        $files = $this->filesOf((string) $this->post(gp247_route_front('customer.privacy_export_download'))->streamedContent());

        $tables = array_column(json_decode($files['data.json'], true)['sources'], 'rows', 'table');
        $this->assertCount(1, $tables['shop_order'] ?? [], 'only the order of the account: anyone can sign up with the email of someone else');
    }

    public function test_another_customer_cannot_download_the_package(): void
    {
        $owner = $this->signedInCustomer();
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD]);
        $this->waitReady();

        $this->signedInCustomer('binh.tran@example.com');

        $this->post(gp247_route_front('customer.privacy_export_download'))->assertRedirect(gp247_route_front('customer.privacy'));
        $this->assertSame(0, (int) $this->table('personal_data_export')->where('customer_id', $owner->id)->value('downloads'));
    }

    public function test_a_customer_who_signs_in_with_a_two_step_code_types_it_too(): void
    {
        config(['gp247-config.api.login_challenge.customer' => [FixedCodeChallenge::class]]);
        $this->signedInCustomer();

        $this->get(gp247_route_front('customer.privacy'))->assertSee('data-testid="customer-privacy-code"', false);
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD, 'code' => '000000'])->assertSessionHas('privacy_error');
        $this->assertSame(0, $this->table('personal_data_export')->count());

        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD, 'code' => FixedCodeChallenge::CODE])->assertSessionHas('privacy_success');
        $this->assertSame(1, $this->table('personal_data_export')->count());
    }

    public function test_asking_again_within_twenty_four_hours_does_not_make_a_second_package(): void
    {
        $this->signedInCustomer();
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD]);
        $this->post(gp247_route_front('customer.privacy_export'), ['password' => self::PASSWORD]);

        $this->assertSame(1, $this->table('personal_data_export')->count());
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(gp247_route_front('customer.privacy'))->assertRedirect();
        $this->getJson(gp247_route_front('customer.privacy_export_status'))->assertRedirect();
    }

    public function test_the_app_asks_for_the_package_follows_it_and_downloads_it_a_few_times_at_most(): void
    {
        $customer = ShopCustomer::find($this->seedCustomer('an.nguyen@example.com')['customer']);
        $token = $this->tokenFor($customer, ['user']);
        $api = $this->v2Front();

        $this->apiCall('POST', $api . '/member/export', $token, ['password' => 'wrong'])->assertStatus(422);
        $created = $this->apiCall('POST', $api . '/member/export', $token, ['password' => self::PASSWORD])->assertStatus(202)->json('data');
        $status = $created['status'];
        for ($i = 0; $i < 20 && $status === 'building'; $i++) {
            $status = $this->apiCall('GET', $api . '/member/export/' . $created['export_id'], $token)->assertOk()->json('data.status');
        }
        $this->assertSame('ready', $status);

        for ($i = 0; $i < 3; $i++) {
            $this->apiCall('GET', $api . '/member/export/' . $created['export_id'] . '/download', $token)->assertOk();
        }
        $this->apiCall('GET', $api . '/member/export/' . $created['export_id'] . '/download', $token)->assertStatus(410);
    }

    public function test_the_app_of_another_customer_cannot_see_or_download_the_package(): void
    {
        $owner = ShopCustomer::find($this->seedCustomer('an.nguyen@example.com')['customer']);
        $created = $this->apiCall('POST', $this->v2Front() . '/member/export', $this->tokenFor($owner, ['user']), ['password' => self::PASSWORD])->json('data');
        $other = ShopCustomer::find($this->seedCustomer('binh.tran@example.com')['customer']);
        $token = $this->tokenFor($other, ['user']);

        $this->apiCall('GET', $this->v2Front() . '/member/export/' . $created['export_id'], $token)->assertNotFound();
        $this->apiCall('GET', $this->v2Front() . '/member/export/' . $created['export_id'] . '/download', $token)->assertNotFound();
    }
}

/**
 * A two-step sign-in check whose code is always the same (stands in for an authenticator app).
 */
final class FixedCodeChallenge implements \GP247\Core\Api\Contracts\LoginChallenge
{
    public const CODE = '424242';

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable $owner
     * @param \Illuminate\Http\Request                    $request
     * @return bool
     */
    public function required(\Illuminate\Contracts\Auth\Authenticatable $owner, \Illuminate\Http\Request $request): bool
    {
        return true;
    }

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable $owner
     * @return array<string, mixed>
     */
    public function describe(\Illuminate\Contracts\Auth\Authenticatable $owner): array
    {
        return ['type' => 'totp'];
    }

    /**
     * @param \Illuminate\Contracts\Auth\Authenticatable $owner
     * @param \Illuminate\Http\Request                    $request
     * @return bool
     */
    public function verify(\Illuminate\Contracts\Auth\Authenticatable $owner, \Illuminate\Http\Request $request): bool
    {
        return $request->input('code') === self::CODE;
    }
}
