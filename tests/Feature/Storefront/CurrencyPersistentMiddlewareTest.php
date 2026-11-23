<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Middleware\CurrencyMiddleware;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use GP247\Shop\Tests\TestCase;

/**
 * Verifies the deploy wiring: CurrencyMiddleware is registered as Livewire
 * persistent middleware, so it is re-applied on "livewire/update" requests
 * against the original storefront page route.
 *
 * Root cause: Livewire's update endpoint only runs the `web` middleware
 * group, never the app's `front` group that carries `check.currency`. Without
 * this registration, ShopCurrency::$exchange_rate silently resets to its
 * class default (1) for every Livewire action (e.g. ProductFilter::applyPrice),
 * converting price-range input with the wrong rate whenever the shopper's
 * active currency isn't the store's base currency. Mirrors the persistent
 * middleware fix already applied to admin RBAC (see
 * tests/Feature/AdminShell/PersistentMiddlewareTest.php, ADR-001) for the
 * same class of bug.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-005
 */
class CurrencyPersistentMiddlewareTest extends TestCase
{
    public function test_currency_middleware_is_persisted_across_livewire_updates(): void
    {
        $persistent = app(PersistentMiddleware::class)->getPersistentMiddleware();

        $this->assertContains(CurrencyMiddleware::class, $persistent);
    }
}
