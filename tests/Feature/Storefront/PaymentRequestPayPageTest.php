<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use GP247\Shop\Tests\Feature\AdminShell\Support\FakeCollectGateway;
use GP247\Shop\Tests\Feature\AdminShell\Support\PaymentRequestFixtures;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\TestCase;

/**
 * A payer opens the link they were sent, sees what they pay, to whom and for what,
 * and is sent to an online gateway — without an account. The page shows nothing
 * beyond what paying needs and answers every wrong link the same way.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-public-pay-page
 * @aidlc-adr payment-request_generic-money-request
 */
class PaymentRequestPayPageTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use PaymentRequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->bootPaymentRequest();
        $this->bootPublicLink();
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{0: PaymentRequest, 1: string}
     */
    private function linked(array $overrides = []): array
    {
        $request = $this->makeRequest(array_merge([
            'store_id' => (string) config('app.storeId'),
            'description' => 'ZZ Deposit for the spring event',
            'party_name' => 'ZZ Payer Co',
            'party_email' => 'zz-payer@example.test',
            'party_phone' => '0909-555-777',
        ], $overrides));
        $token = app(PaymentRequestService::class)->issuePublicLink($request);

        return [$request->fresh(), $token];
    }

    /**
     * Give a request a token directly — for states the service refuses to link.
     */
    private function forceToken(PaymentRequest $request): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $request->forceFill(['public_token_hash' => hash('sha256', $token)])->save();

        return $token;
    }

    public function test_the_payer_sees_what_they_pay_and_the_gateways_that_collect(): void
    {
        [, $token] = $this->linked();

        $this->get(route('payment_request.pay', ['token' => $token]))
            ->assertOk()
            ->assertSeeHtml('data-testid="payment-request-pay-summary"')
            ->assertSee('ZZ Deposit for the spring event')
            ->assertSee('ZZ Payer Co')
            ->assertSee('100.00')
            ->assertSee('USD')
            ->assertSeeHtml('data-testid="payment-request-pay-gateway"')
            ->assertSeeHtml('value="test.fakepay"')
            ->assertDontSeeHtml('value="manual"')
            ->assertDontSeeHtml('value="test.refund-only"')
            ->assertSeeHtml('data-testid="payment-request-pay-submit"');
    }

    public function test_the_page_never_shows_the_payers_email_or_phone(): void
    {
        [, $token] = $this->linked();

        $this->get(route('payment_request.pay', ['token' => $token]))
            ->assertOk()
            ->assertDontSee('zz-payer@example.test')
            ->assertDontSee('0909-555-777');
    }

    public function test_a_wrong_link_another_stores_request_and_money_out_all_answer_the_same_404(): void
    {
        [$request, $token] = $this->linked();
        $this->get(route('payment_request.pay', ['token' => $token . 'x']))->assertNotFound();

        $request->forceFill(['store_id' => 'zz-other-store'])->save();
        $this->get(route('payment_request.pay', ['token' => $token]))->assertNotFound();

        $out = $this->makeRequest(['direction' => 'out', 'store_id' => (string) config('app.storeId')]);
        $this->get(route('payment_request.pay', ['token' => $this->forceToken($out)]))->assertNotFound();
    }

    public function test_a_store_without_a_domain_of_its_own_is_paid_on_the_marketplace_domain(): void
    {
        config(['app.storeId' => (string) GP247_STORE_ID_ROOT]);
        $root = \GP247\Core\Models\AdminStore::find((string) GP247_STORE_ID_ROOT);
        \GP247\Core\Models\AdminStore::create(['id' => 'STO-zzpaynodom', 'code' => 'zzpaynodom', 'domain' => '', 'status' => 1,
            'language' => $root->language ?? 'en', 'currency' => $root->currency ?? 'USD']);
        [, $token] = $this->linked(['store_id' => 'STO-zzpaynodom']);

        $this->get(route('payment_request.pay', ['token' => $token]))
            ->assertOk()
            ->assertSee('ZZ Deposit for the spring event');
    }

    public function test_a_store_with_a_domain_of_its_own_is_not_paid_on_another_domain(): void
    {
        config(['app.storeId' => (string) GP247_STORE_ID_ROOT]);
        $root = \GP247\Core\Models\AdminStore::find((string) GP247_STORE_ID_ROOT);
        \GP247\Core\Models\AdminStore::create(['id' => 'STO-zzpaydom', 'code' => 'zzpaydom', 'domain' => 'zz-shop-b.test', 'status' => 1,
            'language' => $root->language ?? 'en', 'currency' => $root->currency ?? 'USD']);
        [, $token] = $this->linked(['store_id' => 'STO-zzpaydom']);

        $this->get(route('payment_request.pay', ['token' => $token]))->assertNotFound();
    }

    public function test_a_settled_cancelled_or_expired_request_shows_its_state_and_no_pay_button(): void
    {
        foreach ([
            'settled' => ['status' => PaymentRequest::STATUS_SETTLED, 'settled_amount' => 100],
            'cancelled' => ['status' => PaymentRequest::STATUS_CANCELLED, 'cancelled_at' => now()],
            'expired' => ['expires_at' => now()->subDay()],
        ] as $state => $overrides) {
            $request = $this->makeRequest(array_merge(['store_id' => (string) config('app.storeId')], $overrides));

            $this->get(route('payment_request.pay', ['token' => $this->forceToken($request)]))
                ->assertOk()
                ->assertSeeHtml('data-testid="payment-request-pay-state"')
                ->assertSeeHtml('data-state="' . $state . '"')
                ->assertDontSeeHtml('data-testid="payment-request-pay-submit"');
        }
    }

    public function test_without_a_collecting_gateway_the_payer_is_told_to_contact_the_store(): void
    {
        config(['gp247-config.payment.gateways' => ['manual' => config('gp247-config.payment.gateways.manual')]]);
        [, $token] = $this->linked();

        $this->get(route('payment_request.pay', ['token' => $token]))
            ->assertOk()
            ->assertSeeHtml('data-state="no_gateway"')
            ->assertDontSeeHtml('data-testid="payment-request-pay-submit"');
    }

    public function test_choosing_a_gateway_sends_the_payer_to_its_hosted_page(): void
    {
        [$request, $token] = $this->linked();

        $this->post(route('payment_request.pay.collect', ['token' => $token]), ['gateway' => 'test.fakepay'])
            ->assertRedirect('https://pay.example.test/session/' . $request->id);

        $this->assertSame([$request->id], FakeCollectGateway::$collected);
        $this->assertSame('test.fakepay', $request->fresh()->gateway);
    }

    public function test_a_gateway_that_does_not_collect_is_refused_without_calling_it(): void
    {
        [, $token] = $this->linked();
        $page = route('payment_request.pay', ['token' => $token]);

        foreach (['manual', 'test.refund-only', 'nope'] as $gateway) {
            $this->from($page)
                ->post(route('payment_request.pay.collect', ['token' => $token]), ['gateway' => $gateway])
                ->assertRedirect($page)
                ->assertSessionHas('error');
        }
        $this->assertSame([], FakeCollectGateway::$collected);
    }

    public function test_a_gateway_failure_brings_the_payer_back_with_a_message(): void
    {
        FakeCollectGateway::$throws = true;
        [$request, $token] = $this->linked();
        $page = route('payment_request.pay', ['token' => $token]);

        $this->from($page)
            ->post(route('payment_request.pay.collect', ['token' => $token]), ['gateway' => 'test.fakepay'])
            ->assertRedirect($page)
            ->assertSessionHas('error');
        $this->assertNull($request->fresh()->gateway_ref);
    }

    public function test_paying_a_settled_request_is_refused(): void
    {
        $request = $this->makeRequest(['store_id' => (string) config('app.storeId'), 'status' => PaymentRequest::STATUS_SETTLED, 'settled_amount' => 100]);
        $token = $this->forceToken($request);
        $page = route('payment_request.pay', ['token' => $token]);

        $this->from($page)
            ->post(route('payment_request.pay.collect', ['token' => $token]), ['gateway' => 'test.fakepay'])
            ->assertRedirect($page)
            ->assertSessionHas('error');
        $this->assertSame([], FakeCollectGateway::$collected);
    }

    /**
     * A site on another template may never have published the default template's CSS/JS:
     * the default page is a whole document of its own, styled inline.
     */
    public function test_the_default_page_stands_alone_without_any_template_asset(): void
    {
        [, $token] = $this->linked();

        $html = $this->get(route('payment_request.pay', ['token' => $token]))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="payment-request-pay-page"', $html);
        $this->assertStringContainsString('<style', $html, 'styled inline');
        $this->assertStringNotContainsString('GP247/Templates/', $html, 'no template stylesheet, script or image');
        $this->assertMatchesRegularExpression('/<meta name="robots" content="noindex, nofollow">/', $html, 'a payment link is never indexed');
    }

    /**
     * A site customises the page by creating screen/shop_payment_request.blade.php in its
     * own template — nothing to change in the package or in any other template.
     */
    public function test_a_template_can_override_the_page_with_its_own_file(): void
    {
        [, $token] = $this->linked();
        $root = sys_get_temp_dir() . '/gp247-pr-override-' . bin2hex(random_bytes(4));
        $file = $root . '/' . gp247_store_info('template') . '/screen/shop_payment_request.blade.php';
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, '<p data-testid="zz-template-override">{{ $outstanding }}</p>');
        view()->getFinder()->prependNamespace('GP247TemplatePath', $root);
        view()->getFinder()->flush();

        try {
            $this->get(route('payment_request.pay', ['token' => $token]))
                ->assertOk()
                ->assertSeeHtml('data-testid="zz-template-override"')
                ->assertSee('100.00')
                ->assertDontSeeHtml('data-testid="payment-request-pay-page"');
        } finally {
            @unlink($file);
            @rmdir(dirname($file));
            @rmdir(dirname($file, 2));
            @rmdir($root);
            view()->getFinder()->flush();
        }
    }

    public function test_both_public_routes_are_rate_limited(): void
    {
        foreach (['payment_request.pay', 'payment_request.pay.collect'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "{$name} routed");
            $throttled = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertTrue($throttled, "{$name} is throttled");
        }
    }
}
