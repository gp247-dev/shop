<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Customers and admins list their API sessions (devices) and sign any of them out;
 * v2 logout revokes the access and the refresh token of the session. Nobody reaches
 * another owner's session, and the list never shows token material.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-session-management
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiSessionManagementTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private function login($customer, string $device): array
    {
        return $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true, 'device_name' => $device])
            ->assertOk()->json('data');
    }

    public function test_the_customer_sees_each_signed_in_device_and_which_one_is_current(): void
    {
        $customer = $this->makeCustomer();
        $phone = $this->login($customer, 'ZZ phone');
        $tablet = $this->login($customer, 'ZZ tablet');

        $list = $this->apiCall('GET', $this->v2Front() . '/member/sessions', $phone['access_token'])->assertOk()->json('data');

        $this->assertCount(2, $list);
        $byId = collect($list)->keyBy('id');
        $this->assertTrue($byId[$phone['session_id']]['current']);
        $this->assertFalse($byId[$tablet['session_id']]['current']);
        $this->assertSame('ZZ tablet', $byId[$tablet['session_id']]['name']);
        $json = json_encode($list);
        $this->assertStringNotContainsString($phone['refresh_token'], $json);
        $this->assertArrayNotHasKey('access_token_id', $list[0]);
    }

    public function test_signing_out_another_device_stops_its_access_and_refresh_token(): void
    {
        $customer = $this->makeCustomer();
        $phone = $this->login($customer, 'ZZ phone');
        $tablet = $this->login($customer, 'ZZ tablet');

        $this->apiCall('DELETE', $this->v2Front() . '/member/sessions/' . $tablet['session_id'], $phone['access_token'])
            ->assertOk()->assertJson(['data' => ['revoked' => true]]);

        $this->apiCall('GET', $this->v2Front() . '/info', $tablet['access_token'])->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $tablet['refresh_token']])->assertStatus(401);
        $this->apiCall('GET', $this->v2Front() . '/info', $phone['access_token'])->assertOk();
    }

    public function test_a_customer_cannot_sign_out_a_session_of_someone_else(): void
    {
        $mine = $this->login($this->makeCustomer(), 'ZZ mine');
        $theirs = $this->login($this->makeCustomer(), 'ZZ theirs');

        $this->apiCall('DELETE', $this->v2Front() . '/member/sessions/' . $theirs['session_id'], $mine['access_token'])
            ->assertStatus(404)->assertJson(['code' => 'not_found']);
        $this->apiCall('GET', $this->v2Front() . '/info', $theirs['access_token'])->assertOk();
    }

    public function test_v2_logout_revokes_the_access_and_the_refresh_token(): void
    {
        $customer = $this->makeCustomer();
        $session = $this->login($customer, 'ZZ phone');

        $this->apiCall('POST', $this->v2Front() . '/logout', $session['access_token'])->assertOk();

        $this->apiCall('GET', $this->v2Front() . '/info', $session['access_token'])->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $session['refresh_token']])
            ->assertStatus(401)->assertJson(['code' => 'refresh_token_invalid']);
    }

    public function test_v2_logout_still_revokes_a_token_issued_without_a_session(): void
    {
        $customer = $this->makeCustomer();
        $plain = $this->tokenFor($customer, ['user']);

        $this->apiCall('POST', $this->v2Front() . '/logout', $plain)->assertOk();

        $this->apiCall('GET', $this->v2Front() . '/info', $plain)->assertStatus(401);
    }

    public function test_an_admin_lists_and_revokes_own_api_sessions(): void
    {
        $admin = $this->makeAdmin();
        $login = fn () => $this->apiCall('POST', $this->v2Core() . '/login', null,
            ['username' => $admin->username, 'password' => 'Secret#123'])->assertOk()->json('data');
        $one = $login();
        $two = $login();

        $list = $this->apiCall('GET', $this->v2Core() . '/sessions', $one['access_token'])->assertOk()->json('data');
        $this->assertCount(2, $list);

        $this->apiCall('DELETE', $this->v2Core() . '/sessions/' . $two['session_id'], $one['access_token'])->assertOk();
        $this->apiCall('GET', $this->v2Core() . '/info', $two['access_token'])->assertStatus(401);
    }
}
