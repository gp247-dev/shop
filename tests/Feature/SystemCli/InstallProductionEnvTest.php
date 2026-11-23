<?php

namespace GP247\Shop\Tests\Feature\SystemCli;

use GP247\Core\Commands\Install;
use GP247\Core\Console\GP247Command;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use GP247\Shop\Tests\TestCase;

/**
 * The install commands work the same whatever APP_ENV is, and front/shop-install do not wipe a site's
 * data without consent.
 *
 * Incident 2026-11-15 on a Docker production site (APP_ENV=production pinned by the compose file):
 * Laravel cancelled `migrate` (no --force, nobody to answer) and the install carried on — front-install
 * had already dropped the front tables, so the seeder failed on a missing table.
 *
 * WHY fakes: the real install steps DROP and recreate tables; they must never run against the shared
 * test database. `migrate`, `db:seed`, the uninstall commands, `vendor:publish` and `gp247:template-setup`
 * are replaced by fakes that only record how they were called; every test proves the fakes are what runs.
 * The remaining real work (rows of the `migrations` / `admin_config` tables) is DML and rolls back.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-003
 * @aidlc-adr system-cli_command-registration-tiers
 */
class InstallProductionEnvTest extends TestCase
{
    use DatabaseTransactions;

    /** Commands replaced by a recording fake in every test. */
    private const FAKED = ['migrate', 'db:seed', 'gp247:front-uninstall', 'gp247:shop-uninstall', 'vendor:publish', 'gp247:template-setup', 'storage:link'];

    /**
     * Calls the fakes received, in order: [command name, options].
     *
     * @var array<int, array{0: string, 1: array<string, mixed>}>
     */
    public static array $calls = [];

    /**
     * Exit code each fake returns, by command name (absent = 0).
     *
     * @var array<string, int>
     */
    public static array $exitCodes = [];

    /**
     * Replace the destructive steps with recording fakes and run as a production site.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::$calls = [];
        self::$exitCodes = [];
        Install::$lastAdmin = null;

        $this->registerFakes(self::FAKED);
        // WHY: core-install writes the installed marker of this development site (storage/ is shared
        // with the running site); a fake disk keeps every run, even a broken one, away from it.
        Storage::fake('local');
        // WHY: the framework asks before migrating only in production — the environment of the incident.
        $this->app['env'] = 'production';
    }

    /**
     * Register a recording fake for each command name and check it replaced the real one.
     *
     * @param array<int, string> $names
     * @param bool $withForce Whether the fakes declare a --force option.
     * @return void
     */
    private function registerFakes(array $names, bool $withForce = true): void
    {
        foreach ($names as $name) {
            $fake = new FakeRecordingCommand($name, $withForce);
            $this->app[Kernel::class]->registerCommand($fake);
            // Safety: the real command would DROP tables — stop here unless the fake is what runs.
            $this->assertSame($fake, Artisan::all()[$name], "$name must be the fake");
        }
    }

    /**
     * Run a command and return its exit code and output.
     *
     * @param string $command
     * @param array<string, mixed> $options
     * @return array{code: int, output: string}
     */
    private function runCommand(string $command, array $options = []): array
    {
        $buffer = new BufferedOutput();
        $code = Artisan::call($command, $options, $buffer);

        return ['code' => $code, 'output' => $buffer->fetch()];
    }

    /**
     * Options of every recorded call to one fake.
     *
     * @param string $name
     * @return array<int, array<string, mixed>>
     */
    private function callsTo(string $name): array
    {
        return array_values(array_map(
            fn ($call) => $call[1],
            array_filter(self::$calls, fn ($call) => $call[0] === $name)
        ));
    }

    /**
     * The last JSON envelope a --json run printed.
     *
     * @param string $output
     * @return array<string, mixed>
     */
    private function envelope(string $output): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), 'strlen'));

        return (array) json_decode((string) end($lines), true);
    }

    public function test_front_and_shop_install_migrate_with_force_on_a_production_site(): void
    {
        foreach (['gp247:front-install' => 1, 'gp247:shop-install' => 5] as $command => $migrations) {
            self::$calls = [];
            $run = $this->runCommand($command, ['--force' => 1]);

            $this->assertSame(0, $run['code'], $command . ' finishes: ' . $run['output']);
            $migrate = $this->callsTo('migrate');
            $this->assertCount($migrations, $migrate, $command . ' migrations');
            foreach ($migrate as $options) {
                $this->assertTrue((bool) ($options['force'] ?? false), $command . ' migrates ' . ($options['path'] ?? '') . ' with --force');
            }
        }
    }

    public function test_core_install_migrates_with_force_and_stops_when_a_migration_fails(): void
    {
        // WHY the failing migrate: it keeps core-install before seeding, the administrator password and
        // the marker — the marker also goes to the fake disk of setUp() should the stop ever regress.
        self::$exitCodes['migrate'] = 1;

        $run = $this->runCommand('gp247:core-install', ['--force' => 1, '--json' => true]);
        $envelope = $this->envelope($run['output']);

        $this->assertNotSame(0, $run['code']);
        $this->assertSame('migration_failed', $envelope['error']['code'] ?? null);
        $this->assertCount(1, $this->callsTo('migrate'), 'nothing runs after the failed migration');
        $this->assertTrue((bool) ($this->callsTo('migrate')[0]['force'] ?? false), 'core-install migrates with --force');
        $this->assertSame([], $this->callsTo('db:seed'), 'no seeding after a failed migration');
    }

    public function test_a_failed_migration_stops_front_and_shop_install_before_seeding(): void
    {
        self::$exitCodes['migrate'] = 1;
        foreach (['gp247:front-install', 'gp247:shop-install'] as $command) {
            self::$calls = [];
            $run = $this->runCommand($command, ['--force' => 1, '--json' => true]);
            $envelope = $this->envelope($run['output']);

            $this->assertNotSame(0, $run['code'], $command);
            $this->assertSame('migration_failed', $envelope['error']['code'] ?? null, $command);
            $this->assertStringContainsString('00_00_00_create_tables_', (string) ($envelope['error']['message'] ?? ''), $command . ' names the migration');
            $this->assertSame([], $this->callsTo('db:seed'), $command . ' seeds nothing after a failed migration');
        }
    }

    public function test_unattended_front_and_shop_install_refuse_to_wipe_existing_tables(): void
    {
        foreach (['gp247:front-install' => 'front_layout_block', 'gp247:shop-install' => 'shop_product'] as $command => $table) {
            $this->assertTrue(Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . $table), "test DB has $table");
            self::$calls = [];

            $run = $this->runCommand($command, ['--json' => true]);
            $envelope = $this->envelope($run['output']);

            $this->assertNotSame(0, $run['code'], $command);
            $this->assertSame('confirmation_required', $envelope['error']['code'] ?? null, $command);
            $this->assertStringContainsString('--force=1', (string) ($envelope['error']['message'] ?? ''), $command . ' says how to go on');
            $this->assertSame([], self::$calls, $command . ' uninstalls, migrates and seeds nothing');
        }
    }

    public function test_declining_the_reinstall_question_leaves_the_tables_alone(): void
    {
        $this->artisan('gp247:front-install')
            ->expectsConfirmation('Reinstall front and lose its existing data?', 'no')
            ->assertExitCode(0);
        $this->artisan('gp247:shop-install')
            ->expectsConfirmation('Reinstall shop and lose its existing data?', 'no')
            ->assertExitCode(0);

        $this->assertSame([], self::$calls, 'nothing was uninstalled after "no"');
    }

    public function test_a_site_without_the_module_tables_installs_without_being_asked(): void
    {
        $this->app[Kernel::class]->registerCommand(new FakeGuardedInstall());

        $run = $this->runCommand('gp247:fake-guarded-install', ['--json' => true]);

        $this->assertSame(0, $run['code'], $run['output']);
        $this->assertTrue($this->envelope($run['output'])['data']['installed'] ?? false);
    }

    public function test_the_install_orchestrator_passes_force_to_front_and_shop_install(): void
    {
        $this->registerFakes(['gp247:core-install', 'gp247:front-install', 'gp247:shop-install', 'gp247:mcp-install'], true);

        $run = $this->runCommand('gp247:install', ['--force' => 1]);

        $this->assertSame(0, $run['code'], $run['output']);
        foreach (['gp247:core-install', 'gp247:front-install', 'gp247:shop-install'] as $step) {
            $this->assertSame('1', (string) ($this->callsTo($step)[0]['force'] ?? ''), "$step receives --force=1");
        }
    }

    public function test_the_install_orchestrator_does_not_pass_force_to_steps_without_that_option(): void
    {
        $this->registerFakes(['gp247:core-install'], true);
        // Front/shop/mcp of an older release: no --force option — passing one would abort the install.
        $this->registerFakes(['gp247:front-install', 'gp247:shop-install', 'gp247:mcp-install'], false);

        $run = $this->runCommand('gp247:install', ['--force' => 1]);

        $this->assertSame(0, $run['code'], $run['output']);
        $this->assertCount(1, $this->callsTo('gp247:front-install'));
        $this->assertCount(1, $this->callsTo('gp247:shop-install'));
    }
}

/**
 * Stand-in for a command the install steps call: records its options, returns the configured exit code.
 */
class FakeRecordingCommand extends Command
{
    /**
     * @param string $name Command name it replaces.
     * @param bool $withForce Whether it declares a --force option.
     */
    public function __construct(string $name, bool $withForce)
    {
        $this->signature = $name . ' {--path=} {--class=} {--tag=} {--type=} {--key=}'
            . ($withForce ? ' {--force=}' : '');
        parent::__construct();
    }

    /**
     * Record the call.
     *
     * @return int
     */
    public function handle(): int
    {
        $options = array_filter($this->options(), fn ($value) => $value !== null && $value !== false);
        InstallProductionEnvTest::$calls[] = [$this->getName(), $options];

        return InstallProductionEnvTest::$exitCodes[$this->getName()] ?? 0;
    }
}

/**
 * A module install guarded by a table that does not exist — the state of a fresh site.
 */
class FakeGuardedInstall extends GP247Command
{
    /** @var string */
    protected $signature = 'gp247:fake-guarded-install {--force=0}';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        if (($stop = $this->guardReinstall('fake_module_table_absent', 'fake')) !== null) {
            return $stop;
        }

        return $this->respondSuccess(['installed' => true]);
    }
}
