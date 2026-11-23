<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\CurrencyManager;
use GP247\Shop\Models\ShopCurrency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin currency manager (ADR-006/007): two-column form+list,
 * create/edit (code unique, exchange rate > 0), inline edit, delete, and Layer-2
 * authorization. Domain unchanged (ShopCurrency).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-005
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class CurrencyTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    private function makeCurrency(array $overrides = []): ShopCurrency
    {
        return ShopCurrency::create(array_merge([
            'name' => 'ZZ Seed Currency',
            'code' => 'ZZ' . substr(uniqid(), -3),
            'symbol' => '$',
            'exchange_rate' => 1,
            'precision' => 2,
            'symbol_first' => 0,
            'thousands' => ',',
            'status' => 1,
            'sort' => 0,
        ], $overrides));
    }

    public function test_lists_existing_currencies(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->makeCurrency(['name' => 'ZZ List Currency']);

        Livewire::test(CurrencyManager::class)->assertSee('ZZ List Currency');
    }

    public function test_creates_currency(): void
    {
        $this->asUser(slugs: ['admin_currency']);

        Livewire::test(CurrencyManager::class)
            ->set('form.name', 'ZZ Created Currency')
            ->set('form.code', 'ZCC')
            ->set('form.symbol', '₫')
            ->set('form.exchange_rate', 23000)
            ->set('form.precision', 0)
            ->set('form.symbol_first', 0)
            ->set('form.thousands', '.')
            ->set('form.sort', 1)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertTrue(ShopCurrency::where('code', 'ZCC')->exists());
    }

    public function test_create_requires_symbol_and_positive_rate(): void
    {
        $this->asUser(slugs: ['admin_currency']);

        Livewire::test(CurrencyManager::class)
            ->set('form.name', '')
            ->set('form.code', '')
            ->set('form.symbol', '')
            ->set('form.exchange_rate', 0)
            ->call('save')
            ->assertHasErrors(['form.name', 'form.code', 'form.symbol', 'form.exchange_rate']);
    }

    public function test_code_must_be_unique(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $this->makeCurrency(['name' => 'ZZ Dup', 'code' => 'ZDP']);

        Livewire::test(CurrencyManager::class)
            ->set('form.name', 'ZZ Dup 2')
            ->set('form.code', 'ZDP')
            ->set('form.symbol', '$')
            ->set('form.exchange_rate', 1)
            ->set('form.precision', 2)
            ->set('form.symbol_first', 0)
            ->set('form.thousands', ',')
            ->set('form.sort', 0)
            ->call('save')
            ->assertHasErrors(['form.code']);
    }

    public function test_edit_loads_via_route_and_updates(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $row = $this->makeCurrency(['name' => 'ZZ Edit Currency']);

        Livewire::test(CurrencyManager::class, ['id' => (string) $row->id])
            ->assertSet('form.name', 'ZZ Edit Currency')
            ->set('form.name', 'ZZ Edited Currency')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertSame('ZZ Edited Currency', ShopCurrency::find($row->id)->name);
    }

    public function test_delete_removes_row(): void
    {
        $this->asUser(slugs: ['admin_currency']);
        $row = $this->makeCurrency(['name' => 'ZZ Delete Currency']);

        Livewire::test(CurrencyManager::class)->call('delete', $row->id);

        $this->assertNull(ShopCurrency::find($row->id));
    }

    public function test_save_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);

        $component = Livewire::test(CurrencyManager::class)
            ->set('form.name', 'Nope Currency')
            ->set('form.code', 'NOPE')
            ->set('form.symbol', '$')
            ->set('form.exchange_rate', 1)
            ->set('form.precision', 2)
            ->set('form.symbol_first', 0)
            ->set('form.thousands', ',')
            ->set('form.sort', 0);

        $this->assertDenied(fn () => $component->call('save'));
        $this->assertFalse(ShopCurrency::where('code', 'NOPE')->exists());
    }
}
