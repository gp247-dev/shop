<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Core\Mail\SendMail;
use GP247\Core\Models\AdminConfig;
use GP247\Core\PersonalData\Application\ErasureService;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Infrastructure\Export\ExportStorage;
use GP247\Core\PersonalData\Infrastructure\Requests\PersonalDataInstaller;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Tests\Feature\CustomerPrivacy\Support\SeedsCustomerData;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The request form of shoppers without an account: the same answer for every address, the
 * instructions only in the mailbox of that address, a link that proves it, then a package to
 * download or an erasure waiting for the shop. Off, or with outgoing email off, the page does not exist.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-guest-request, US-personal-data-requests-guest-approval, US-customer-privacy-customer-emails
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class GuestPrivacyRequestTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsCustomerData;

    private const GUEST = 'hoa.le@example.com';

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
        $this->configure('email_action_mode', '1');
        $this->configure('email_action_queue', '0');
        $this->table('admin_config')->where('key', 'personal_data_guest_form')->update(['value' => '1']);
        foreach ([self::GUEST, 'nobody@example.com', 'an.nguyen@example.com'] as $email) {
            RateLimiter::clear('privacy-request:' . hash('sha256', $email));
        }
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
     * @param string $key
     * @param string $value
     * @return void
     */
    private function configure(string $key, string $value): void
    {
        $this->table('admin_config')->where('key', $key)->update(['value' => $value]);
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $prop => $empty) {
            (new \ReflectionProperty(AdminConfig::class, $prop))->setValue(null, $empty);
        }
    }

    /**
     * @return void A guest order under GUEST (no account).
     */
    private function seedGuestOrder(): void
    {
        $this->insertOrder('0', self::GUEST, ShopOrderStatus::DONE);
    }

    /**
     * @param string $email
     * @param string $kind
     * @return \Illuminate\Testing\TestResponse
     */
    private function ask(string $email, string $kind = 'export')
    {
        $response = $this->post(gp247_route_front('shop.privacy_request_post'), ['email' => $email, 'kind' => $kind]);
        // The form only stores the submission; the lazy scheduler looks it up later.
        app(RequestScheduler::class)->tick(force: true);

        return $response;
    }

    /**
     * @param string $subjectKey
     * @return list<SendMail>
     */
    private function mails(string $subjectKey): array
    {
        $found = [];
        Mail::assertSent(SendMail::class, function (SendMail $mail) use ($subjectKey, &$found) {
            if (($mail->config['subject'] ?? null) === gp247_language_render($subjectKey)) {
                $found[] = $mail;
            }

            return true;
        });

        return $found;
    }

    public function test_the_form_answers_the_same_whether_the_address_has_data_an_account_or_nothing(): void
    {
        $this->seedGuestOrder();
        $this->seedCustomer('an.nguyen@example.com');

        $answers = [];
        foreach ([self::GUEST, 'nobody@example.com', 'an.nguyen@example.com'] as $email) {
            $response = $this->ask($email);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Location'), session('privacy_success')];
        }

        $this->assertCount(1, array_unique(array_map('serialize', $answers)), 'one answer for every address');
        $this->assertCount(1, $this->mails('email.privacy.verify_export.subject'), 'only the address with data gets a link');
        $this->assertSame(self::GUEST, $this->mails('email.privacy.verify_export.subject')[0]->config['to']);
        $this->assertCount(1, $this->mails('email.privacy.sign_in.subject'), 'the address with an account is told to sign in');
    }

    public function test_a_bot_filling_the_hidden_field_gets_the_same_answer_and_nothing_happens(): void
    {
        $this->seedGuestOrder();

        $this->post(gp247_route_front('shop.privacy_request_post'), ['email' => self::GUEST, 'kind' => 'export', 'website' => 'http://spam.example'])->assertSessionHas('privacy_success');

        $this->assertSame(0, $this->table('personal_data_request')->count());
    }

    public function test_asking_again_and_again_for_one_address_sends_the_link_once_an_hour(): void
    {
        $this->seedGuestOrder();

        for ($i = 0; $i < 5; $i++) {
            $this->ask(self::GUEST)->assertSessionHas('privacy_success');
        }

        $this->assertCount(1, $this->mails('email.privacy.verify_export.subject'));
        $this->assertSame(1, $this->table('personal_data_request')->count(), 'one request for the address');
    }

    public function test_the_page_does_not_exist_when_switched_off_or_when_the_site_sends_no_email(): void
    {
        $this->get(gp247_route_front('shop.privacy_request'))->assertOk()->assertSee('data-testid="customer-privacy-request-form"', false);

        $this->table('admin_config')->where('key', 'personal_data_guest_form')->update(['value' => '0']);
        $this->get(gp247_route_front('shop.privacy_request'))->assertNotFound();

        $this->table('admin_config')->where('key', 'personal_data_guest_form')->update(['value' => '1']);
        $this->configure('email_action_mode', '0');
        $this->get(gp247_route_front('shop.privacy_request'))->assertNotFound();
    }

    public function test_the_email_link_of_an_erasure_leaves_it_waiting_for_the_shop_which_is_told(): void
    {
        $this->seedGuestOrder();
        $this->ask(self::GUEST, 'erase');
        $link = (string) $this->mails('email.privacy.verify_erase.subject')[0]->dataView['url'];

        $this->get($link)->assertOk()->assertSee('data-testid="customer-privacy-request-confirm"', false);
        $this->assertSame('pending_verification', $this->table('personal_data_request')->value('status'), 'opening the link (a mail scanner) proves nothing');
        $this->post($link)->assertOk()->assertSee(gp247_language_render('customer.privacy.guest_waiting_approval'));

        $this->assertSame('pending_approval', $this->table('personal_data_request')->value('status'));
        $this->assertCount(1, $this->mails('email.privacy.admin_approval.subject'));
        $this->get($link . 'x')->assertForbidden();
    }

    public function test_the_email_link_of_a_copy_prepares_the_package_which_the_page_then_downloads(): void
    {
        if (!ExportService::zipAvailable()) {
            $this->markTestSkipped('The PHP zip extension is missing on this machine.');
        }
        $this->seedGuestOrder();
        $this->ask(self::GUEST, 'export');
        $link = (string) $this->mails('email.privacy.verify_export.subject')[0]->dataView['url'];

        $this->get($link)->assertOk();
        $page = $this->post($link)->assertOk();
        $status = (string) $this->table('personal_data_export')->value('status');
        for ($i = 0; $i < 20 && $status === 'building'; $i++) {
            app(ExportService::class)->continue((string) $this->table('personal_data_export')->value('id'));
            $status = (string) $this->table('personal_data_export')->value('status');
        }
        $this->assertSame('ready', $status);
        $ready = $this->mails('email.privacy.export_ready.subject');
        $this->assertCount(1, $ready);
        $emailLink = (string) $ready[0]->dataView['url'];

        $this->get($emailLink)->assertOk()->assertSee('data-testid="customer-privacy-request-download"', false);
        $this->post($emailLink)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->post($emailLink . 'x')->assertForbidden();
        $this->assertNotNull($page);
    }
}
