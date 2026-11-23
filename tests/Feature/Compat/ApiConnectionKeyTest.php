<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\AdminShell\Http\Livewire\ApiConnectionManager;
use GP247\Core\Models\AdminApiConnection;
use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * API Connection keys are stored hashed and shown once: a database copy never
 * yields a usable key, a wrong/missing/expired/disabled key answers 401, keys issued
 * before the change keep working after the upgrade migration, the plain-key column is
 * dropped by the contract migration (the model works with either column set), and
 * last_active is not rewritten on every call.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-connection-key-hashing
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiConnectionKeyTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use InteractsWithAdminUser;

    private const CONTRACT_MIGRATION = 'vendor/gp247/core/src/Database/Migrations/upgrade/2026_10_27_154124_drop_api_connection_plain_key.php';

    protected function tearDown(): void
    {
        // WHY: gp247_config() memoises admin_config statically for the process; the
        // rolled-back "required" flag must not leak into the next test.
        $this->flushConfigMemo();
        parent::tearDown();
    }

    public function test_a_new_key_is_stored_hashed_and_revealed_once(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        $component = Livewire::test(ApiConnectionManager::class)
            ->set('description', 'ZZ hashed')
            ->set('apiconnection', 'zz_hashed_conn')
            ->set('status', true)
            ->call('save');

        $this->assertSame(1, preg_match('/data-testid="api-connection-key-once".*?<code[^>]*>\s*([0-9a-z]+)\s*</s', $component->html(), $m), 'the new key is shown once after saving');
        $shown = $m[1];
        $row = AdminApiConnection::where('apiconnection', 'zz_hashed_conn')->first();
        $this->assertSame('', (string) $row->apikey, 'the plain key is not stored');
        $this->assertSame(hash('sha256', $shown), $row->apikey_hash);
        $this->assertStringNotContainsString($shown, json_encode($component->snapshot), 'the key never enters the Livewire snapshot');
        $component->call('resetForm')->assertDontSeeHtml('api-connection-key-once');
    }

    public function test_editing_a_connection_never_shows_its_key_again(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);
        $row = AdminApiConnection::create(['description' => 'ZZ', 'apiconnection' => 'zz_edit_hidden', 'apikey' => 'zzsecretkey', 'status' => 1]);

        Livewire::test(ApiConnectionManager::class)
            ->call('editRow', $row->id)
            ->assertSet('apikey', '')
            ->assertDontSeeHtml('zzsecretkey')
            ->set('description', 'ZZ renamed')
            ->call('save');

        $this->assertSame(hash('sha256', 'zzsecretkey'), $row->fresh()->apikey_hash, 'saving without a new key keeps the old one');
    }

    public function test_valid_connection_and_key_open_the_api_when_required(): void
    {
        $this->makeConnection('zz_ok_conn', 'zzokkey');
        $this->requireConnection();

        $this->withHeaders(['apiconnection' => 'zz_ok_conn', 'apikey' => 'zzokkey'])
            ->getJson($this->frontApi() . '/product/list')
            ->assertOk();
    }

    public function test_wrong_key_is_unauthenticated(): void
    {
        $this->makeConnection('zz_wrong_conn', 'zzrightkey');
        $this->requireConnection();

        $this->withHeaders(['apiconnection' => 'zz_wrong_conn', 'apikey' => 'zzwrongkey'])
            ->getJson($this->frontApi() . '/product/list')
            ->assertStatus(401)
            ->assertJson(['error' => 1, 'code' => 'api_connection_invalid']);
    }

    public function test_missing_headers_are_unauthenticated(): void
    {
        $this->requireConnection();

        $this->getJson($this->frontApi() . '/product/list')
            ->assertStatus(401)
            ->assertJson(['code' => 'api_connection_invalid']);
    }

    public function test_expired_or_disabled_connection_is_unauthenticated(): void
    {
        $this->makeConnection('zz_expired_conn', 'zzexpkey', ['expire' => now()->subDay()->toDateString()]);
        $this->makeConnection('zz_off_conn', 'zzoffkey', ['status' => 0]);
        $this->requireConnection();

        $this->withHeaders(['apiconnection' => 'zz_expired_conn', 'apikey' => 'zzexpkey'])
            ->getJson($this->frontApi() . '/product/list')->assertStatus(401);
        $this->withHeaders(['apiconnection' => 'zz_off_conn', 'apikey' => 'zzoffkey'])
            ->getJson($this->frontApi() . '/product/list')->assertStatus(401);
    }

    /**
     * A site still holding plain keys (column `apikey` present) upgrades without
     * reissuing them: the plain key opens the API before the contract migration, the
     * migration backfills the hash, drops the plain column and is idempotent, and the
     * same key still opens the API afterwards (US-CMP-api-connection-key-hashing,
     * contract step).
     */
    public function test_a_site_still_holding_plain_keys_upgrades_without_reissuing_them(): void
    {
        $this->withSchemaChanges(function (): void {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'api_connection')->insert([
                'description' => 'ZZ legacy', 'apiconnection' => 'zz_legacy_conn', 'apikey' => 'zzlegacykey',
                'apikey_hash' => null, 'status' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            AdminApiConnection::forgetSchemaMemo();
            $this->requireConnection();

            $this->withHeaders(['apiconnection' => 'zz_legacy_conn', 'apikey' => 'zzlegacykey'])
                ->getJson($this->frontApi() . '/product/list')->assertOk();

            // WHY the released expand migration first: the first gp247:update of a fresh
            // install runs the whole upgrade/ folder in name order, so 2026_10_26_191822
            // (which reads the plain column) must still work before the contract drops it.
            $expand = require base_path('vendor/gp247/core/src/Database/Migrations/upgrade/2026_10_26_191822_add_apikey_hash_to_api_connection.php');
            $expand->up();
            $migration = require base_path(self::CONTRACT_MIGRATION);
            $migration->up();
            $migration->up();

            $this->assertFalse($this->schema()->hasColumn(GP247_DB_PREFIX . 'api_connection', 'apikey'), 'the plain key column is dropped');
            $this->assertSame(hash('sha256', 'zzlegacykey'), AdminApiConnection::where('apiconnection', 'zz_legacy_conn')->value('apikey_hash'));
            $this->withHeaders(['apiconnection' => 'zz_legacy_conn', 'apikey' => 'zzlegacykey'])
                ->getJson($this->frontApi() . '/product/list')->assertOk();
        });
    }

    /**
     * Before the hash column exists (site that has not run the 3.2.3 migration yet) the
     * model stores and checks the key in the plain column.
     */
    public function test_before_the_hash_column_exists_keys_are_stored_and_checked_plain(): void
    {
        $this->withSchemaChanges(function (): void {
            $this->setSchemaMemo(hash: false, plain: true);

            $this->makeConnection('zz_prehash_conn', 'zzprehashkey-0123456789abcdef0123');

            $this->assertSame('zzprehashkey-0123456789abcdef0123', DB::connection(GP247_DB_CONNECTION)
                ->table(GP247_DB_PREFIX . 'api_connection')->where('apiconnection', 'zz_prehash_conn')->value('apikey'));
            $this->assertNotNull(AdminApiConnection::check('zz_prehash_conn', 'zzprehashkey-0123456789abcdef0123'));
            $this->assertNull(AdminApiConnection::check('zz_prehash_conn', 'wrong-key-0123456789abcdef0123456'));
        });
    }

    /**
     * After the contract a new key is stored as a hash only (no plain column at all).
     */
    public function test_after_the_contract_a_key_lives_only_as_a_hash(): void
    {
        $this->setSchemaMemo(hash: true, plain: false);
        try {
            $row = $this->makeConnection('zz_hashonly_conn', 'zzhashonlykey-0123456789abcdef012');

            $this->assertArrayNotHasKey('apikey', $row->getAttributes());
            $this->assertSame(hash('sha256', 'zzhashonlykey-0123456789abcdef012'), $row->getAttributes()['apikey_hash']);
            $this->assertNotNull(AdminApiConnection::check('zz_hashonly_conn', 'zzhashonlykey-0123456789abcdef012'));
        } finally {
            AdminApiConnection::forgetSchemaMemo();
        }
    }

    public function test_last_active_is_written_at_most_once_a_minute(): void
    {
        $recent = now()->subSeconds(20)->startOfSecond();
        $this->makeConnection('zz_recent_conn', 'zzrecentkey', ['last_active' => $recent]);
        $this->makeConnection('zz_stale_conn', 'zzstalekey', ['last_active' => now()->subMinutes(5)]);
        $this->requireConnection();

        $this->withHeaders(['apiconnection' => 'zz_recent_conn', 'apikey' => 'zzrecentkey'])->getJson($this->frontApi() . '/product/list')->assertOk();
        $this->withHeaders(['apiconnection' => 'zz_stale_conn', 'apikey' => 'zzstalekey'])->getJson($this->frontApi() . '/product/list')->assertOk();

        $this->assertSame($recent->toDateTimeString(), (string) AdminApiConnection::where('apiconnection', 'zz_recent_conn')->value('last_active'));
        $this->assertGreaterThan(now()->subMinute(), \Carbon\Carbon::parse(AdminApiConnection::where('apiconnection', 'zz_stale_conn')->value('last_active')));
    }

    /**
     * @param string $connection
     * @param string $key
     * @param array  $attributes
     * @return AdminApiConnection
     */
    private function makeConnection(string $connection, string $key, array $attributes = []): AdminApiConnection
    {
        return AdminApiConnection::create(array_merge([
            'description' => 'ZZ ' . $connection, 'apiconnection' => $connection, 'apikey' => $key, 'status' => 1,
        ], $attributes));
    }

    /**
     * Turn on api_connection_required for this test only.
     *
     * @return void
     */
    private function requireConnection(): void
    {
        AdminConfig::where('key', 'api_connection_required')
            ->where('store_id', (string) GP247_STORE_ID_GLOBAL)
            ->update(['value' => '1']);
        $this->flushConfigMemo();
    }

    /**
     * @return void
     */
    private function flushConfigMemo(): void
    {
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $property => $value) {
            $reflection = new \ReflectionProperty(AdminConfig::class, $property);
            $reflection->setValue(null, $value);
        }
    }

    public function test_typed_keys_need_at_least_32_characters(): void
    {
        $this->asUser(slugs: ['admin_api_connection']);

        Livewire::test(ApiConnectionManager::class)
            ->set('description', 'ZZ short')
            ->set('apiconnection', 'zz_short_conn')
            ->set('apikey', 'short')
            ->call('save')
            ->assertHasErrors(['apikey']);
    }

    public function test_the_admin_action_log_never_records_the_key(): void
    {
        // WHY: the HTTP and Livewire operation logs both drop every input/property whose
        // name contains one of these words; the revealed key is not a public property at all.
        $this->assertContains('apikey', \GP247\Core\Middleware\LogOperation::exceptKeys());
        $this->assertFalse((new \ReflectionProperty(ApiConnectionManager::class, 'revealedKey'))->isPublic());
    }

    /**
     * @return \Illuminate\Database\Schema\Builder
     */
    private function schema()
    {
        return \Illuminate\Support\Facades\Schema::connection(GP247_DB_CONNECTION);
    }

    /**
     * Run a test body that needs the legacy plain-key column and runs schema changes.
     *
     * WHY outside the test transaction: on MySQL every DDL statement commits the open
     * transaction, so the fixture writes made before it (API switches, API Connection
     * flag) would land in the shared dev database for good. The pending fixture writes
     * are rolled back first, the config rows this body touches are read from the real
     * database, and everything is put back exactly as it was afterwards: those config
     * rows (every column), the rows the body created (zz_*), the plain-key column (same
     * nullability), and finally the test transaction Laravel will roll back.
     *
     * Skipped when the database still holds real plain keys: the body runs the contract
     * migration, which would hash them and drop the column — a change to rows this test
     * did not create (rule data-safety D.1). Run gp247:update on that database first.
     *
     * @param callable $body
     * @return void
     */
    private function withSchemaChanges(callable $body): void
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $table = GP247_DB_PREFIX . 'api_connection';
        $hadPlain = $this->schema()->hasColumn($table, 'apikey');
        if ($hadPlain && $db->table($table)->where('apikey', '<>', '')->where('apiconnection', 'not like', 'zz\_%')->exists()) {
            $this->markTestSkipped('This database still holds plain API keys; run gp247:update before this test.');
        }
        $plainNullable = $hadPlain
            ? (bool) (collect($this->schema()->getColumns($table))->firstWhere('name', 'apikey')['nullable'] ?? true)
            : true;

        // Leave the test transaction (Laravel's own API keeps its level counter right).
        while ($db->transactionLevel() > 0) {
            $db->rollBack();
        }
        $configTable = GP247_DB_PREFIX . 'admin_config';
        $keys = array_merge(['api_connection_required'], $this->apiSwitchKeys);
        $original = $db->table($configTable)->where('store_id', GP247_STORE_ID_GLOBAL)->whereIn('key', $keys)->get()->keyBy('key');

        try {
            foreach ($this->apiSwitchKeys as $key) {
                $this->setApiSwitch($key, true);
            }
            $db->table($configTable)->where('store_id', GP247_STORE_ID_GLOBAL)->where('key', 'api_connection_required')->update(['value' => '0']);
            if (!$hadPlain) {
                $this->schema()->table($table, fn ($t) => $t->string('apikey', 128)->nullable()->after('apiconnection'));
            }
            AdminApiConnection::forgetSchemaMemo();
            $this->flushConfigMemo();

            $body();
        } finally {
            $db->table($table)->where('apiconnection', 'like', 'zz\_%')->delete();
            foreach ($keys as $key) {
                $query = $db->table($configTable)->where('store_id', GP247_STORE_ID_GLOBAL)->where('key', $key);
                if (isset($original[$key])) {
                    $query->update(collect((array) $original[$key])->except('id')->all());
                } else {
                    $query->delete();
                }
            }
            $has = $this->schema()->hasColumn($table, 'apikey');
            if ($hadPlain && !$has) {
                $this->schema()->table($table, fn ($t) => $t->string('apikey', 128)->nullable($plainNullable)->after('apiconnection'));
            } elseif (!$hadPlain && $has) {
                $this->schema()->table($table, fn ($t) => $t->dropColumn('apikey'));
            }
            AdminApiConnection::forgetSchemaMemo();
            $this->flushConfigMemo();
            $db->beginTransaction();
        }
    }

    /**
     * Force the model's view of the schema without touching it.
     *
     * @param bool $hash  Whether apikey_hash exists.
     * @param bool $plain Whether apikey exists.
     * @return void
     */
    private function setSchemaMemo(bool $hash, bool $plain): void
    {
        foreach (['hasHashColumn' => $hash, 'hasPlainColumn' => $plain] as $property => $value) {
            (new \ReflectionProperty(AdminApiConnection::class, $property))->setValue(null, $value);
        }
    }
}
