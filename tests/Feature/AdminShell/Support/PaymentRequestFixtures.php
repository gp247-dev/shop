<?php

namespace GP247\Shop\Tests\Feature\AdminShell\Support;

use GP247\Core\AdminShell\Domain\AdminUserContract;
use GP247\Shop\Payment\Contracts\PaymentGateway;
use GP247\Shop\Payment\Contracts\PurposeResolver;
use GP247\Shop\Payment\Support\CollectResult;
use GP247\Shop\Payment\Support\RefundResult;
use GP247\Shop\Payment\Models\PaymentMovement;
use GP247\Shop\Payment\Models\PaymentRequest;
use Illuminate\Support\Facades\Schema;
use GP247\Core\Testing\ReopensTransactionAfterDdl;
use GP247\Core\Testing\FakeAdminUser;

/**
 * Shared fixtures for the Payment Request feature tests: makes sure the two core
 * tables exist (created once through the plugin's own upgrade migration when the
 * shared dev database has not been upgraded yet), registers a recording purpose
 * resolver, and binds fake admins with or without the money-out permission.
 *
 * WHY create the tables here: the tests run on the shared dev database with
 * DatabaseTransactions. The migration only adds two empty tables when they are
 * missing and never drops anything, so leaving them behind is harmless; the DDL
 * commits the open transaction, so it is re-opened right after.
 *
 * @aidlc-unit payment-request
 */
trait PaymentRequestFixtures
{
    use ReopensTransactionAfterDdl;

    protected function bootPaymentRequest(): void
    {
        if (!Schema::connection(GP247_DB_CONNECTION)->hasTable(GP247_DB_PREFIX . 'payment_request')) {
            $migration = require base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_01_200000_install_payment_request.php');
            $migration->createTables();
            // CREATE TABLE committed the test transaction on MySQL; put the rest of the
            // test back under the rollback (leak seen 2026-09-30: 2 rows kept).
            $this->reopenTransactionAfterDdl();
        }

        RecordingPurposeResolver::$calls = [];
        FakeCollectGateway::$collected = [];
        FakeCollectGateway::$throws = false;
        FakeCollectGateway::$refunds = [];
        FakeCollectGateway::$refundCalls = [];
        FakeCollectGateway::$refundThrows = false;
        FakeCollectGateway::$available = true;
        RecordingPurposeResolver::$reversed = [];
        RecordingPurposeResolver::$throws = false;

        config([
            'gp247-config.payment.purposes' => array_merge(
                (array) config('gp247-config.payment.purposes', []),
                [
                    'test.recording' => [
                        'label' => 'Recording purpose',
                        'directions' => ['in', 'out'],
                        'resolver' => RecordingPurposeResolver::class,
                    ],
                    'test.in-only' => [
                        'label' => 'Money in only',
                        'directions' => ['in'],
                        'resolver' => null,
                    ],
                    'test.over' => [
                        'label' => 'Allows over-settlement',
                        'directions' => ['in'],
                        'resolver' => null,
                        'allow_over' => true,
                    ],
                    'test.subject' => [
                        'label' => 'Needs a thing',
                        'directions' => ['in', 'out'],
                        'resolver' => SubjectCheckingResolver::class,
                        'subject' => ['types' => ['zz_thing' => 'ZZ thing', 'zz_other' => 'ZZ other thing'], 'label' => 'ZZ Thing ID', 'help' => 'ZZ where to find the id'],
                    ],
                    'test.orphan' => [
                        'label' => 'Plugin gone',
                        'directions' => ['in', 'out'],
                        'resolver' => 'App\\GP247\\Plugins\\Gone\\NoSuchResolver',
                    ],
                ]
            ),
            'gp247-config.payment.gateways' => array_merge(
                (array) config('gp247-config.payment.gateways', []),
                [
                    'test.fakepay' => [
                        'label' => 'Fake pay',
                        'capabilities' => ['collect', 'refund'],
                        'driver' => FakeCollectGateway::class,
                    ],
                    'test.refund-only' => [
                        'label' => 'Refund only',
                        'capabilities' => ['refund'],
                        'driver' => FakeCollectGateway::class,
                    ],
                ]
            ),
        ]);
    }

    /**
     * Add the link token column when the shared dev database predates it
     * (add-only; the DDL commits the test transaction, so it is re-opened).
     */
    protected function bootPublicLink(): void
    {
        if (!Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . 'payment_request', 'public_token_enc')) {
            $migration = require base_path('vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_10_01_200000_install_payment_request.php');
            $migration->createTables();
            $this->reopenTransactionAfterDdl();
        }
    }

    /**
     * Make the core believe its payment request tables are missing (a site that ran
     * composer update but not gp247:update) — without touching the real schema.
     */
    protected function pretendNotReady(): void
    {
        $prop = new \ReflectionProperty(\GP247\Shop\Payment\PaymentRequestService::class, 'ready');
        $prop->setAccessible(true);
        $prop->setValue(null, false);
    }

    protected function adminPrefix(): string
    {
        return defined('GP247_ADMIN_PREFIX') ? GP247_ADMIN_PREFIX : 'gp247_admin';
    }

    /**
     * An admin who may use the screen (create, record money IN, cancel) but has NOT
     * been granted the money-out permission.
     */
    protected function asScreenUser(): void
    {
        $this->app->bind(
            AdminUserContract::class,
            fn (): AdminUserContract => new FakeAdminUser(false, false, [$this->adminPrefix() . '/payment_request'])
        );
    }

    /**
     * Screen user who also holds `payment.request.settle_out`
     * (POST <prefix>/payment_request_settle_out).
     */
    protected function asSettleOutUser(): void
    {
        $this->app->bind(
            AdminUserContract::class,
            fn (): AdminUserContract => new FakeAdminUser(false, false, [
                $this->adminPrefix() . '/payment_request',
                $this->adminPrefix() . '/payment_request_settle_out',
            ])
        );
    }

    protected function asAdministrator(): void
    {
        $this->app->bind(AdminUserContract::class, fn (): AdminUserContract => new FakeAdminUser(true));
    }

    protected function makeRequest(array $overrides = []): PaymentRequest
    {
        return PaymentRequest::create(array_merge([
            'direction' => 'in',
            'amount' => 100,
            'currency' => 'USD',
            'purpose' => 'free',
            'party_name' => 'ZZ Partner ' . uniqid(),
            'description' => 'fixture',
            'store_id' => (string) (defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : '1'),
            'status' => PaymentRequest::STATUS_OPEN,
        ], $overrides));
    }

    protected function movementsOf(PaymentRequest $request): int
    {
        return PaymentMovement::where('request_id', $request->id)->count();
    }
}

/**
 * Purpose resolver that records every callback (and can be told to fail).
 */
class RecordingPurposeResolver implements PurposeResolver
{
    /**
     * WHY statics live here and not on the trait: a trait's static property is copied
     * into every class that uses it, so the test and this resolver would each see a
     * different variable.
     *
     * @var array<int, array{request: int, movement: int}>
     */
    public static array $calls = [];

    /** @var bool When true onSettled() throws. */
    public static bool $throws = false;

    /** @var array<int, array{request: int, movement: int}> Calls to onReversed(). */
    public static array $reversed = [];

    public function describeSubject(PaymentRequest $request): ?array
    {
        return ['label' => 'Subject #' . $request->subject_id, 'suggested_amount' => 42.5];
    }

    public function onSettled(PaymentRequest $request, PaymentMovement $movement): void
    {
        if (self::$throws) {
            throw new \RuntimeException('owner ledger refused');
        }
        self::$calls[] = ['request' => $request->id, 'movement' => $movement->id];
    }

    public function onReversed(PaymentRequest $request, PaymentMovement $movement): void
    {
        self::$reversed[] = ['request' => $request->id, 'movement' => $movement->id];
    }
}

/**
 * Gateway double that "collects" by returning a hosted-page URL and remembers every
 * call (or fails on demand).
 */
class FakeCollectGateway implements PaymentGateway
{
    /** @var array<int, int> Request ids passed to collect(). */
    public static array $collected = [];

    /** @var bool When true collect() throws. */
    public static bool $throws = false;

    /** @var array<int, array{request: int, original: int, amount: float}> Calls to refund(). */
    public static array $refunds = [];

    /** @var array<int, array{ref: string, key: string|null}> Gateway reference + idempotency key of each refund() call. */
    public static array $refundCalls = [];

    /** @var bool When true refund() throws. */
    public static bool $refundThrows = false;

    /** @var bool What availableFor() answers. */
    public static bool $available = true;

    public function key(): string
    {
        return 'test.fakepay';
    }

    public function capabilities(): array
    {
        return [self::CAP_COLLECT, self::CAP_REFUND];
    }

    public function availableFor(PaymentRequest $request): bool
    {
        return self::$available;
    }

    public function collect(PaymentRequest $request): CollectResult
    {
        if (self::$throws) {
            throw new \RuntimeException('gateway down');
        }
        self::$collected[] = (int) $request->id;

        return new CollectResult('https://pay.example.test/session/' . $request->id, 'fake_sess_' . $request->id . '_' . count(self::$collected));
    }

    public function refund(PaymentRequest $request, PaymentMovement $original, float $amount, ?string $idempotencyKey = null): RefundResult
    {
        if (self::$refundThrows) {
            throw new \RuntimeException('refund refused by gateway');
        }
        self::$refunds[] = ['request' => (int) $request->id, 'original' => (int) $original->id, 'amount' => $amount];
        self::$refundCalls[] = ['ref' => (string) $original->gateway_ref, 'key' => $idempotencyKey];

        return new RefundResult('fake_re_' . $original->id . '_' . count(self::$refunds));
    }
}

/**
 * Currency seam double: the list a shop would provide (code => decimals) and its labels.
 */
class FakeCurrencySeam
{
    /** @return array<string, int> */
    public static function catalog(): array
    {
        return ['USD' => 2, 'VND' => 0];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['USD' => 'USD — ZZ Dollar', 'VND' => 'VND — ZZ Dong'];
    }
}

/**
 * Purpose owner that vets the linked record and the currency, refusing per field.
 */
class SubjectCheckingResolver implements PurposeResolver, \GP247\Shop\Payment\Contracts\ValidatesRequest
{
    public function validateRequest(array $data): void
    {
        if (($data['subject_id'] ?? null) !== 'ok-1') {
            throw new \GP247\Shop\Payment\Support\RequestRejected('ZZ no such thing', 'subject_id');
        }
        if (($data['currency'] ?? null) !== 'USD') {
            throw new \GP247\Shop\Payment\Support\RequestRejected('ZZ wrong currency', 'currency');
        }
    }

    public function describeSubject(PaymentRequest $request): ?array
    {
        return null;
    }

    public function onSettled(PaymentRequest $request, PaymentMovement $movement): void
    {
    }

    public function onReversed(PaymentRequest $request, PaymentMovement $movement): void
    {
    }
}

/**
 * Availability callable of a purpose whose owner's tables are (not) installed.
 */
class UnavailablePurpose
{
    public static bool $available = true;

    public static function available(): bool
    {
        return self::$available;
    }
}

