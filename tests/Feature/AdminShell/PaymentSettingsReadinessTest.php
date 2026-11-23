<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Infrastructure\ReadinessCheck;
use GP247\Core\Models\AdminConfig;
use GP247\Shop\Payment\PaymentSettings;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\TestCase;

/**
 * One place answers "what does the shop still need before shoppers can pay with this
 * plugin?": the payment step at checkout and the plugin being on for the store — read
 * fresh, for the store being checked, so every payment plugin shows the same thing.
 *
 * @aidlc-unit payment-request
 * @aidlc-story US-payment-request-plugin-readiness-check
 */
#[Group('payment-request')]
class PaymentSettingsReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private const PLUGIN = 'FakePaymentReadiness';

    public function test_the_payment_step_off_is_an_error_with_a_link_to_the_order_tab(): void
    {
        $this->setRow('use_payment', '0');

        $check = $this->byKey(PaymentSettings::readiness($this->root()))['payment_enabled'];

        $this->assertSame(ReadinessCheck::ERROR, $check->level);
        $this->assertNotSame('', $check->message);
        $this->assertSame((string) gp247_route_admin('admin_config.index'), $check->fixUrl);
        $this->assertNotSame('', $check->fixLabel);
    }

    public function test_the_payment_step_on_passes(): void
    {
        $this->setRow('use_payment', '1');

        $check = $this->byKey(PaymentSettings::readiness($this->root()))['payment_enabled'];

        $this->assertTrue($check->passed());
        $this->assertNotSame('', $check->message);
    }

    public function test_without_a_plugin_key_only_the_payment_step_is_checked(): void
    {
        $this->setRow('use_payment', '1');

        $this->assertSame(['payment_enabled'], array_keys($this->byKey(PaymentSettings::readiness($this->root()))));
    }

    public function test_a_named_plugin_must_be_enabled_for_the_store_being_checked(): void
    {
        $this->setRow('use_payment', '1');
        $this->setRow(self::PLUGIN, '0', 'Plugins');

        $check = $this->byKey(PaymentSettings::readiness($this->root(), self::PLUGIN))['provider_enabled'];
        $this->assertSame(ReadinessCheck::ERROR, $check->level);
        $this->assertSame((string) gp247_route_admin('admin_plugin.index'), $check->fixUrl);

        $this->setRow(self::PLUGIN, '1', 'Plugins');

        $this->assertTrue($this->byKey(PaymentSettings::readiness($this->root(), self::PLUGIN))['provider_enabled']->passed());
    }

    public function test_a_plugin_with_no_row_at_all_is_not_enabled(): void
    {
        $this->setRow('use_payment', '1');

        $this->assertSame(ReadinessCheck::ERROR, $this->byKey(PaymentSettings::readiness($this->root(), self::PLUGIN))['provider_enabled']->level);
    }

    public function test_only_the_plugins_row_counts_as_the_plugin_switch(): void
    {
        $this->setRow('use_payment', '1');
        $this->setRow(self::PLUGIN, '1', 'not_plugins');

        $this->assertSame(ReadinessCheck::ERROR, $this->byKey(PaymentSettings::readiness($this->root(), self::PLUGIN))['provider_enabled']->level);
    }

    public function test_the_store_row_wins_over_the_global_base(): void
    {
        $this->setRow('use_payment', '1');
        $this->setRow(self::PLUGIN, '1', 'Plugins');
        $this->setRow('use_payment', '0', 'gp247_cart', $this->root());
        $this->setRow(self::PLUGIN, '0', 'Plugins', $this->root());

        $checks = $this->byKey(PaymentSettings::readiness($this->root(), self::PLUGIN));

        $this->assertSame(ReadinessCheck::ERROR, $checks['payment_enabled']->level);
        $this->assertSame(ReadinessCheck::ERROR, $checks['provider_enabled']->level);
        // GLOBAL itself still reads its own rows.
        $global = $this->byKey(PaymentSettings::readiness((string) GP247_STORE_ID_GLOBAL, self::PLUGIN));
        $this->assertTrue($global['payment_enabled']->passed());
        $this->assertTrue($global['provider_enabled']->passed());
    }

    public function test_a_setting_changed_during_the_request_is_seen_at_once(): void
    {
        $this->setRow('use_payment', '1');
        gp247_config('use_payment', $this->root()); // warm the per-request cache

        AdminConfig::where('key', 'use_payment')->update(['value' => '0']);

        $this->assertSame(ReadinessCheck::ERROR, $this->byKey(PaymentSettings::readiness($this->root()))['payment_enabled']->level);
    }

    /**
     * @param array<int, ReadinessCheck> $checks Checks.
     * @return array<string, ReadinessCheck>
     */
    private function byKey(array $checks): array
    {
        $out = [];
        foreach ($checks as $check) {
            $this->assertInstanceOf(ReadinessCheck::class, $check);
            $out[$check->key] = $check;
        }

        return $out;
    }

    /**
     * @return string
     */
    private function root(): string
    {
        return (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1');
    }

    /**
     * Write one admin_config row; a GLOBAL write also drops store overrides of the key.
     *
     * @param string      $key   Key.
     * @param string      $value Value.
     * @param string      $group admin_config group.
     * @param string|null $store Store id (null = GLOBAL).
     * @return void
     */
    private function setRow(string $key, string $value, string $group = 'gp247_cart', ?string $store = null): void
    {
        $global = (string) GP247_STORE_ID_GLOBAL;
        $store ??= $global;
        if ($store === $global) {
            AdminConfig::where('key', $key)->where('store_id', '<>', $global)->delete();
        }
        AdminConfig::where('key', $key)->where('store_id', $store)->delete();
        AdminConfig::insert(['group' => $group, 'code' => $group, 'key' => $key, 'store_id' => $store, 'value' => $value, 'sort' => 0, 'detail' => '']);
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $name => $empty) {
            $prop = new \ReflectionProperty(AdminConfig::class, $name);
            $prop->setValue(null, $empty);
        }
    }
}
