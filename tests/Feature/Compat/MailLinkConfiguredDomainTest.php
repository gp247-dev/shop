<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Mail\SendMail;
use GP247\Core\Models\AdminConfig;
use GP247\Core\Models\AdminStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use GP247\Shop\Tests\TestCase;

/**
 * Links that carry a secret (password reset for administrators and customers,
 * e-mail verification) always point at the configured origin of the store that
 * serves the request — APP_URL for the root store, the store's own domain for a
 * store that has one — whatever Host the request that asked for them carried.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-link-configured-domain
 * @aidlc-adr compat-foundation_forwarded-header-trust
 */
class MailLinkConfiguredDomainTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.url' => 'https://shop.example', 'app.storeId' => GP247_STORE_ID_ROOT]);
        $this->sendMailSynchronously();
        $this->resetStoreCaches();
        $this->asRequestTo('http://evil.example/forgot');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->resetStoreCaches();

        parent::tearDown();
    }

    /**
     * Make the current request one that arrived with the given URL (and so Host).
     *
     * @param string $url
     * @return void
     */
    private function asRequestTo(string $url): void
    {
        $this->app->instance('request', Request::create($url));
        URL::setRequest($this->app['request']);
    }

    /**
     * Turn mail on and off the queue for every store (rolled back with the test
     * transaction), so the mail reaches the fake mailer in this request.
     *
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
     * Set (or clear) the domain saved for the root store (rolled back with the test).
     *
     * @param string|null $domain
     * @return void
     */
    private function setRootStoreDomain(?string $domain): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_store')
            ->where('id', GP247_STORE_ID_ROOT)->update(['domain' => $domain]);
        $this->resetStoreCaches();
    }

    /**
     * Give a new store the same mail switches as the root store.
     *
     * @param string $storeId
     * @return void
     */
    private function copyMailSettingsTo(string $storeId): void
    {
        $table = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config');
        $rows = $table->clone()->whereIn('key', ['email_action_mode', 'email_action_queue'])
            ->where('store_id', GP247_STORE_ID_ROOT)->get();
        foreach ($rows as $row) {
            $copy = (array) $row;
            unset($copy['id']);
            $copy['store_id'] = $storeId;
            $table->clone()->insert($copy);
        }
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $prop => $empty) {
            (new \ReflectionProperty(AdminConfig::class, $prop))->setValue(null, $empty);
        }
    }

    /**
     * @return void
     */
    private function resetStoreCaches(): void
    {
        foreach (['getDomainStore', 'getStoreActive', 'getAll'] as $prop) {
            if (property_exists(AdminStore::class, $prop)) {
                (new \ReflectionProperty(AdminStore::class, $prop))->setValue(null, null);
            }
        }
    }

    /**
     * @param string $key Data key of the mail view that holds the link.
     * @return string
     */
    private function sentLink(string $key): string
    {
        $link = null;
        Mail::assertSent(SendMail::class, function (SendMail $mail) use ($key, &$link) {
            $link = $mail->dataView[$key] ?? null;

            return $link !== null;
        });

        return (string) $link;
    }

    public function test_an_administrator_reset_link_asked_with_a_forged_host_points_at_app_url(): void
    {
        gp247_mail_admin_send_reset_notification('tok-admin', 'admin@shop.example');

        $link = $this->sentLink('reset_link');
        $this->assertStringStartsWith('https://shop.example/', $link);
        $this->assertStringNotContainsString('evil.example', $link);
        $this->assertStringContainsString('tok-admin', $link);
    }

    public function test_a_route_built_earlier_in_the_request_does_not_let_the_forged_host_back_in(): void
    {
        // WHY: the admin auth middleware builds route('admin.login') on every admin request,
        // so the URL generator already holds a route generator bound to the request Host.
        gp247_route_admin('admin.login');

        gp247_mail_admin_send_reset_notification('tok-after-route', 'admin@shop.example');

        $link = $this->sentLink('reset_link');
        $this->assertStringStartsWith('https://shop.example/', $link);
        $this->assertStringNotContainsString('evil.example', $link);
    }

    public function test_with_a_local_app_url_the_link_uses_the_root_store_domain_saved_at_install(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->setRootStoreDomain('shop.example');
        $this->asRequestTo('https://evil.example/forgot');

        gp247_mail_admin_send_reset_notification('tok-local', 'admin@shop.example');

        $link = $this->sentLink('reset_link');
        $this->assertStringStartsWith('https://shop.example/', $link);
        $this->assertStringNotContainsString('evil.example', $link);
    }

    public function test_with_a_local_app_url_and_no_root_store_domain_the_link_keeps_app_url(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->setRootStoreDomain(null);

        gp247_mail_admin_send_reset_notification('tok-nodomain', 'admin@shop.example');

        $this->assertStringStartsWith('http://localhost/', $this->sentLink('reset_link'));
    }

    public function test_a_reset_link_asked_on_a_store_domain_points_at_that_store_domain(): void
    {
        $storeId = (string) AdminStore::create([
            'language' => 'en', 'currency' => 'USD', 'template' => 'GP247Front',
            'code' => 'ml' . substr(uniqid(), -10), 'domain' => 'store2.example', 'status' => 1,
        ])->id;
        $this->resetStoreCaches();
        $this->copyMailSettingsTo($storeId);
        config(['app.storeId' => $storeId]);
        $this->asRequestTo('http://store2.example/forgot');

        gp247_mail_admin_send_reset_notification('tok-store', 'admin@store2.example');

        $this->assertStringStartsWith('https://store2.example/', $this->sentLink('reset_link'));
    }

    public function test_a_customer_reset_link_asked_with_a_forged_host_points_at_app_url(): void
    {
        gp247_customer_sendmail_reset_notification('tok-customer', 'buyer@shop.example');

        $link = $this->sentLink('reset_link');
        $this->assertStringStartsWith('https://shop.example/', $link);
        $this->assertStringNotContainsString('evil.example', $link);
    }

    public function test_a_verification_link_points_at_app_url_and_its_signature_holds_there(): void
    {
        gp247_customer_sendmail_verify('buyer@shop.example', '123');

        $link = $this->sentLink('url_verify');
        $this->assertStringStartsWith('https://shop.example/', $link);
        $this->assertTrue(URL::hasValidSignature(Request::create($link)), 'the signed link must validate on the configured domain');
    }

    public function test_the_page_being_served_keeps_its_own_root_after_a_link_is_built(): void
    {
        $this->asRequestTo('http://www.shop.example/forgot');

        gp247_mail_admin_send_reset_notification('tok-alias', 'admin@shop.example');

        $this->assertSame('http://www.shop.example', url('/'));
        $this->assertStringStartsWith('https://shop.example/', $this->sentLink('reset_link'));
    }
}
