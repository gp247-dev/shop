<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Shop\Models\ShopCustomer;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Schema;

/**
 * A customer signed in (website or app): the time is kept as the account's last sign-in, and an
 * erasure waiting because the account looked unused is called off — signing in is how the customer
 * keeps it. Never breaks the sign-in: a failure is reported by class only.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-personal-data-retention-inactive-accounts
 * @aidlc-adr personal-data-retention_inactive-accounts
 */
final class CustomerActivity
{
    /** @var bool|null Memo: whether shop_customer has the last_login_at column (site updated). */
    private static ?bool $hasColumn = null;

    /**
     * Listener of Laravel's Login event: only the customer guard counts.
     *
     * @param Login $event
     * @return void
     */
    public static function onLogin(Login $event): void
    {
        if ($event->guard !== 'customer' || !$event->user instanceof ShopCustomer) {
            return;
        }
        self::signedIn($event->user);
    }

    /**
     * @param ShopCustomer $customer
     * @return void
     */
    public static function signedIn(ShopCustomer $customer): void
    {
        try {
            $id = (string) $customer->getKey();
            if (self::hasColumn()) {
                // WHY a query, not save(): updated_at and model events stay as they were — this is not an edit of the account.
                ShopCustomer::query()->whereKey($id)->toBase()->update(['last_login_at' => now()]);
            }
            if (method_exists(CustomerErasures::class, 'cancelRetention') && RequestScheduler::ready()) {
                app(CustomerErasures::class)->cancelRetention($id);
            }
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report('Customer sign-in bookkeeping failed: ' . $e::class);
            }
        }
    }

    /**
     * @return bool
     */
    private static function hasColumn(): bool
    {
        return self::$hasColumn ??= Schema::connection(GP247_DB_CONNECTION)->hasColumn((new ShopCustomer())->getTable(), 'last_login_at');
    }

    /**
     * @return void Forget the memo (tests, after gp247:update in the same process).
     */
    public static function forget(): void
    {
        self::$hasColumn = null;
    }
}
