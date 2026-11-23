<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\CurrencyManager;
use GP247\Shop\Models\ShopCurrency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Shop-admin Currency screen behaviour for the explicit base + rebase flow
 * (US-SADM-currency-rebase-ui, US-CMP-base-currency-explicit):
 *   - the base currency is edit-locked in the Livewire layer, both via the inline
 *     Edit action (editRow) and when an edit form is reached directly by URL (save);
 *   - picking a rebase target pre-fills the outgoing base's new rate with the
 *     value-preserving suggestion 1/rate(target), as a plain decimal;
 *   - rebase() requires the explicit confirmation and a real (>0, !=1) outgoing
 *     rate, and on success changes the base and redirects;
 *   - rebase() is Layer-2 authorized.
 *
 * Tier-3 (needs a real DB connection): the local CLI PHP has no pdo driver, so
 * this runs in CI regression, not the TDD inner loop.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-currency-rebase-ui
 * @aidlc-story US-CMP-base-currency-explicit
 * @aidlc-adr currency-base-system-scope
 * @aidlc-adr currency-rebase-value-preserving
 */
class CurrencyBaseUiTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /**
     * Clear any seeded base flag so a test builds its own unambiguous base.
     *
     * @return void
     */
    private function clearBaseFlag(): void
    {
        ShopCurrency::query()->update(['is_base' => 0]);
        ShopCurrency::resetStaticCache();
    }

    /**
     * Create a currency row (defaults to an active, non-base secondary).
     *
     * @param array<string, mixed> $overrides Column overrides.
     * @return ShopCurrency
     */
    private function makeCurrency(array $overrides = []): ShopCurrency
    {
        return ShopCurrency::create(array_merge([
            'name' => 'ZZ Currency',
            'code' => 'ZC' . substr(uniqid(), -4),
            'symbol' => '$',
            'exchange_rate' => 1,
            'precision' => 2,
            'symbol_first' => 0,
            'thousands' => ',',
            'status' => 1,
            'is_base' => 0,
            'sort' => 0,
        ], $overrides));
    }

    public function test_edit_row_is_blocked_for_the_base_currency(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $base = $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU1', 'exchange_rate' => 1, 'is_base' => 1]);

        Livewire::test(CurrencyManager::class)
            ->call('editRow', $base->id)
            ->assertDispatched('notify', type: 'error')
            ->assertSet('editingId', null);
    }

    public function test_save_is_blocked_for_the_base_reached_by_url(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $base = $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU2', 'exchange_rate' => 1, 'is_base' => 1]);

        // Mounting with the id fills the base into the form (deep link). The save
        // guard must still refuse to persist a change to the base.
        Livewire::test(CurrencyManager::class, ['id' => (string) $base->id])
            ->set('form.name', 'ZZ Base Renamed')
            ->call('save')
            ->assertDispatched('notify', type: 'error');

        $this->assertSame('ZZ Base', ShopCurrency::find($base->id)->name);
    }

    public function test_selecting_a_target_suggests_the_inverse_rate(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU3', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Target', 'code' => 'ZTG3', 'exchange_rate' => 25000]);

        Livewire::test(CurrencyManager::class)
            ->set('rebaseConfirmed', true)
            ->set('rebaseTarget', 'ZTG3')
            // 1 / 25000 = 0.00004, rendered as a plain decimal (no scientific notation).
            ->assertSet('rebaseOldRate', '0.00004')
            // Picking a target resets a previously ticked confirmation.
            ->assertSet('rebaseConfirmed', false);
    }

    public function test_rebase_requires_confirmation(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU4', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Target', 'code' => 'ZTG4', 'exchange_rate' => 25000]);

        Livewire::test(CurrencyManager::class)
            ->set('rebaseTarget', 'ZTG4')
            ->set('rebaseOldRate', '0.00004')
            ->set('rebaseConfirmed', false)
            ->call('rebase')
            ->assertHasErrors('rebaseConfirmed');

        $this->assertSame('ZBU4', ShopCurrency::getBaseCode());
    }

    public function test_rebase_rejects_outgoing_rate_of_one(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU5', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Target', 'code' => 'ZTG5', 'exchange_rate' => 25000]);

        Livewire::test(CurrencyManager::class)
            ->set('rebaseTarget', 'ZTG5')
            ->set('rebaseOldRate', '1')
            ->set('rebaseConfirmed', true)
            ->call('rebase')
            ->assertHasErrors('rebaseOldRate');

        $this->assertSame('ZBU5', ShopCurrency::getBaseCode());
    }

    public function test_rebase_succeeds_and_changes_the_base(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU6', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Target', 'code' => 'ZTG6', 'exchange_rate' => 25000]);

        Livewire::test(CurrencyManager::class)
            ->set('rebaseTarget', 'ZTG6')
            ->set('rebaseOldRate', '0.00004')
            ->set('rebaseConfirmed', true)
            ->call('rebase')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin_currency.index'));

        $this->assertSame('ZTG6', ShopCurrency::getBaseCode());
        $this->assertEqualsWithDelta(0.00004, (float) ShopCurrency::where('code', 'ZBU6')->value('exchange_rate'), 1e-9);
    }

    public function test_rebase_is_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $this->clearBaseFlag();
        $this->makeCurrency(['name' => 'ZZ Base', 'code' => 'ZBU7', 'exchange_rate' => 1, 'is_base' => 1]);
        $this->makeCurrency(['name' => 'ZZ Target', 'code' => 'ZTG7', 'exchange_rate' => 25000]);

        $component = Livewire::test(CurrencyManager::class)
            ->set('rebaseTarget', 'ZTG7')
            ->set('rebaseOldRate', '0.00004')
            ->set('rebaseConfirmed', true);

        $this->assertDenied(fn () => $component->call('rebase'));
        $this->assertSame('ZBU7', ShopCurrency::getBaseCode());
    }
}
