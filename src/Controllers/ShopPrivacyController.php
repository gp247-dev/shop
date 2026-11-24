<?php

namespace GP247\Shop\Controllers;

use GP247\Core\PersonalData\Application\ErasureStepUp;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Core\Support\StepUp\StepUpGate;
use GP247\Core\Support\StepUp\StepUpRefused;
use GP247\Front\Controllers\RootFrontController;
use GP247\Shop\Support\ShopLayoutPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "My data" in the customer's account: the customer types their password again (and their
 * two-step code when they use one), the site prepares a package of everything it holds about
 * them (one per 24 hours), the page asks for progress every few seconds — each question builds
 * one more slice, no cron — and the customer downloads it with a button (no secret in any URL),
 * while signed in, within 24 hours. "Delete my account" asks the same confirmation; the erasure
 * waits the days set for the site and can be called off from the account or with the link of
 * the email.
 *
 * Rows found by email (guest orders, messages) are added only when the account's email is
 * verified: anyone can sign up with someone else's address.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-self-export, US-customer-privacy-self-delete
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class ShopPrivacyController extends RootFrontController
{
    /**
     * @return \Illuminate\Contracts\View\View
     */
    public function index()
    {
        $customer = customer()->user();
        if (ExportService::ready()) {
            app(ExportService::class)->pruneSometimes();
        }
        $subPath = 'account.shop_privacy';
        $view = gp247_shop_process_view($this->GP247TemplatePath, $subPath);
        gp247_check_view($view);

        return view($view)->with([
            'title' => gp247_language_render('customer.privacy.title'),
            'customer' => $customer,
            'available' => ExportService::ready(),
            'needsCode' => $this->gate()->codeRequired(),
            'package' => $this->state((string) $customer->id),
            'erasure' => $this->erasureState((string) $customer->id),
            'waitDays' => PrivacySettings::waitDays(),
            'layout_page' => ShopLayoutPage::Profile->value,
            'breadcrumbs' => [
                ['url' => gp247_route_front('customer.index'), 'title' => gp247_language_render('customer.my_profile')],
                ['url' => '', 'title' => gp247_language_render('customer.privacy.title')],
            ],
        ]);
    }

    /**
     * The customer asks for their data: password (and code) first, then the package is prepared.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function export(Request $request): RedirectResponse
    {
        $customer = customer()->user();
        if (!ExportService::ready()) {
            return redirect()->back()->with('privacy_error', gp247_language_render('customer.privacy.unavailable'));
        }
        try {
            $this->gate()->confirm((string) $request->input('password', ''), (string) $request->input('code', ''));
        } catch (StepUpRefused $e) {
            return redirect()->back()->with('privacy_error', $this->refusal($e));
        }
        try {
            $result = app(PrivacyExports::class)->request(
                (string) $customer->id,
                (string) ($customer->store_id ?? GP247_STORE_ID_ROOT),
                RequestSource::Customer,
                $this->actor(),
                $customer->email_verified_at !== null,
            );
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('privacy_error', gp247_language_render('customer.privacy.unavailable'));
        }
        if (!$result['reused']) {
            app(ExportService::class)->continue($result['export_id']);
        }

        return redirect()->to(gp247_route_front('customer.privacy'))
            ->with('privacy_success', gp247_language_render($result['reused'] ? 'customer.privacy.reused' : 'customer.privacy.started'));
    }

    /**
     * "Delete my account": password (and code) first; the erasure then waits the days set for the
     * site (0 days: it runs now and the customer is signed out).
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function delete(Request $request): RedirectResponse
    {
        $customer = customer()->user();
        if (!RequestScheduler::ready()) {
            return redirect()->back()->with('privacy_error', gp247_language_render('customer.privacy.unavailable'));
        }
        try {
            $this->gate()->confirm((string) $request->input('password', ''), (string) $request->input('code', ''));
        } catch (StepUpRefused $e) {
            return redirect()->back()->with('privacy_error', $this->refusal($e));
        }
        try {
            $result = app(CustomerErasures::class)->request((string) $customer->id, (string) ($customer->store_id ?? GP247_STORE_ID_ROOT), RequestSource::Customer, $this->actor());
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('privacy_error', gp247_language_render('customer.privacy.unavailable'));
        }
        return match ($result['state']) {
            'erasing' => $this->signedOutAfterErasure($request),
            'held' => redirect()->to(gp247_route_front('customer.privacy'))->with('privacy_success', gp247_language_render('customer.privacy.delete_on_hold')),
            'failed' => redirect()->to(gp247_route_front('customer.privacy'))->with('privacy_error', gp247_language_render('customer.privacy.delete_failed')),
            default => redirect()->to(gp247_route_front('customer.privacy'))->with('privacy_success', gp247_language_render('customer.privacy.delete_scheduled', ['date' => (string) $result['runs_at']])),
        };
    }

    /**
     * The erasure runs now (wait of 0 days): the account is going, so is the session.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    private function signedOutAfterErasure(Request $request): RedirectResponse
    {
        customer()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(gp247_route_front('front.home'))->with('success', gp247_language_render('customer.privacy.deleted_now'));
    }

    /**
     * The signed-in customer calls off their erasure.
     *
     * @return RedirectResponse
     */
    public function cancelDelete(): RedirectResponse
    {
        $customerId = (string) customer()->user()->id;
        $pending = RequestScheduler::ready() ? app(CustomerErasures::class)->pendingFor($customerId) : null;
        $done = $pending !== null && app(CustomerErasures::class)->cancel((string) $pending->id, $customerId, $this->actor());

        return redirect()->to(gp247_route_front('customer.privacy'))
            ->with($done ? 'privacy_success' : 'privacy_error', gp247_language_render($done ? 'customer.privacy.delete_cancelled' : 'customer.privacy.link_invalid'));
    }

    /**
     * The "call off" link of the email: a page with a button (mail scanners open links, they
     * must not change anything).
     *
     * @param Request $request
     * @param string  $requestId
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function cancelLinkPage(Request $request, string $requestId)
    {
        if (!$request->hasValidSignature() || !RequestScheduler::ready() || app(CustomerErasures::class)->pendingById($requestId) === null) {
            return $this->privacyNotice(gp247_language_render('customer.privacy.link_invalid'), 403);
        }

        return $this->privacyNotice(gp247_language_render('customer.privacy.cancel_question'), 200, $request->fullUrl());
    }

    /**
     * @param Request $request
     * @param string  $requestId
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function cancelLink(Request $request, string $requestId)
    {
        if (!$request->hasValidSignature() || !RequestScheduler::ready()) {
            return $this->privacyNotice(gp247_language_render('customer.privacy.link_invalid'), 403);
        }
        $done = app(CustomerErasures::class)->cancel($requestId, null, ['type' => RequestLog::ACTOR_CUSTOMER, 'id' => null]);

        return $this->privacyNotice(gp247_language_render($done ? 'customer.privacy.delete_cancelled' : 'customer.privacy.link_invalid'), $done ? 200 : 403);
    }

    /**
     * Progress of the customer's package; builds one more slice while it is being prepared.
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        $customerId = (string) customer()->user()->id;
        if (ExportService::ready()) {
            $service = app(ExportService::class);
            $service->pruneSometimes();
            $recent = $service->recentFor($customerId);
            if ($recent !== null && (string) $recent->status === ExportRepository::BUILDING) {
                $service->continue((string) $recent->id);
            }
        }

        return response()->json(['data' => $this->state($customerId)])->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST: send the customer's ready package. The link is made and used in this one request, so
     * it is never in a URL, a browser history or a server log.
     *
     * @return Response
     */
    public function download(): Response
    {
        $customerId = (string) customer()->user()->id;
        $service = app(ExportService::class);
        $recent = ExportService::ready() ? $service->recentFor($customerId) : null;
        $owner = 'customer:' . $customerId;
        $token = $recent === null ? null : $service->issueToken((string) $recent->id, $owner);
        $path = $token === null ? null : $service->claim((string) $recent->id, $token, $owner);
        if ($path === null) {
            return redirect()->to(gp247_route_front('customer.privacy'))->with('privacy_error', gp247_language_render('customer.privacy.link_invalid'));
        }
        app(PrivacyExports::class)->downloaded($recent, $this->actor());
        $response = response()->download($path, 'my-data-' . now()->format('Ymd') . '.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);

        // WHY only the last time: a download that breaks halfway can be tried again.
        return $service->lastDownload((string) $recent->id) ? $response->deleteFileAfterSend() : $response;
    }

    /**
     * @param string $customerId
     * @return array{status: string|null, expires_at: string|null, next_at: string|null, downloads_left: int}
     */
    private function state(string $customerId): array
    {
        $none = ['status' => null, 'expires_at' => null, 'next_at' => null, 'downloads_left' => 0];
        if (!ExportService::ready()) {
            return $none;
        }
        $recent = app(ExportService::class)->recentFor($customerId);
        if ($recent === null) {
            return $none;
        }

        return [
            'status' => (string) $recent->status,
            'expires_at' => $recent->expires_at === null ? null : (string) $recent->expires_at,
            'next_at' => now()->parse((string) $recent->created_at)->addHours(24)->toDateTimeString(),
            'downloads_left' => max(0, ExportRepository::MAX_DOWNLOADS - (int) $recent->downloads),
        ];
    }

    /**
     * @param string $customerId
     * @return array{status: string, runs_at: string|null}|null The erasure that has not run yet, if any.
     */
    private function erasureState(string $customerId): ?array
    {
        $pending = RequestScheduler::ready() ? app(CustomerErasures::class)->pendingFor($customerId) : null;

        return $pending === null ? null : ['status' => (string) $pending->status, 'runs_at' => $pending->wait_until === null ? null : (string) $pending->wait_until];
    }

    /**
     * One message (and, for the call-off link, a confirm button) on the shop's privacy page.
     *
     * @param string      $message
     * @param int         $status
     * @param string|null $confirmUrl Form action of the "call off" button.
     * @return \Illuminate\Http\Response
     */
    private function privacyNotice(string $message, int $status, ?string $confirmUrl = null)
    {
        $view = gp247_shop_process_view($this->GP247TemplatePath, 'screen.shop_privacy_request');
        gp247_check_view($view);

        return response()->view($view, [
            'title' => gp247_language_render('customer.privacy.title'),
            'mode' => $confirmUrl === null ? 'message' : 'cancel',
            'message' => $message,
            'confirmUrl' => $confirmUrl,
            'layout_page' => ShopLayoutPage::Profile->value,
            'breadcrumbs' => [['url' => '', 'title' => gp247_language_render('customer.privacy.title')]],
        ], $status)->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param StepUpRefused $e
     * @return string
     */
    private function refusal(StepUpRefused $e): string
    {
        return match ($e->reason) {
            StepUpRefused::THROTTLED => gp247_language_render('customer.privacy.throttled', ['minutes' => max(1, (int) ceil($e->retryAfter / 60))]),
            StepUpRefused::WRONG_CODE => gp247_language_render('customer.privacy.wrong_code'),
            default => gp247_language_render('customer.password_old_notcorrect'),
        };
    }

    /**
     * @return StepUpGate
     */
    private function gate(): StepUpGate
    {
        return ErasureStepUp::customer(fn () => customer()->user());
    }

    /**
     * @return array{type: string, id: string}
     */
    private function actor(): array
    {
        return ['type' => RequestLog::ACTOR_CUSTOMER, 'id' => (string) customer()->user()->id];
    }
}
