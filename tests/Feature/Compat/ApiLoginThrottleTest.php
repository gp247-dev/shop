<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * API logins count failed attempts per (login identity + IP); past the limit the
 * endpoint answers 429 with Retry-After without checking the password, and a
 * successful login clears the counter.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-login-throttle
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiLoginThrottleTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_admin_login_is_blocked_after_five_failed_attempts_even_with_the_right_password(): void
    {
        $admin = $this->makeAdmin();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['error' => 1, 'code' => 'too_many_attempts']);
    }

    public function test_successful_admin_login_resets_the_failed_attempt_counter(): void
    {
        $admin = $this->makeAdmin();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'wrong']);
        }
        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'wrong'])
                ->assertStatus(401);
        }
    }

    public function test_counter_is_per_login_identity(): void
    {
        $locked = $this->makeAdmin();
        $other = $this->makeAdmin();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $locked->username, 'password' => 'wrong']);
        }

        $this->postJson($this->coreApi() . '/login', ['username' => $other->username, 'password' => 'Secret#123'])
            ->assertOk();
    }

    public function test_customer_login_is_blocked_after_five_failed_attempts(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->frontApi() . '/login', ['email' => $customer->email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson($this->frontApi() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_login_limit_is_configurable(): void
    {
        config(['gp247-config.login_throttle.account' => '2,1']);
        $admin = $this->makeAdmin();
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'wrong']);
        }

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_spelling_variants_of_the_same_account_share_one_counter(): void
    {
        $admin = $this->makeAdmin();
        // WHY: the accent-insensitive collation matches "zzápi…" to the "zzapi…" account,
        // so a counter keyed on the typed text alone would start fresh for each variant.
        $variant = preg_replace('/a/', 'á', $admin->username, 1);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => $variant, 'password' => 'wrong']);
        }

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])
            ->assertStatus(429);
    }

    public function test_one_client_ip_cannot_spray_passwords_across_many_accounts(): void
    {
        config(['gp247-config.login_throttle.ip' => '3,1']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson($this->coreApi() . '/login', ['username' => 'zz-nobody-' . $i, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $this->postJson($this->coreApi() . '/login', ['username' => $this->makeAdmin()->username, 'password' => 'Secret#123'])
            ->assertStatus(429);
    }

    public function test_wrong_old_passwords_on_password_change_count_against_the_account_login_limit(): void
    {
        $customer = $this->makeCustomer();
        $token = $this->tokenFor($customer, ['user']);
        for ($i = 0; $i < 5; $i++) {
            $this->apiCall('PUT', '/api/v2/front/member/password', $token, [
                'password_old' => 'wrong', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
            ])->assertStatus(422);
        }

        $this->apiCall('POST', $this->frontApi() . '/login', null, ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
    }
}
