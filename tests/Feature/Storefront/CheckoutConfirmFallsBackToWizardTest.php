<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Front\Livewire\CheckoutWizard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Shop\Tests\TestCase;

/**
 * The confirm route must never dead-end when a template owns no confirm screen.
 *
 * GP247Front renders confirm as step 4 of CheckoutWizard on the checkout screen and
 * ships no screen.shop_checkout_confirm — the 1.x blade went away with the core 2.0
 * port (gp247/shop commit 398bb4d, 2026-07-06) while the route and controller that
 * render it stayed. gp247_check_view() echoes a bare "view not found" and exit()s, so
 * every entry into that route ended on a blank page.
 *
 * That was reachable in the live wizard flow, not just legacy code: addOrder()'s
 * price-changed guard (RISK-BIZ-price-change-mid-checkout) redirects here so the
 * shopper can re-confirm a total that moved mid-checkout — and instead of protecting
 * them it ended their checkout on a blank page with no order placed.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-006
 */
class CheckoutConfirmFallsBackToWizardTest extends TestCase
{
    use DatabaseTransactions;

    /** Session shape commitToSession() writes once the wizard reaches confirm. */
    private function committedCheckoutSession(array $overrides = []): array
    {
        return array_merge([
            'step' => 'checkout.confirm',
            'shippingMethod' => 'ShippingStandard',
            'paymentMethod' => 'CashPayment',
            'address_process' => 'new',
            'shippingAddress' => [
                'first_name' => 'Lan',
                'last_name' => 'Nguyen',
                'first_name_kana' => '',
                'last_name_kana' => '',
                'email' => 'lan@example.test',
                'phone' => '0900000000',
                'city' => 'Ha Noi',
                'district' => 'Ba Dinh',
                'address1' => '12 Phan Dinh Phung',
                'address2' => '',
                'address3' => '',
                'postcode' => '100000',
                'country' => 'VN',
                'company' => '',
                'comment' => 'leave at door',
            ],
        ], $overrides);
    }

    public function test_gp247front_ships_no_separate_confirm_screen(): void
    {
        // The premise of the fallback. If a confirm screen ever comes back, this
        // fails and the fallback should be revisited rather than left dormant.
        $this->assertFalse(
            view()->exists('GP247TemplatePath::GP247Front.screen.shop_checkout_confirm'),
            'GP247Front renders confirm inside CheckoutWizard; a second confirm screen would duplicate it'
        );
        $this->assertFalse(
            view()->exists('gp247-shop-front::screen.shop_checkout_confirm'),
            'gp247/shop ships no confirm screen default since the core 2.0 port'
        );
    }

    public function test_confirm_route_redirects_to_checkout_instead_of_dead_ending(): void
    {
        session($this->committedCheckoutSession());

        $response = $this->get(gp247_route_front('checkout.confirm'));

        $response->assertRedirect(gp247_route_front('checkout'));
    }

    public function test_confirm_route_keeps_the_step_marker_so_the_wizard_can_resume(): void
    {
        // processCheckout() only FLASHES the marker, so it would be gone by the time
        // the wizard mounts if the redirect did not persist it again.
        session($this->committedCheckoutSession());

        $this->get(gp247_route_front('checkout.confirm'));

        $this->assertSame('checkout.confirm', session('step'));
    }

    public function test_confirm_route_carries_the_price_changed_notice_across(): void
    {
        // addOrder() flashes this before redirecting here; without reflash() the
        // shopper would land on the wizard with no idea why they were sent back.
        session($this->committedCheckoutSession());
        session()->flash('warning', 'The price changed, please review');

        $this->get(gp247_route_front('checkout.confirm'));

        $this->assertSame('The price changed, please review', session('warning'));
    }

    public function test_confirm_route_still_sends_a_stale_session_back_to_the_cart(): void
    {
        // The pre-existing guards run first and must keep winning: no address means
        // there is nothing to confirm, wizard or not.
        session(['step' => 'checkout.confirm']);

        $this->get(gp247_route_front('checkout.confirm'))
            ->assertRedirect(gp247_route_front('cart'));
    }

    public function test_wizard_resumes_on_the_confirm_step(): void
    {
        session($this->committedCheckoutSession());

        Livewire::test(CheckoutWizard::class)->assertSet('step', 'confirm');
    }

    public function test_wizard_resume_restores_the_address_it_prints_on_confirm(): void
    {
        // The confirm step prints these from the component's own properties, so a
        // bare step jump would show a confirmed order over a blank address.
        session($this->committedCheckoutSession());

        Livewire::test(CheckoutWizard::class)
            ->assertSet('first_name', 'Lan')
            ->assertSet('last_name', 'Nguyen')
            ->assertSet('email', 'lan@example.test')
            ->assertSet('phone', '0900000000')
            ->assertSet('city', 'Ha Noi')
            ->assertSet('address1', '12 Phan Dinh Phung')
            ->assertSet('postcode', '100000')
            ->assertSet('country', 'VN')
            ->assertSet('comment', 'leave at door')
            ->assertSet('shippingMethod', 'ShippingStandard')
            ->assertSet('paymentMethod', 'CashPayment')
            ->assertSet('address_process', 'new');
    }

    public function test_wizard_still_starts_at_the_address_step_without_the_marker(): void
    {
        // A shopper simply opening /checkout must not be dropped onto confirm.
        session($this->committedCheckoutSession(['step' => '']));

        Livewire::test(CheckoutWizard::class)->assertSet('step', 'address');
    }

    public function test_wizard_does_not_resume_on_an_empty_address(): void
    {
        session($this->committedCheckoutSession(['shippingAddress' => []]));

        Livewire::test(CheckoutWizard::class)->assertSet('step', 'address');
    }
}
