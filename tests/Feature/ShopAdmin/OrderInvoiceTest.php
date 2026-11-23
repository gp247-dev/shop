<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin;

use GP247\Shop\Admin\Livewire\OrderManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\TestCase;

/**
 * E2E for the shop-admin order INVOICE/EMAIL actions (group E, US-SADM-003):
 * the print link reuses the legacy invoice route and "resend email" reuses the
 * existing gp247_order_process_after_success helper (which is config-gated, so a
 * no-op in tests). Domain unchanged. Gated by `admin_order`.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-003
 * @aidlc-adr ADR-001, ADR-006, ADR-007
 */
class OrderInvoiceTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopOrders;

    public function test_invoice_url_points_to_legacy_invoice_route(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();

        $url = Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->instance()->invoiceUrl();

        $this->assertStringContainsString('invoice', $url);
        $this->assertStringContainsString((string) $order->id, $url);
    }

    public function test_resend_email_runs_without_error(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->makeOrder();

        Livewire::test(OrderManager::class, ['id' => (string) $order->id])
            ->call('resendEmail')
            ->assertHasNoErrors();
    }

    public function test_resend_email_denied_for_view_all(): void
    {
        $this->asUser(viewAll: true);
        $order = $this->makeOrder();

        $component = Livewire::test(OrderManager::class, ['id' => (string) $order->id]);
        $this->assertDenied(fn () => $component->call('resendEmail'));
    }
}
