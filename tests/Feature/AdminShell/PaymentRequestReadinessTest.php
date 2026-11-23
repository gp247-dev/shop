<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Shop\Admin\Livewire\PaymentRequestManager;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\PaymentRequestService;
use GP247\Shop\Payment\PurposeRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\AdminShell\Support\PaymentRequestFixtures;
use GP247\Shop\Tests\Feature\AdminShell\Support\RecordingPurposeResolver;
use GP247\Shop\Tests\TestCase;

/**
 * Nothing assumes the payment request tables, or a purpose owner's tables, are there:
 * a site whose code is newer than its database (composer update without gp247:shop-update,
 * a package whose install was never run) shows a notice and hides the entry points
 * instead of failing with an SQL error.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-owner-validation
 * @aidlc-story US-payment-request-admin-screen
 */
class PaymentRequestReadinessTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentRequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentRequest();
    }

    protected function tearDown(): void
    {
        PaymentRequestService::forgetReadiness();
        \GP247\Shop\Tests\Feature\AdminShell\Support\UnavailablePurpose::$available = true;
        parent::tearDown();
    }

    public function test_readiness_follows_the_tables(): void
    {
        PaymentRequestService::forgetReadiness();
        $this->assertTrue(PaymentRequestService::ready());

        $this->pretendNotReady();
        $this->assertFalse(PaymentRequestService::ready());
    }

    public function test_the_screen_explains_the_missing_upgrade_instead_of_failing(): void
    {
        $this->asScreenUser();
        $this->pretendNotReady();

        Livewire::test(PaymentRequestManager::class)
            ->assertSeeHtml('data-testid="payment-request-not-ready"')
            // The tables ship with the shop's upgrade migrations (gp247:update runs core's only).
            ->assertSee('gp247:shop-update')
            ->assertDontSeeHtml('data-testid="payment-request-save"');
    }

    public function test_a_purpose_whose_owner_is_not_installed_is_not_offered_and_takes_no_owner_call(): void
    {
        $purposes = (array) config('gp247-config.payment.purposes');
        $purposes['test.unready'] = [
            'label' => 'Owner not installed',
            'directions' => ['in'],
            'resolver' => RecordingPurposeResolver::class,
            'available' => [\GP247\Shop\Tests\Feature\AdminShell\Support\UnavailablePurpose::class, 'available'],
        ];
        config(['gp247-config.payment.purposes' => $purposes]);
        $registry = app(PurposeRegistry::class);

        $this->assertArrayHasKey('test.unready', $registry->optionsFor('in'));
        $request = $this->makeRequest(['purpose' => 'test.unready']);

        \GP247\Shop\Tests\Feature\AdminShell\Support\UnavailablePurpose::$available = false;
        $registry = new PurposeRegistry();
        $this->app->instance(PurposeRegistry::class, $registry);
        $this->assertArrayNotHasKey('test.unready', $registry->optionsFor('in'));
        $this->assertFalse($registry->isSettleable('test.unready'));

        // Money a gateway confirms still lands on the request; the absent owner is not called.
        app(PaymentRequestService::class)->recordMovement($request, [
            'type' => PaymentMovement::TYPE_COLLECT, 'amount' => 10, 'gateway' => 'test.fakepay',
            'gateway_ref' => 'fake_pi_unready_' . $request->id, 'confirmed' => true,
        ]);
        $this->assertSame(1, $this->movementsOf($request));
        $this->assertSame([], RecordingPurposeResolver::$calls);
    }
}
