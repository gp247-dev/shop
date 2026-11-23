<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The general API limit counts a signed-in caller by account and an anonymous caller by
 * IP: many app users behind one carrier NAT do not hit each other's limit, one account
 * stuck in a loop only stops itself, and a made-up token never opens a fresh counter.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-throttle-per-account
 * @aidlc-adr compat-foundation_api-http-caching-and-shaping
 */
class ApiAccountThrottleTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gp247-config.api.throttle.default' => 3]);
    }

    /**
     * GET from a given IP, with or without a bearer token.
     *
     * @param string      $uri
     * @param string|null $token
     * @param string      $ip
     * @return TestResponse
     */
    private function getFrom(string $uri, ?string $token, string $ip = '203.0.113.7'): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        if ($token !== null) {
            $this->withHeader('Authorization', 'Bearer ' . $token);
        }
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($uri);
    }

    public function test_two_accounts_behind_one_ip_have_their_own_limit(): void
    {
        $profile = $this->v2Front() . '/member/profile';
        $busy = $this->tokenFor($this->makeCustomer(), ['user']);
        $other = $this->tokenFor($this->makeCustomer(), ['user']);

        for ($i = 0; $i < 3; $i++) {
            $this->getFrom($profile, $busy)->assertOk();
        }
        $this->getFrom($profile, $busy)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');

        $this->getFrom($profile, $other)->assertOk();
    }

    public function test_one_account_from_two_ips_shares_one_limit(): void
    {
        $profile = $this->v2Front() . '/member/profile';
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->getFrom($profile, $token, '203.0.113.10')->assertOk();
        $this->getFrom($profile, $token, '203.0.113.11')->assertOk();
        $this->getFrom($profile, $token, '203.0.113.12')->assertOk();

        $this->getFrom($profile, $token, '203.0.113.13')->assertStatus(429);
    }

    public function test_anonymous_calls_and_made_up_tokens_count_by_ip(): void
    {
        $list = $this->v2Front() . '/product/list';

        for ($i = 0; $i < 3; $i++) {
            $this->getFrom($list, null, '198.51.100.20')->assertOk();
        }

        $this->getFrom($list, 'zz-made-up-' . uniqid(), '198.51.100.20')->assertStatus(429);
        $this->getFrom($list, null, '198.51.100.21')->assertOk();
    }
}
