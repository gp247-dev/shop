<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Models\AdminApiSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The signed-in customer reads and edits the profile through API v2 with the
 * storefront rules — only declared fields come back, status and password cannot be
 * set through the profile — and changes the password after proving the old one, which
 * keeps the current session and signs every other client out (v1 tokens included).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-profile
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiCustomerProfileTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useMinimalCustomerForm();
    }

    private function signIn($customer): array
    {
        return $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true])->assertOk()->json('data');
    }

    public function test_the_profile_shows_declared_fields_and_the_verification_state(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->signIn($customer);

        $data = $this->apiCall('GET', $this->v2Front() . '/member/profile', $session['access_token'])->assertOk()->json('data');

        $this->assertSame($customer->email, $data['email']);
        $this->assertFalse($data['email_verified']);
        $this->assertFalse($data['email_verification_required']);
        foreach (['password', 'remember_token', 'store_id'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $data);
        }
    }

    public function test_updating_the_profile_follows_the_store_rules_and_ignores_protected_fields(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->signIn($customer);

        $this->apiCall('PUT', $this->v2Front() . '/member/profile', $session['access_token'], [
            'first_name' => 'ZZ Renamed', 'email' => $customer->email, 'address1' => '1 ZZ street', 'status' => 0, 'password' => 'Hijack#999',
        ])->assertOk()->assertJson(['data' => ['first_name' => 'ZZ Renamed']]);

        $fresh = $customer->fresh();
        $this->assertSame('ZZ Renamed', $fresh->first_name);
        $this->assertSame(1, (int) $fresh->status);
        $this->assertTrue(Hash::check('Secret#123', $fresh->password));

        $this->apiCall('PUT', $this->v2Front() . '/member/profile', $session['access_token'], ['first_name' => ''])
            ->assertStatus(422)->assertJsonValidationErrors(['first_name']);
    }

    public function test_a_profile_update_without_the_first_name_key_is_rejected_not_crashed(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->signIn($customer);

        $this->apiCall('PUT', $this->v2Front() . '/member/profile', $session['access_token'], ['address1' => '2 ZZ street'])
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['first_name']);
        $this->assertSame($customer->first_name, $customer->fresh()->first_name);
    }

    public function test_changing_the_password_needs_the_old_one(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->signIn($customer);

        $this->apiCall('PUT', $this->v2Front() . '/member/password', $session['access_token'], [
            'password_old' => 'zz-wrong', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertStatus(422)->assertJsonValidationErrors(['password_old']);

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
    }

    public function test_changing_the_password_without_a_new_one_changes_nothing(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->signIn($customer);

        foreach ([['password_old' => 'Secret#123'], ['password_old' => 'Secret#123', 'password' => '', 'password_confirmation' => '']] as $body) {
            $this->apiCall('PUT', $this->v2Front() . '/member/password', $session['access_token'], $body)
                ->assertStatus(422)->assertJsonValidationErrors(['password']);
        }

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
        $this->apiCall('GET', $this->v2Front() . '/member/profile', $session['access_token'])->assertOk();
    }

    public function test_changing_the_password_keeps_this_session_and_signs_every_other_client_out(): void
    {
        $customer = $this->makeCustomer();
        $current = $this->signIn($customer);
        $other = $this->signIn($customer);
        $v1Token = $this->tokenFor($customer, ['user']);

        $this->apiCall('PUT', $this->v2Front() . '/member/password', $current['access_token'], [
            'password_old' => 'Secret#123', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertOk();

        $this->apiCall('GET', $this->v2Front() . '/member/profile', $current['access_token'])->assertOk();
        $this->apiCall('GET', $this->v2Front() . '/member/profile', $other['access_token'])->assertStatus(401);
        $this->apiCall('GET', $this->frontApi() . '/info', $v1Token)->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $other['refresh_token']])->assertStatus(401);
        $this->assertNull(AdminApiSession::find($current['session_id'])->revoked_at);
        $this->assertTrue(Hash::check('NewSecret#456', $customer->fresh()->password));
    }
}
