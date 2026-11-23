<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Shop\Admin\Livewire\PaymentRequestManager;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\PaymentRequestService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Shop\Tests\Feature\AdminShell\Support\FakeCollectGateway;
use GP247\Shop\Tests\Feature\AdminShell\Support\PaymentRequestFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The admin issues a payment link for a request to collect money: the link cannot be
 * guessed or rebuilt from the database, can be shown again later, and re-issuing it
 * kills the old one. Starting a collection only goes through a gateway that really
 * collects.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-public-pay-link
 * @aidlc-adr payment-request_generic-money-request
 */
class PaymentRequestPublicLinkTest extends TestCase
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

    public function test_an_issued_link_token_is_long_random_and_never_stored_in_clear(): void
    {
        $request = $this->makeRequest();

        $token = $this->service()->issuePublicLink($request);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token, '32 random bytes, base64url');
        $row = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'payment_request')->where('id', $request->id)->first();
        $this->assertSame(hash('sha256', $token), $row->public_token_hash);
        $this->assertStringStartsWith('enc:', (string) $row->public_token_enc);
        $this->assertStringNotContainsString($token, json_encode($row), 'raw token never stored');
    }

    public function test_the_admin_can_read_the_link_again_after_issuing_it(): void
    {
        $request = $this->makeRequest();
        $token = $this->service()->issuePublicLink($request);

        $fresh = $request->fresh();
        $this->assertSame($token, $this->service()->publicToken($fresh));
        $this->assertSame(route('payment_request.pay', ['token' => $token]), $this->service()->publicUrl($fresh));
    }

    public function test_the_token_finds_its_request_and_a_wrong_token_finds_nothing(): void
    {
        $request = $this->makeRequest();
        $token = $this->service()->issuePublicLink($request);

        $this->assertSame($request->id, $this->service()->findByPublicToken($token)?->id);
        $this->assertNull($this->service()->findByPublicToken($token . 'x'));
        $this->assertNull($this->service()->findByPublicToken(''));
    }

    public function test_re_issuing_the_link_kills_the_old_one(): void
    {
        $request = $this->makeRequest();
        $old = $this->service()->issuePublicLink($request);
        $new = $this->service()->issuePublicLink($request->fresh());

        $this->assertNotSame($old, $new);
        $this->assertNull($this->service()->findByPublicToken($old));
        $this->assertSame($request->id, $this->service()->findByPublicToken($new)?->id);
    }

    public function test_no_link_is_issued_for_money_out_a_cancelled_or_a_settled_request(): void
    {
        foreach ([
            ['direction' => 'out'],
            ['status' => PaymentRequest::STATUS_CANCELLED, 'cancelled_at' => now()],
            ['status' => PaymentRequest::STATUS_SETTLED, 'settled_amount' => 100],
            ['expires_at' => now()->subDay()],
        ] as $overrides) {
            $request = $this->makeRequest($overrides);
            try {
                $this->service()->issuePublicLink($request);
                $this->fail('link issued for ' . json_encode($overrides));
            } catch (\DomainException $e) {
                $this->assertNull($request->fresh()->public_token_hash);
            }
        }
    }

    public function test_the_token_column_is_covered_by_the_secret_tooling(): void
    {
        $this->assertContains('public_token_enc', (array) config('gp247-config.security.encrypted_columns.payment_request'));
    }

    public function test_starting_a_collection_calls_the_gateway_and_keeps_its_session_reference(): void
    {
        $request = $this->makeRequest();

        $result = $this->service()->startCollect($request, 'test.fakepay');

        $this->assertSame('https://pay.example.test/session/' . $request->id, $result->redirectUrl);
        $this->assertSame([$request->id], FakeCollectGateway::$collected);
        $fresh = $request->fresh();
        $this->assertSame('test.fakepay', $fresh->gateway);
        $this->assertSame($result->gatewayRef, $fresh->gateway_ref);
        $this->assertSame(0, $this->movementsOf($request), 'starting a collection records no money');
    }

    public function test_a_collection_never_goes_through_manual_or_a_gateway_that_cannot_collect(): void
    {
        $request = $this->makeRequest();

        foreach (['manual', 'test.refund-only', 'no-such-gateway'] as $gateway) {
            try {
                $this->service()->startCollect($request, $gateway);
                $this->fail("collect started through {$gateway}");
            } catch (\DomainException $e) {
                $this->assertSame([], FakeCollectGateway::$collected);
            }
        }
    }

    public function test_a_collection_is_refused_when_nothing_is_left_to_collect(): void
    {
        foreach ([
            ['status' => PaymentRequest::STATUS_SETTLED, 'settled_amount' => 100],
            ['status' => PaymentRequest::STATUS_CANCELLED, 'cancelled_at' => now()],
            ['direction' => 'out'],
        ] as $overrides) {
            $request = $this->makeRequest($overrides);
            try {
                $this->service()->startCollect($request, 'test.fakepay');
                $this->fail('collect started for ' . json_encode($overrides));
            } catch (\DomainException $e) {
                $this->assertSame([], FakeCollectGateway::$collected);
            }
        }
    }

    public function test_the_admin_issues_and_re_issues_the_link_from_the_screen(): void
    {
        $this->asScreenUser();
        $request = $this->makeRequest();

        $component = Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->assertSeeHtml('data-testid="payment-request-issue-link"')
            ->call('issuePublicLink')
            ->assertHasNoErrors();

        $first = $this->service()->publicUrl($request->fresh());
        $this->assertNotNull($first);
        $component->assertSee($first)->assertSeeHtml('data-testid="payment-request-public-url"');

        $component->call('issuePublicLink');
        $second = $this->service()->publicUrl($request->fresh());
        $this->assertNotSame($first, $second);
        $component->assertSee($second)->assertDontSee($first);
    }

    public function test_link_labels_reach_both_fresh_installs_and_upgraded_sites(): void
    {
        $pairs = [
            // One migration carries every payment request label: admin screen, pay page, order purposes.
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_01_200000_install_payment_request.php'
                => 'vendor/gp247/shop/src/Admin/Database/Seeders/DataShopLanguageSeeder.php',
        ];
        foreach ($pairs as $migrationPath => $seederPath) {
            $migration = require base_path($migrationPath);
            $seeder = file_get_contents(base_path($seederPath));
            foreach ($migration::labels() as $row) {
                $this->assertStringContainsString(
                    "['code' => '{$row['code']}','text' => '" . str_replace("'", "\\'", $row['text']) . "','position' => '{$row['position']}','location' => '{$row['location']}']",
                    $seeder,
                    "{$row['code']} ({$row['location']}) missing from " . basename($seederPath)
                );
            }
        }
    }

    public function test_the_screen_offers_no_link_for_money_out(): void
    {
        $this->asSettleOutUser();
        $request = $this->makeRequest(['direction' => 'out']);

        Livewire::test(PaymentRequestManager::class)
            ->call('editRow', $request->id)
            ->assertDontSeeHtml('data-testid="payment-request-issue-link"')
            ->call('issuePublicLink');

        $this->assertNull($request->fresh()->public_token_hash);
    }
}
