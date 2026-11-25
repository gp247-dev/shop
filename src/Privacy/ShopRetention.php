<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Core\PersonalData\Domain\ColumnRule;
use GP247\Core\PersonalData\Domain\Retention\RetentionItem;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Payment\Models\PaymentRequest;
use GP247\Shop\Payment\OrderPurpose;
use GP247\Shop\Shipping\ShipmentStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How long gp247/shop keeps its customers' data (site defaults, the owner may change them on the
 * "Data retention" screen): carts, wishlists and compare lists untouched for 180 days; password
 * reset codes once expired; and, off until the owner turns them on, closed orders anonymised after
 * 10 years (amounts kept; not while a shipment or a payment of the order is still open) and accounts
 * unused for 3 years erased after a notice (activity during the notice keeps them).
 *
 * Does nothing on a core without retention items, so this package keeps running there.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-personal-data-retention-policies, US-personal-data-retention-order-anonymise, US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_retention-registry, personal-data-retention_record-anonymise, personal-data-retention_inactive-accounts
 */
final class ShopRetention
{
    /**
     * @return void
     */
    public static function register(): void
    {
        if (!method_exists(PersonalDataRegistry::class, 'retention')) {
            return;
        }
        foreach (self::items() as $item) {
            PersonalDataRegistry::retention($item);
        }
    }

    /**
     * @return list<RetentionItem>
     */
    public static function items(): array
    {
        $owner = ShopPersonalData::OWNER;
        $cart = fn (string $key, string $instance, array $label, int $order) => RetentionItem::rows('shop.' . $key, 'shop_shoppingcart')
            ->olderThan('updated_at')->where(['instance' => $instance])->days(180, 7, 730)
            ->describe($label, $owner, $order);

        // WHY labels by locale here: no language row has to be added for them.
        return [
            $cart('carts', 'default', ['en' => 'Shopping carts not changed', 'vi' => 'Giỏ hàng không thay đổi', 'ja' => '更新のないカート'], 10),
            $cart('wishlists', 'wishlist', ['en' => 'Wishlists not changed', 'vi' => 'Danh sách yêu thích không thay đổi', 'ja' => '更新のないお気に入り'], 11),
            $cart('compare_lists', 'compare', ['en' => 'Compare lists not changed', 'vi' => 'Danh sách so sánh không thay đổi', 'ja' => '更新のない比較リスト'], 12),
            RetentionItem::rows('shop.password_resets', 'shop_password_resets')
                ->expiresAfter(static fn (): int => 60 * max(1, (int) config('auth.passwords.customer_password.expire', 60)))
                ->describe(['en' => 'Password reset codes (once expired)', 'vi' => 'Mã đặt lại mật khẩu (khi hết hạn)', 'ja' => 'パスワード再設定コード（期限切れ後）'], $owner, 20),
            RetentionItem::records('shop.orders', 'shop_order')
                ->whereUsing(static fn (Builder $query) => self::closedOrdersOnly($query))
                ->days(3650, 365, 7300)->disabledByDefault()->risky(['orders'])
                ->describe(['en' => 'Closed orders: buyer details', 'vi' => 'Đơn hàng đã đóng: thông tin người mua', 'ja' => '完了した注文: 購入者情報'], $owner, 60),
            RetentionItem::accounts('shop.inactive_accounts')
                ->findAccountsWith(static fn (\DateTimeInterface $since, ?string $after, int $limit): array => self::unusedAccounts($since, $after, $limit))
                ->countAccountsWith(static fn (\DateTimeInterface $since): int => self::unusedQuery($since)->count())
                ->checkActivityWith(static fn (string $id, \DateTimeInterface $since): bool => !self::unusedQuery($since)->where('c.id', $id)->exists())
                ->days(1095, 365, 3650)->risky(['customers'])
                ->describe(['en' => 'Customer accounts not used', 'vi' => 'Tài khoản khách lâu không dùng', 'ja' => '使われていない顧客アカウント'], $owner, 70),
        ];
    }

    /**
     * Closed orders not anonymised yet, without a shipment still on its way or a payment still
     * waiting for money (the same things that hold a customer's erasure).
     *
     * @param Builder $query Rows of shop_order.
     * @return void
     */
    public static function closedOrdersOnly(Builder $query): void
    {
        $p = GP247_DB_PREFIX;
        $orders = $p . 'shop_order';
        $schema = Schema::connection(GP247_DB_CONNECTION);
        // WHY not already anonymised: an anonymised order gets the marker as its name and is not picked again.
        $query->whereIn('status', OpenOrderBlocker::closedStatuses())
            ->where(fn (Builder $q) => $q->whereNull('first_name')->orWhere('first_name', '<>', ColumnRule::MARKER));
        if ($schema->hasTable($p . 'shop_shipment')) {
            $final = array_map(fn (ShipmentStatus $s) => $s->value, array_filter(ShipmentStatus::cases(), fn (ShipmentStatus $s) => $s->isFinal()));
            $query->whereNotExists(fn (Builder $s) => $s->selectRaw('1')->from($p . 'shop_shipment as rs')
                ->whereColumn('rs.order_id', $orders . '.id')->whereNotIn('rs.status', $final));
        }
        if ($schema->hasTable($p . 'payment_request')) {
            $query->whereNotExists(fn (Builder $r) => $r->selectRaw('1')->from($p . 'payment_request as rp')
                ->where('rp.subject_type', OrderPurpose::SUBJECT_TYPE)->whereColumn('rp.subject_id', $orders . '.id')
                ->whereIn('rp.status', [PaymentRequest::STATUS_OPEN, PaymentRequest::STATUS_PARTIAL]));
        }
    }

    /**
     * Accounts with no sign-in, order or app use since the date (an account never signed in counts
     * from its creation), ordered by id.
     *
     * @param \DateTimeInterface $since
     * @param string|null        $after Last id of the previous slice.
     * @param int                $limit
     * @return list<array{id: string, store_id: string|null}>
     */
    public static function unusedAccounts(\DateTimeInterface $since, ?string $after, int $limit): array
    {
        $query = self::unusedQuery($since)->select(['c.id', 'c.store_id']);
        if ($after !== null) {
            $query->where('c.id', '>', $after);
        }

        return $query->orderBy('c.id')->limit($limit)->get()
            ->map(fn ($row) => ['id' => (string) $row->id, 'store_id' => $row->store_id === null ? null : (string) $row->store_id])->all();
    }

    /**
     * @param \DateTimeInterface $since
     * @return Builder Accounts (alias c) with no sign-in, order or app use since the date.
     */
    public static function unusedQuery(\DateTimeInterface $since): Builder
    {
        $p = GP247_DB_PREFIX;
        $schema = Schema::connection(GP247_DB_CONNECTION);
        $morph = (new ShopCustomer())->getMorphClass();
        $query = DB::connection(GP247_DB_CONNECTION)->table($p . 'shop_customer as c')
            ->whereNotExists(fn (Builder $o) => $o->selectRaw('1')->from($p . 'shop_order as o')->whereColumn('o.customer_id', 'c.id')->where('o.created_at', '>=', $since));
        if ($schema->hasColumn($p . 'shop_customer', 'last_login_at')) {
            $query->where(fn (Builder $w) => $w->where('c.last_login_at', '<', $since)
                ->orWhere(fn (Builder $n) => $n->whereNull('c.last_login_at')->where('c.created_at', '<', $since)));
        } else {
            $query->where('c.created_at', '<', $since);
        }
        // WHY both: the app signs in through v2 sessions or older v1 tokens, each with its last use.
        foreach ([$p . 'api_session', 'personal_access_tokens'] as $tokens) {
            if ($schema->hasTable($tokens)) {
                $query->whereNotExists(fn (Builder $t) => $t->selectRaw('1')->from($tokens . ' as t')
                    ->where('t.tokenable_type', $morph)->whereColumn('t.tokenable_id', 'c.id')->where('t.last_used_at', '>=', $since));
            }
        }

        return $query;
    }
}
