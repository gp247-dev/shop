<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Support\ApiEnv;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * API configuration keys and environment variables are spelled correctly, the old
 * misspelled names keep working (the new name wins when both are set), gp247:doctor
 * reports old names still in use, and the "admin-super" ability is accepted next to
 * the legacy "admin-supper" carried by tokens already issued.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-config-naming
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiConfigNamingTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function tearDown(): void
    {
        foreach (['ZZ_GP247_NEW', 'ZZ_GP247_OLD', 'GP247_API_RECOMMEMBER'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        parent::tearDown();
    }

    public function test_correctly_spelled_keys_carry_the_same_values_as_the_legacy_keys(): void
    {
        $auth = config('gp247-config.api.auth');

        $this->assertSame($auth['api_remmember'], $auth['api_remember']);
        $this->assertSame($auth['api_remmember_admin'], $auth['api_remember_admin']);
        $this->assertSame($auth['api_scope_admin_supper'], $auth['api_scope_admin_super']);
    }

    public function test_the_new_environment_name_wins_and_the_old_one_still_works(): void
    {
        $this->setEnv('ZZ_GP247_OLD', '11');
        $this->assertSame('11', (string) ApiEnv::pick('ZZ_GP247_NEW', 'ZZ_GP247_OLD', 30));

        $this->setEnv('ZZ_GP247_NEW', '22');
        $this->assertSame('22', (string) ApiEnv::pick('ZZ_GP247_NEW', 'ZZ_GP247_OLD', 30));
    }

    public function test_doctor_lists_legacy_environment_names_still_in_use(): void
    {
        $this->setEnv('GP247_API_RECOMMEMBER', '30');

        $this->assertContains('GP247_API_RECOMMEMBER', ApiEnv::legacyInUse());
        $this->artisan('gp247:doctor', ['--json' => true])->assertExitCode(0)
            ->expectsOutputToContain('api_config_legacy_names');
    }

    public function test_admin_super_ability_is_accepted_on_admin_routes(): void
    {
        $this->pinAdminStoreContext(null, null);
        $admin = $this->makeAdmin([], ['administrator']);

        $this->getWithToken($this->coreApi() . '/order/list', $this->tokenFor($admin, ['admin-super']))->assertOk();
    }

    /**
     * @param string $name
     * @param string $value
     * @return void
     */
    private function setEnv(string $name, string $value): void
    {
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
