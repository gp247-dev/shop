<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Every API token carries an expiry, expired tokens are refused, logout revokes the
 * current token (POST; GET kept as deprecated in v1) and long-expired tokens are
 * purged lazily at the owner's next login — no cron needed.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-token-lifetime
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiTokenLifetimeTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_admin_token_without_remember_me_expires_after_the_configured_default(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])->assertOk();

        $token = PersonalAccessToken::where('tokenable_id', $admin->id)->latest('id')->first();
        $this->assertNotNull($token->expires_at, 'Admin token must not be issued without an expiry');
        $days = (int) config('gp247-config.api.auth.api_token_expire_admin_default', 7);
        $this->assertEqualsWithDelta(now()->addDays($days)->timestamp, $token->expires_at->timestamp, 120);
    }

    public function test_admin_token_with_remember_me_uses_the_remember_lifetime(): void
    {
        $admin = $this->makeAdmin();

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123', 'remember_me' => true])
            ->assertOk();

        $token = PersonalAccessToken::where('tokenable_id', $admin->id)->latest('id')->first();
        $days = (int) config('gp247-config.api.auth.api_remmember_admin', 30);
        $this->assertEqualsWithDelta(now()->addDays($days)->timestamp, $token->expires_at->timestamp, 120);
    }

    public function test_expired_admin_token_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $plain = $this->tokenFor($admin, ['admin']);
        PersonalAccessToken::findToken($plain)->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->getWithToken($this->coreApi() . '/info', $plain)->assertStatus(401);
    }

    public function test_post_logout_revokes_the_current_admin_token(): void
    {
        $admin = $this->makeAdmin();
        $plain = $this->tokenFor($admin, ['admin']);

        $this->withHeader('Authorization', 'Bearer ' . $plain)->postJson($this->coreApi() . '/logout')
            ->assertOk()
            ->assertJson(['error' => 0]);

        $this->assertNull(PersonalAccessToken::findToken($plain));
    }

    public function test_get_logout_still_works_but_is_marked_deprecated(): void
    {
        $customer = $this->makeCustomer();
        $plain = $this->tokenFor($customer, ['user']);

        $this->getWithToken($this->frontApi() . '/logout', $plain)
            ->assertOk()
            ->assertHeader('Deprecation');

        $this->assertNull(PersonalAccessToken::findToken($plain));
    }

    public function test_long_expired_tokens_of_the_owner_are_purged_at_next_login(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();
        $stale = PersonalAccessToken::findToken($this->tokenFor($admin, ['admin']));
        $stale->forceFill(['expires_at' => now()->subDays(8)])->save();
        $recent = PersonalAccessToken::findToken($this->tokenFor($admin, ['admin']));
        $recent->forceFill(['expires_at' => now()->subDay()])->save();
        $othersStale = PersonalAccessToken::findToken($this->tokenFor($other, ['admin']));
        $othersStale->forceFill(['expires_at' => now()->subDays(8)])->save();

        $this->postJson($this->coreApi() . '/login', ['username' => $admin->username, 'password' => 'Secret#123'])->assertOk();

        $this->assertNull(PersonalAccessToken::find($stale->id));
        $this->assertNotNull(PersonalAccessToken::find($recent->id));
        $this->assertNotNull(PersonalAccessToken::find($othersStale->id));
    }

    /**
     * Tokens issued before tokens had an expiry get one at the upgrade: their real age
     * plus the remember lifetime of their owner (admin or customer), but never sooner
     * than 7 days from the upgrade (DQ1); tokens that already
     * expire are untouched; running the migration again changes nothing.
     */
    public function test_tokens_issued_without_an_expiry_get_one_at_the_upgrade(): void
    {
        config(['gp247-config.api.auth.api_remember_admin' => 20, 'gp247-config.api.auth.api_remember' => 40]);
        $days = 20;
        $admin = $this->makeAdmin();
        $customerToken = PersonalAccessToken::findToken($this->tokenFor($this->makeCustomer(), ['user']));
        $customerToken->forceFill(['expires_at' => null, 'created_at' => now()->subDays(5)])->save();
        $young = PersonalAccessToken::findToken($this->tokenFor($admin, ['admin']));
        $young->forceFill(['expires_at' => null, 'created_at' => now()->subDays(5)])->save();
        $old = PersonalAccessToken::findToken($this->tokenFor($admin, ['admin']));
        $old->forceFill(['expires_at' => null, 'created_at' => now()->subDays(400)])->save();
        $dated = PersonalAccessToken::findToken($this->tokenFor($admin, ['admin']));
        $datedExpiry = now()->addDays(2)->startOfSecond();
        $dated->forceFill(['expires_at' => $datedExpiry])->save();

        $migration = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_10_27_154125_expire_legacy_api_tokens.php');
        $migration->up();
        $afterFirst = PersonalAccessToken::whereIn('id', [$young->id, $old->id, $dated->id])->pluck('expires_at', 'id')->map(fn ($v) => (string) $v)->all();
        $migration->up();

        $this->assertEqualsWithDelta($young->created_at->copy()->addDays($days)->getTimestamp(), PersonalAccessToken::find($young->id)->expires_at->getTimestamp(), 5);
        $this->assertEqualsWithDelta($customerToken->created_at->copy()->addDays(40)->getTimestamp(), PersonalAccessToken::find($customerToken->id)->expires_at->getTimestamp(), 5, 'a customer token gets the customer lifetime');
        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), PersonalAccessToken::find($old->id)->expires_at->getTimestamp(), 5);
        $this->assertSame($datedExpiry->toDateTimeString(), PersonalAccessToken::find($dated->id)->expires_at->toDateTimeString());
        $this->assertSame($afterFirst, PersonalAccessToken::whereIn('id', [$young->id, $old->id, $dated->id])->pluck('expires_at', 'id')->map(fn ($v) => (string) $v)->all(), 'a second run changes nothing');
        $this->assertSame(0, PersonalAccessToken::whereNull('expires_at')->count());
    }

    public function test_v2_tokens_issued_with_a_refresh_token_live_minutes_not_days(): void
    {
        $customer = $this->makeCustomer();

        $data = $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true])->assertOk()->json('data');

        $minutes = (int) config('gp247-config.api.auth.api_access_minutes', 60);
        $this->assertEqualsWithDelta(now()->addMinutes($minutes)->timestamp,
            PersonalAccessToken::findToken($data['access_token'])->expires_at->timestamp, 120);
    }

    public function test_changing_the_password_ends_v1_tokens_too(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $v2 = $this->tokenFor($customer, ['user']);

        $this->apiCall('PUT', $this->v2Front() . '/member/password', $v2, [
            'password_old' => 'Secret#123', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertOk();

        $this->getWithToken($this->frontApi() . '/info', $v1)->assertStatus(401);
    }
}
