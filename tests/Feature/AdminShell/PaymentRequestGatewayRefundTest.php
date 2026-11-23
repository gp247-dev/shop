<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Domain\AuthorizationException;
use GP247\Shop\Admin\Livewire\PaymentRequestManager;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\AdminShell\Support\FakeCollectGateway;
use GP247\Shop\Tests\Feature\AdminShell\Support\PaymentRequestFixtures;
use GP247\Shop\Tests\Feature\AdminShell\Support\RecordingPurposeResolver;
use GP247\Shop\Tests\TestCase;

/**
 * Money a gateway collected can be given back through that same gateway, never more
 * than was collected, and only by someone allowed to move money out. Whatever a
 * gateway confirms — even too much, or after the request was cancelled — lands in the
 * ledger with a note, because it really moved.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-gateway-refund
 * @aidlc-story US-payment-request-confirmed-money
 * @aidlc-adr payment-request_gateway-capability-contract
 */
class PaymentRequestGatewayRefundTest extends TestCase
{
    use DatabaseTransactions;
    use PaymentRequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootPaymentRequest();
        $this->bootPublicLink();
    }

    private function service(): PaymentRequestService
    {
        return app(PaymentRequestService::class);
    }

    /**
     * A request of 100 fully collected through the fake gateway.
     *
     * @return array{0: PaymentRequest, 1: PaymentMovement}
     */
    private function collected(array $overrides = [], float $amount = 100): array
    {
        $request = $this->makeRequest($overrides);
        $movement = $this->service()->recordMovement($request, [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => $amount,
            'gateway' => 'test.fakepay',
            'gateway_ref' => 'fake_pi_' . $request->id,
            'confirmed' => true,
        ]);

        return [$request->fresh(), $movement];
    }

    public function test_a_refund_through_the_gateway_is_recorded_against_the_collection_it_gives_back(): void
    {
        [$request, $collect] = $this->collected(['purpose' => 'test.recording']);

        $refund = $this->service()->refundViaGateway($request, $collect, 30, '1', 'goodwill');

        $this->assertSame(PaymentMovement::TYPE_REFUND, $refund->type);
        $this->assertEqualsWithDelta(30, (float) $refund->amount, 0.001);
        $this->assertSame('test.fakepay', $refund->gateway);
        $this->assertSame('fake_re_' . $collect->id . '_1', $refund->gateway_ref);
        $this->assertSame($collect->id, (int) ($refund->metadata['refund_of'] ?? 0));
        $this->assertSame('1', (string) $refund->admin_id);
        $this->assertSame([['request' => $request->id, 'original' => $collect->id, 'amount' => 30.0]], FakeCollectGateway::$refunds);

        $fresh = $request->fresh();
        $this->assertEqualsWithDelta(70, (float) $fresh->settled_amount, 0.001);
        $this->assertSame(PaymentRequest::STATUS_PARTIAL, $fresh->status);
        $this->assertSame([['request' => $request->id, 'movement' => $refund->id]], RecordingPurposeResolver::$reversed);
    }

    public function test_refunds_never_exceed_what_the_collection_took(): void
    {
        [$request, $collect] = $this->collected();

        $this->service()->refundViaGateway($request, $collect, 60);
        $this->assertEqualsWithDelta(40, $this->service()->refundableAmount($collect->fresh()), 0.001);
        $this->service()->refundViaGateway($request->fresh(), $collect, 40);
        $this->assertEqualsWithDelta(0, $this->service()->refundableAmount($collect->fresh()), 0.001);

        try {
            $this->service()->refundViaGateway($request->fresh(), $collect, 0.01);
            $this->fail('refunded more than was collected');
        } catch (\DomainException $e) {
            $this->assertCount(2, FakeCollectGateway::$refunds, 'the gateway is not called');
        }
        $this->assertSame(PaymentRequest::STATUS_OPEN, $request->fresh()->status);
    }

    public function test_only_a_gateway_collection_of_this_request_can_be_refunded_through_a_gateway(): void
    {
        [$request, $collect] = $this->collected();
        $manual = $this->makeRequest();
        $manualCollect = $this->service()->recordManual($manual, 50, 'bank');
        [$other] = $this->collected();

        $cases = [
            'manual collection' => [$manual, $manualCollect],
            'movement of another request' => [$other, $collect],
            'a refund' => [$request, $this->service()->refundViaGateway($request, $collect, 10)],
        ];
        FakeCollectGateway::$refunds = [];
        foreach ($cases as $label => [$req, $movement]) {
            try {
                $this->service()->refundViaGateway($req->fresh(), $movement, 5);
                $this->fail("refunded {$label}");
            } catch (\DomainException $e) {
                $this->assertSame([], FakeCollectGateway::$refunds, $label);
            }
        }
    }

    public function test_a_gateway_that_does_not_refund_is_never_asked_to(): void
    {
        $gateways = (array) config('gp247-config.payment.gateways');
        $gateways['test.fakepay']['capabilities'] = ['collect'];
        config(['gp247-config.payment.gateways' => $gateways]);
        [$request, $collect] = $this->collected();

        $this->expectException(\DomainException::class);
        try {
            $this->service()->refundViaGateway($request, $collect, 10);
        } finally {
            $this->assertSame([], FakeCollectGateway::$refunds);
        }
    }

    public function test_a_refused_refund_records_nothing(): void
    {
        FakeCollectGateway::$refundThrows = true;
        [$request, $collect] = $this->collected();

        try {
            $this->service()->refundViaGateway($request, $collect, 10);
            $this->fail('no exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('refund refused by gateway', $e->getMessage());
        }
        $this->assertSame(0, PaymentMovement::where('request_id', $request->id)->where('type', PaymentMovement::TYPE_REFUND)->count());
        $this->assertEqualsWithDelta(100, (float) $request->fresh()->settled_amount, 0.001);
    }

    public function test_money_a_gateway_confirms_beyond_the_amount_is_recorded_with_a_note(): void
    {
        [$request] = $this->collected([], 100);

        $second = $this->service()->recordMovement($request, [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => 100,
            'gateway' => 'test.fakepay',
            'gateway_ref' => 'fake_pi_second_' . $request->id,
            'confirmed' => true,
        ]);

        $this->assertTrue((bool) ($second->metadata['confirmed'] ?? false));
        $this->assertNotEmpty($second->note, 'the over-payment is explained on the movement');
        $fresh = $request->fresh();
        $this->assertEqualsWithDelta(200, (float) $fresh->settled_amount, 0.001);
        $this->assertSame(PaymentRequest::STATUS_SETTLED, $fresh->status);
    }

    public function test_money_a_gateway_confirms_after_cancellation_is_kept_and_the_request_stays_cancelled(): void
    {
        $request = $this->makeRequest();
        $this->service()->cancel($request);

        $movement = $this->service()->recordMovement($request->fresh(), [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => 100,
            'gateway' => 'test.fakepay',
            'gateway_ref' => 'fake_pi_late_' . $request->id,
            'confirmed' => true,
        ]);

        $this->assertNotEmpty($movement->note);
        $fresh = $request->fresh();
        $this->assertSame(PaymentRequest::STATUS_CANCELLED, $fresh->status);
        $this->assertEqualsWithDelta(100, (float) $fresh->settled_amount, 0.001);
    }

    public function test_a_replayed_gateway_confirmation_is_recorded_once(): void
    {
        [$request, $collect] = $this->collected();

        $again = $this->service()->recordMovement($request, [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => 100,
            'gateway' => 'test.fakepay',
            'gateway_ref' => $collect->gateway_ref,
            'confirmed' => true,
        ]);

        $this->assertSame($collect->id, $again->id);
        $this->assertSame(1, $this->movementsOf($request));
        $this->assertSame($collect->id, $this->service()->findGatewayCollect('test.fakepay', (string) $collect->gateway_ref)?->id);
        $this->assertNull($this->service()->findGatewayCollect('other', (string) $collect->gateway_ref));
    }

    public function test_money_recorded_by_hand_keeps_every_s1_guard(): void
    {
        $request = $this->makeRequest();
        $this->service()->cancel($request);

        $this->expectException(\DomainException::class);
        $this->service()->recordMovement($request->fresh(), [
            'type' => PaymentMovement::TYPE_COLLECT,
            'amount' => 10,
            'gateway' => 'manual',
            'method' => PaymentMovement::METHOD_MANUAL,
        ]);
    }

    public function test_the_pay_page_offers_only_gateways_usable_for_the_request(): void
    {
        $request = $this->makeRequest();
        $this->assertArrayHasKey('test.fakepay', $this->service()->collectGateways($request));

        FakeCollectGateway::$available = false;
        $this->assertArrayNotHasKey('test.fakepay', $this->service()->collectGateways($request));

        $this->expectException(\DomainException::class);
        $this->service()->startCollect($request, 'test.fakepay');
    }

    public function test_the_admin_refunds_from_the_screen_with_the_money_out_permission(): void
    {
        $this->asSettleOutUser();
        [$request, $collect] = $this->collected();

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->assertSeeHtml('data-testid="payment-request-refund-open"')
            ->call('openRefund', $collect->id)
            ->assertSet('refund.amount', '100.00')
            ->set('refund.amount', '25')
            ->call('refundViaGateway')
            ->assertHasNoErrors();

        $this->assertSame(1, PaymentMovement::where('request_id', $request->id)->where('type', PaymentMovement::TYPE_REFUND)->count());
        $this->assertEqualsWithDelta(75, (float) $request->fresh()->settled_amount, 0.001);
    }

    public function test_the_screen_rejects_an_over_refund_without_calling_the_gateway(): void
    {
        $this->asSettleOutUser();
        [$request, $collect] = $this->collected();

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->call('openRefund', $collect->id)
            ->set('refund.amount', '100.01')
            ->call('refundViaGateway')
            ->assertHasErrors('refund.amount');

        $this->assertSame([], FakeCollectGateway::$refunds);
    }

    public function test_without_the_money_out_permission_no_refund_goes_through(): void
    {
        $this->asScreenUser();
        [$request, $collect] = $this->collected();

        $component = Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->assertDontSeeHtml('data-testid="payment-request-refund-open"');

        try {
            $component->set('refund', ['movement_id' => (string) $collect->id, 'amount' => '10'])->call('refundViaGateway');
            $this->fail('refund went through without the permission');
        } catch (AuthorizationException $e) {
            $this->assertSame([], FakeCollectGateway::$refunds);
        }
    }
}
