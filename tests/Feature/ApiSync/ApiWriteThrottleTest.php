<?php

namespace GP247\Shop\Tests\Feature\ApiSync;

use GP247\Core\Api\Support\ApiAbilities;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Admin API writes are rate limited per admin account (not per IP): a sync tool stuck
 * in a loop is stopped without blocking other accounts or reads. Exercised on a probe
 * write route declared by this test.
 *
 * @aidlc-unit api-sync
 * @aidlc-story US-api-sync-write-throttle
 * @aidlc-adr api-sync_admin-idempotency-store
 */
#[Group('api-sync')]
class ApiWriteThrottleTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gp247-config.api.throttle.write' => '3,1']);
        Route::middleware(GP247_API_MIDDLEWARE)->prefix(trim(GP247_API_V2_CORE_PREFIX, '/'))->group(function () {
            Route::middleware(['auth:admin-api', ApiAbilities::admin(), 'throttle:gp247-api-write'])
                ->post('zz-throttle-probe', fn () => response()->json(['data' => ['ok' => true]]));
        });
    }

    public function test_an_account_over_its_write_limit_is_told_to_wait_while_other_accounts_and_reads_go_on(): void
    {
        $busy = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);
        $other = $this->tokenFor($this->makeAdmin(['admin_product.index']), ['admin']);

        for ($i = 0; $i < 3; $i++) {
            $this->apiCall('POST', $this->v2Core() . '/zz-throttle-probe', $busy)->assertOk();
        }
        $this->apiCall('POST', $this->v2Core() . '/zz-throttle-probe', $busy)
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');

        $this->apiCall('POST', $this->v2Core() . '/zz-throttle-probe', $other)->assertOk();
        $this->apiCall('GET', $this->v2Core() . '/product/list', $busy)->assertOk();
    }
}
