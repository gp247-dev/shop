<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Application\SourceQuery;
use GP247\Core\PersonalData\Domain\DataSubject;
use GP247\Core\PersonalData\Domain\ErasureBlocker;
use GP247\Shop\Models\ShopOrderStatus;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Shipping\ShipmentStatus;

/**
 * A customer is not erased while one of their orders (account orders and guest orders with
 * their email) is still in progress: its status is not a closed one, a shipment of it is still
 * on the way, or a payment or refund request for it is still waiting. Erasing then would leave
 * the shop unable to deliver, contact or refund.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr customer-privacy_admin-delete-flow
 */
final class OpenOrderBlocker implements ErasureBlocker
{
    public const OPEN_ORDER = 'open_order';
    public const OPEN_SHIPMENT = 'open_shipment';
    public const OPEN_PAYMENT = 'open_payment';
    private const LIMIT = 50;

    /**
     * @param DataSubject $subject
     * @return list<array{reason: string, ref: string, label: string}>
     */
    public function blockers(DataSubject $subject): array
    {
        $orders = PersonalDataRegistry::source('shop_order');
        if ($orders === null || !SourceQuery::exists('shop_order')) {
            return [];
        }
        $out = [];
        $open = SourceQuery::forSubject($orders, $subject)
            ->whereNotIn('status', self::closedStatuses())
            ->orderBy('created_at')->limit(self::LIMIT)->pluck('id')->all();
        foreach ($open as $id) {
            $out[] = $this->reason(self::OPEN_ORDER, (string) $id);
        }
        foreach ($this->openShipments($subject) as $orderId) {
            $out[] = $this->reason(self::OPEN_SHIPMENT, $orderId);
        }
        foreach ($this->openPayments($subject) as $ref) {
            $out[] = $this->reason(self::OPEN_PAYMENT, $ref);
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    public static function closedStatuses(): array
    {
        $configured = config('gp247-config.shop.personal_data.closed_order_statuses');
        $default = [ShopOrderStatus::CANCELED, ShopOrderStatus::DONE, ShopOrderStatus::FAILED, ShopOrderStatus::REFUNDED];

        return array_values(array_map('intval', is_array($configured) && $configured !== [] ? $configured : $default));
    }

    /**
     * @param DataSubject $subject
     * @return list<string> Order ids with a shipment not finished yet.
     */
    private function openShipments(DataSubject $subject): array
    {
        $source = PersonalDataRegistry::source('shop_shipment');
        if ($source === null || !SourceQuery::exists('shop_shipment')) {
            return [];
        }
        $final = array_map(fn (ShipmentStatus $s) => $s->value, array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $s) => $s->isFinal()));

        return array_map('strval', SourceQuery::forSubject($source, $subject)
            ->whereNotIn('status', $final)->limit(self::LIMIT)->distinct()->pluck('order_id')->all());
    }

    /**
     * @param DataSubject $subject
     * @return list<string> Payment requests still waiting for money ("#id").
     */
    private function openPayments(DataSubject $subject): array
    {
        $source = PersonalDataRegistry::source('payment_request');
        if ($source === null || !SourceQuery::exists('payment_request')) {
            return [];
        }
        $ids = SourceQuery::forSubject($source, $subject)
            ->whereIn('status', [PaymentRequest::STATUS_OPEN, PaymentRequest::STATUS_PARTIAL])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->limit(self::LIMIT)->pluck('id')->all();

        return array_map(fn ($id) => '#' . $id, $ids);
    }

    /**
     * @param string $reason
     * @param string $ref
     * @return array{reason: string, ref: string, label: string}
     */
    private function reason(string $reason, string $ref): array
    {
        return [
            'reason' => $reason,
            'ref' => $ref,
            'label' => (string) gp247_language_render('admin.customer.erase.blocked_' . $reason, ['ref' => $ref]),
        ];
    }
}
