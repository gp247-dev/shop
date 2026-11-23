<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Contracts\LoginChallenge;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A plugin registered under gp247-config.api.login_challenge adds a second sign-in
 * step: the correct password returns a single-use ticket instead of a token, and the
 * token is issued only after the plugin accepts the answer. A failing plugin denies
 * the sign-in, wrong answers count against the login throttle, and v1 refuses
 * accounts that need the step.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-challenge-seam, US-CMP-api-login-throttle
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiLoginChallengeSeamTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        ZzCodeChallenge::$throws = false;
    }

    private function enable(string $audience = 'customer', $entry = ZzCodeChallenge::class): void
    {
        config(['gp247-config.api.login_challenge.' . $audience => [$entry]]);
    }

    private function startCustomer($customer)
    {
        return $this->apiCall('POST', $this->v2Front() . '/login', null, ['email' => $customer->email, 'password' => 'Secret#123']);
    }

    public function test_without_a_registered_challenge_sign_in_is_unchanged(): void
    {
        $customer = $this->makeCustomer();

        $this->startCustomer($customer)->assertOk()->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_the_right_password_returns_a_ticket_and_no_token_until_the_challenge_is_passed(): void
    {
        $this->enable();
        $customer = $this->makeCustomer();

        $response = $this->startCustomer($customer)->assertStatus(401)
            ->assertJson(['error' => 1, 'code' => 'challenge_required', 'challenge' => ['method' => 'zz-code']]);
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());

        $data = $this->apiCall('POST', $this->v2Front() . '/login/challenge', null,
            ['ticket' => $response->json('challenge.ticket'), 'code' => '123456'])->assertOk()->json('data');
        $this->apiCall('GET', $this->v2Front() . '/info', $data['access_token'])->assertOk();
    }

    public function test_a_ticket_is_single_use(): void
    {
        $this->enable();
        $ticket = $this->startCustomer($this->makeCustomer())->json('challenge.ticket');
        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])->assertOk();

        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
    }

    public function test_wrong_answers_are_refused_and_throttled_with_the_account_login_counter(): void
    {
        $this->enable();
        $customer = $this->makeCustomer();
        $ticket = $this->startCustomer($customer)->json('challenge.ticket');

        for ($i = 0; $i < 4; $i++) {
            $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '000000'])
                ->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
        }
        // WHY 5th: the password attempt that issued the ticket already used one of the five tries.
        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(401);
    }

    public function test_a_ticket_is_destroyed_after_five_wrong_answers_from_any_ip(): void
    {
        $this->enable();
        $ticket = $this->startCustomer($this->makeCustomer())->json('challenge.ticket');

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.' . ($i + 10)]);
            $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '000000'])
                ->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99']);
        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
    }

    public function test_a_customer_ticket_is_not_accepted_by_the_admin_api(): void
    {
        $this->enable();
        $ticket = $this->startCustomer($this->makeCustomer())->json('challenge.ticket');

        $this->apiCall('POST', $this->v2Core() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
    }

    public function test_a_failing_plugin_denies_the_sign_in(): void
    {
        $this->enable();
        $ticket = $this->startCustomer($this->makeCustomer())->json('challenge.ticket');
        ZzCodeChallenge::$throws = true;

        $this->apiCall('POST', $this->v2Front() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
    }

    public function test_a_broken_registration_denies_the_sign_in_instead_of_skipping_the_step(): void
    {
        $this->enable('customer', \stdClass::class);

        $this->startCustomer($this->makeCustomer())->assertStatus(401)->assertJson(['code' => 'challenge_invalid']);
    }

    public function test_v1_login_refuses_an_account_that_needs_the_challenge(): void
    {
        $this->enable();
        $customer = $this->makeCustomer();

        $response = $this->apiCall('POST', $this->frontApi() . '/login', null, ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertStatus(401)->assertJson(['code' => 'challenge_required']);
        $this->assertArrayNotHasKey('challenge', $response->json());
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
    }

    public function test_the_admin_api_uses_the_admin_audience(): void
    {
        $this->enable('admin');
        $admin = $this->makeAdmin();

        $ticket = $this->apiCall('POST', $this->v2Core() . '/login', null, ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertStatus(401)->json('challenge.ticket');
        $this->apiCall('POST', $this->v2Core() . '/login/challenge', null, ['ticket' => $ticket, 'code' => '123456'])
            ->assertOk()->assertJsonStructure(['data' => ['access_token', 'session_id']]);
    }
}

/**
 * Test double of a plugin challenge: always required, accepts code 123456.
 */
class ZzCodeChallenge implements LoginChallenge
{
    public static bool $throws = false;

    public function required(Authenticatable $owner, Request $request): bool
    {
        return true;
    }

    public function describe(Authenticatable $owner): array
    {
        return ['method' => 'zz-code', 'meta' => ['digits' => 6]];
    }

    public function verify(Authenticatable $owner, Request $request): bool
    {
        if (self::$throws) {
            throw new \RuntimeException('zz challenge backend down');
        }
        return $request->input('code') === '123456';
    }
}
