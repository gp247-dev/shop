<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Support\ApiSessions;
use GP247\Core\Models\AdminApiRefreshToken;
use GP247\Core\Models\AdminApiSession;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A v2 client that signs in with with_refresh gets a one-hour access token and a
 * refresh token it can exchange once; replaying a spent refresh token signs the whole
 * session out, except a retry within the grace window. Clients that do not ask keep
 * the long-lived token, and sites without the session tables keep working.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiRefreshTokenRotationTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ApiSessions::forgetSchemaMemo();
        parent::tearDown();
    }

    private function loginCustomer($customer, array $extra = [])
    {
        return $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123'] + $extra);
    }

    public function test_sign_in_with_refresh_gives_a_one_hour_access_token_and_a_refresh_token_stored_only_as_a_hash(): void
    {
        $customer = $this->makeCustomer();

        $data = $this->loginCustomer($customer, ['with_refresh' => true, 'device_name' => 'ZZ phone'])->assertOk()->json('data');

        $this->assertNotEmpty($data['refresh_token']);
        $this->assertNotEmpty($data['session_id']);
        $token = PersonalAccessToken::findToken($data['access_token']);
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $token->expires_at->timestamp, 120);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, Carbon::parse($data['refresh_expires_at'])->timestamp, 120);
        $row = AdminApiRefreshToken::where('session_id', $data['session_id'])->first();
        $this->assertSame(hash('sha256', $data['refresh_token']), $row->token_hash);
        $this->assertNull(AdminApiRefreshToken::where('token_hash', $data['refresh_token'])->first(), 'Plain refresh token must not be stored');
        $this->assertSame('ZZ phone', AdminApiSession::find($data['session_id'])->name);
    }

    public function test_sign_in_without_refresh_keeps_the_long_lived_token_and_opens_a_plain_session(): void
    {
        $customer = $this->makeCustomer();

        $data = $this->loginCustomer($customer)->assertOk()->json('data');

        $this->assertArrayNotHasKey('refresh_token', $data);
        $token = PersonalAccessToken::findToken($data['access_token']);
        $days = (int) config('gp247-config.api.auth.api_token_expire_default', 7);
        $this->assertEqualsWithDelta(now()->addDays($days)->timestamp, $token->expires_at->timestamp, 120);
        $this->assertFalse((bool) AdminApiSession::find($data['session_id'])->refreshable);
    }

    public function test_refresh_rotates_the_pair_and_the_old_access_token_stops_working(): void
    {
        $customer = $this->makeCustomer();
        $first = $this->loginCustomer($customer, ['with_refresh' => true])->json('data');

        $second = $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $first['refresh_token']])
            ->assertOk()->json('data');

        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame($first['session_id'], $second['session_id']);
        $this->apiCall('GET', $this->v2Front() . '/info', $first['access_token'])->assertStatus(401);
        $this->apiCall('GET', $this->v2Front() . '/info', $second['access_token'])->assertOk();
        $this->assertSame($first['refresh_expires_at'], $second['refresh_expires_at'], 'Rotation must not extend the session');
    }

    public function test_replaying_a_spent_refresh_token_after_the_grace_window_signs_the_session_out(): void
    {
        $customer = $this->makeCustomer();
        $first = $this->loginCustomer($customer, ['with_refresh' => true])->json('data');
        $second = $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $first['refresh_token']])->json('data');

        Carbon::setTestNow(now()->addSeconds(30));
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $first['refresh_token']])
            ->assertStatus(401)->assertJson(['error' => 1, 'code' => 'refresh_token_reused']);

        $this->apiCall('GET', $this->v2Front() . '/info', $second['access_token'])->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $second['refresh_token']])
            ->assertStatus(401)->assertJson(['code' => 'refresh_token_invalid']);
        $this->assertNotNull(AdminApiSession::find($first['session_id'])->revoked_at);
    }

    public function test_replaying_within_the_grace_window_answers_409_and_keeps_the_session(): void
    {
        $customer = $this->makeCustomer();
        $first = $this->loginCustomer($customer, ['with_refresh' => true])->json('data');
        $second = $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $first['refresh_token']])->json('data');

        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $first['refresh_token']])
            ->assertStatus(409)->assertJson(['code' => 'refresh_in_progress']);

        $this->apiCall('GET', $this->v2Front() . '/info', $second['access_token'])->assertOk();
        $this->assertNull(AdminApiSession::find($first['session_id'])->revoked_at);
    }

    public function test_an_expired_or_unknown_refresh_token_is_refused(): void
    {
        $customer = $this->makeCustomer();
        $data = $this->loginCustomer($customer, ['with_refresh' => true])->json('data');

        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => 'zz-not-a-token'])
            ->assertStatus(401)->assertJson(['code' => 'refresh_token_invalid']);
        Carbon::setTestNow(now()->addDays(31));
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $data['refresh_token']])
            ->assertStatus(401)->assertJson(['code' => 'refresh_token_invalid']);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, [])->assertStatus(422);
    }

    public function test_a_customer_refresh_token_does_not_work_on_the_admin_api(): void
    {
        $customer = $this->makeCustomer();
        $data = $this->loginCustomer($customer, ['with_refresh' => true])->json('data');

        $this->apiCall('POST', $this->v2Core() . '/refresh', null, ['refresh_token' => $data['refresh_token']])
            ->assertStatus(401)->assertJson(['code' => 'refresh_token_invalid']);
    }

    public function test_admin_sign_in_with_refresh_uses_the_admin_refresh_lifetime(): void
    {
        $admin = $this->makeAdmin();

        $data = $this->apiCall('POST', $this->v2Core() . '/login', null,
            ['username' => $admin->username, 'password' => 'Secret#123', 'with_refresh' => true])->assertOk()->json('data');

        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, Carbon::parse($data['refresh_expires_at'])->timestamp, 120);
        $next = $this->apiCall('POST', $this->v2Core() . '/refresh', null, ['refresh_token' => $data['refresh_token']])->assertOk()->json('data');
        $this->apiCall('GET', $this->v2Core() . '/info', $next['access_token'])->assertOk();
    }

    public function test_without_the_session_tables_sign_in_still_works_and_refresh_is_unavailable(): void
    {
        $customer = $this->makeCustomer();
        $memo = new \ReflectionProperty(ApiSessions::class, 'ready');
        $memo->setValue(null, false);

        $data = $this->loginCustomer($customer, ['with_refresh' => true])->assertOk()->json('data');

        $this->assertArrayNotHasKey('refresh_token', $data);
        $this->assertArrayNotHasKey('session_id', $data);
        $this->apiCall('GET', $this->v2Front() . '/info', $data['access_token'])->assertOk();
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => 'zz'])->assertStatus(404);
    }
}
