<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Core\Models\AdminConfig;
use GP247\Shop\Admin\Database\Seeders\DataShopDefaultSeeder;
use GP247\Shop\Admin\Livewire\ShopConfigForm;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\TestCase;

/**
 * The shop "Send mail" tab hands the two contact-form mail toggles over to
 * gp247/front (Unit front-contact): shop stops seeding them, and once their
 * `code` moves to `contact_config` the tab no longer offers them, while the
 * key names (and the values a site already set) stay untouched.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-sendmail-contact-handover
 * @aidlc-adr front-contact_contact-form-in-front
 */
class SendmailContactHandoverTest extends TestCase
{
    use DatabaseTransactions;

    /** The two keys whose ownership moves from shop to front. */
    private const CONTACT_KEYS = ['contact_to_admin', 'contact_to_customer'];

    /**
     * WHY reset: other tests in the same process leave AdminConfig's memoised
     * lookups null or stale, which gp247_config() would then trip over.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['getAllGlobal' => null, 'getAllConfigOfStore' => []] as $prop => $empty) {
            (new \ReflectionProperty(AdminConfig::class, $prop))->setValue(null, $empty);
        }
    }

    /**
     * Store id the screen targets for store-scoped keys (no session in tests).
     *
     * @return int Root store id.
     */
    private function storeId(): int
    {
        return defined('GP247_STORE_ID_ROOT') ? (int) GP247_STORE_ID_ROOT : 1;
    }

    /**
     * Keys listed on the Send mail tab, read through the same buildTabs() the screen renders.
     *
     * @return array<int, string> Config keys of the sendmail tab.
     */
    private function sendmailTabKeys(): array
    {
        // WHY: mount() is where the screen picks the admin's store; buildTabs() alone
        // would read an empty store id, so set the same root store mount() falls back to.
        $form = new ShopConfigForm;
        $form->storeId = (string) $this->storeId();
        $m = new \ReflectionMethod(ShopConfigForm::class, 'buildTabs');
        $m->setAccessible(true);
        $tabs = collect($m->invoke($form))->keyBy('id');

        return collect($tabs['sendmail']['fields'] ?? [])->pluck('key')->all();
    }

    public function test_shop_seeder_no_longer_seeds_the_contact_form_mail_toggles(): void
    {
        $keys = collect((new DataShopDefaultSeeder)->dataConfigShop('STORE-TEST'))->pluck('key');

        foreach (self::CONTACT_KEYS as $key) {
            $this->assertNotContains($key, $keys, "shop must not seed {$key}; front-contact owns it");
        }
        $this->assertContains('order_success_to_admin', $keys, 'the other send-mail toggles are still seeded by shop');
    }

    public function test_send_mail_tab_hides_contact_toggles_once_moved_to_contact_config_and_keeps_their_values(): void
    {
        foreach (self::CONTACT_KEYS as $key) {
            AdminConfig::updateOrCreate(
                ['key' => $key, 'store_id' => $this->storeId()],
                ['group' => 'gp247_cart', 'code' => 'contact_config', 'value' => '1', 'sort' => 0, 'detail' => 'admin.sendmail_config.' . $key],
            );
        }
        AdminConfig::updateOrCreate(
            ['key' => 'zz_sendmail_probe', 'store_id' => $this->storeId()],
            ['group' => 'gp247_cart', 'code' => 'sendmail_config', 'value' => '0', 'sort' => 9, 'detail' => 'admin.sort'],
        );

        $tabKeys = $this->sendmailTabKeys();

        $this->assertContains('zz_sendmail_probe', $tabKeys, 'the tab still lists sendmail_config rows');
        foreach (self::CONTACT_KEYS as $key) {
            $this->assertNotContains($key, $tabKeys, "{$key} belongs to the front Contact tab now");
            $this->assertSame('1', (string) gp247_config($key, $this->storeId()), "{$key} keeps its value: lookups go by key, not code");
        }
    }
}
