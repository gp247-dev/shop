<?php

namespace GP247\Shop\Controllers;

use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\GuestRequests;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Application\Requests\PrivacySettings;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use GP247\Front\Controllers\RootFrontController;
use GP247\Shop\Support\ShopLayoutPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request form of shoppers without an account. The form answers the same for every address and
 * only stores the submission: whether the shop holds data, or an account, under it is found out
 * later by the lazy scheduler and told only to that address by email. The email link opens a page
 * whose button proves the address (mail scanners open links); a package is then prepared (the page
 * follows it, and an email brings a link to a download page when it is ready), an erasure waits
 * for the shop's approval.
 *
 * Off (settings) or with outgoing email off, the page does not exist (404).
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-guest-request, US-personal-data-requests-guest-approval
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class ShopPrivacyRequestController extends RootFrontController
{
    private const HONEYPOT = 'website';
    private const PER_EMAIL_PER_HOUR = 3;
    private const SIGNED_HOURS = 24;

    /**
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function form()
    {
        if (!self::enabled()) {
            return $this->pageNotFound();
        }

        return $this->page('form', [
            'viewCaptcha' => gp247_captcha_processview('privacy', gp247_language_render('customer.privacy.guest_submit')),
            'honeypot' => self::HONEYPOT,
        ]);
    }

    /**
     * @param Request $request
     * @return RedirectResponse|Response
     */
    public function submit(Request $request)
    {
        if (!self::enabled()) {
            return $this->pageNotFound();
        }
        // WHY the same answer: a bot learns nothing from the trap, a person nothing about an address.
        if (trim((string) $request->input(self::HONEYPOT, '')) !== '') {
            return $this->sent();
        }
        $data = $request->only(['email', 'kind']);
        $rules = ['email' => 'required|string|email:filter|max:150', 'kind' => 'required|in:export,erase'];
        $captcha = gp247_captcha_method();
        if ($captcha && in_array('privacy', gp247_captcha_page(), true)) {
            $data['captcha_field'] = $request->input($captcha->getField(), '');
            $rules['captcha_field'] = ['required', 'string', new \GP247\Core\Rules\CaptchaRule()];
        }
        $validator = Validator::make($data, $rules);
        if ($validator->fails()) {
            return redirect(gp247_route_front('shop.privacy_request'))->withErrors($validator)->withInput($request->only(['email', 'kind']));
        }
        $email = mb_strtolower(trim((string) $data['email']));
        $key = 'privacy-request:' . hash('sha256', $email);
        if (!RateLimiter::tooManyAttempts($key, self::PER_EMAIL_PER_HOUR)) {
            RateLimiter::hit($key, 3600);
            // WHY only stored: the same work for every address, so the answer and its timing tell
            // nothing; the lookup and the email happen later, in the lazy scheduler.
            app(GuestRequests::class)->submit($email, (string) $data['kind'], (string) (config('app.storeId') ?? GP247_STORE_ID_ROOT));
        }

        return $this->sent();
    }

    /**
     * The link of the email: a page with a button — mail scanners open links, they must not
     * confirm anything.
     *
     * @param Request $request
     * @param string  $requestId
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function verifyPage(Request $request, string $requestId)
    {
        if (!$request->hasValidSignature() || !RequestScheduler::ready() || app(GuestRequests::class)->awaitingConfirmation($requestId) === null) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }

        return $this->page('confirm', ['message' => gp247_language_render('customer.privacy.guest_confirm'), 'confirmUrl' => $request->fullUrl()]);
    }

    /**
     * The button of the link's page (signed POST).
     *
     * @param Request $request
     * @param string  $requestId
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function verify(Request $request, string $requestId)
    {
        if (!$request->hasValidSignature() || !RequestScheduler::ready()) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }
        $result = app(GuestRequests::class)->verify($requestId);
        if ($result === null) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }
        if ($result['kind'] === 'erase') {
            $key = $result['status'] === 'rejected' ? 'customer.privacy.guest_rejected' : 'customer.privacy.guest_waiting_approval';

            return $this->page('message', ['message' => gp247_language_render($key)]);
        }
        $until = now()->addHours(self::SIGNED_HOURS);

        return $this->page('package', [
            'packageStatus' => $this->packageStatus($requestId),
            'statusUrl' => URL::temporarySignedRoute('shop.privacy_request_status', $until, ['requestId' => $requestId]),
            'downloadUrl' => URL::temporarySignedRoute('shop.privacy_request_page_download', $until, ['requestId' => $requestId]),
        ]);
    }

    /**
     * Progress of a guest's package; builds one more slice while it is being prepared.
     *
     * @param Request $request
     * @param string  $requestId
     * @return JsonResponse
     */
    public function status(Request $request, string $requestId): JsonResponse
    {
        if (!$request->hasValidSignature() || !ExportService::ready()) {
            return response()->json(['data' => ['status' => null]], 403);
        }
        $package = app(ExportRepository::class)->forRequest($requestId);
        if ($package !== null && (string) $package->status === ExportRepository::BUILDING) {
            app(ExportService::class)->continue((string) $package->id);
        }

        return response()->json(['data' => ['status' => $this->packageStatus($requestId)]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * POST from the page reached by the email link.
     *
     * @param Request $request
     * @param string  $requestId
     * @return Response
     */
    public function download(Request $request, string $requestId): Response
    {
        if (!$request->hasValidSignature() || !ExportService::ready()) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }
        $package = app(ExportRepository::class)->forRequest($requestId);
        $owner = 'guest:' . $requestId;
        $token = $package === null ? null : app(ExportService::class)->issueToken((string) $package->id, $owner);

        return $this->send($package, $token, $owner);
    }

    /**
     * The link of the "your data is ready" email: a page with a download button.
     *
     * @param Request $request
     * @param string  $export
     * @return \Illuminate\Contracts\View\View|Response
     */
    public function downloadLinkPage(Request $request, string $export)
    {
        if (!$request->hasValidSignature() || !ExportService::ready()) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }

        return $this->page('package', ['packageStatus' => $this->packageStatusOf(app(ExportService::class)->find($export)), 'statusUrl' => '', 'downloadUrl' => $request->fullUrl()]);
    }

    /**
     * The button of that page (signed POST): the link is made and used in this request.
     *
     * @param Request $request
     * @param string  $export
     * @return Response
     */
    public function downloadByLink(Request $request, string $export): Response
    {
        if (!$request->hasValidSignature() || !ExportService::ready()) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }
        $package = app(ExportService::class)->find($export);
        $owner = $package === null || $package->request_id === null ? '' : 'guest:' . $package->request_id;
        $token = $owner === '' ? null : app(ExportService::class)->issueToken((string) $package->id, $owner);

        return $this->send($package, $token, $owner);
    }

    /**
     * @return bool Whether the form exists on this site.
     */
    public static function enabled(): bool
    {
        return RequestScheduler::ready() && PrivacySettings::guestFormEnabled() && !empty(gp247_config('email_action_mode'));
    }

    /**
     * @param object|null $package
     * @param string|null $token
     * @param string      $owner
     * @return Response
     */
    private function send(?object $package, ?string $token, string $owner): Response
    {
        $service = app(ExportService::class);
        $path = $package === null || $token === null || $owner === '' ? null : $service->claim((string) $package->id, $token, $owner);
        if ($path === null) {
            return $this->page('message', ['message' => gp247_language_render('customer.privacy.link_invalid')], 403);
        }
        app(PrivacyExports::class)->downloaded($package, ['type' => RequestLog::ACTOR_CUSTOMER, 'id' => null]);
        $response = response()->download($path, 'my-data-' . now()->format('Ymd') . '.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);

        return $service->lastDownload((string) $package->id) ? $response->deleteFileAfterSend() : $response;
    }

    /**
     * @param string $requestId
     * @return string|null
     */
    private function packageStatus(string $requestId): ?string
    {
        return $this->packageStatusOf(app(ExportRepository::class)->forRequest($requestId));
    }

    /**
     * @param object|null $package
     * @return string|null
     */
    private function packageStatusOf(?object $package): ?string
    {
        return $package === null ? null : (string) $package->status;
    }

    /**
     * @return RedirectResponse
     */
    private function sent(): RedirectResponse
    {
        return redirect(gp247_route_front('shop.privacy_request'))->with('privacy_success', gp247_language_render('customer.privacy.guest_sent'));
    }

    /**
     * @param string               $mode    form, message, package or confirm.
     * @param array<string, mixed> $data
     * @param int                  $status
     * @return \Illuminate\Http\Response
     */
    private function page(string $mode, array $data = [], int $status = 200)
    {
        $view = gp247_shop_process_view($this->GP247TemplatePath, 'screen.shop_privacy_request');
        gp247_check_view($view);
        $title = gp247_language_render('customer.privacy.guest_title');

        return response()->view($view, $data + [
            'title' => $title,
            'mode' => $mode,
            'layout_page' => ShopLayoutPage::Profile->value,
            'breadcrumbs' => [['url' => '', 'title' => $title]],
        ], $status)->header('Cache-Control', 'no-store, private');
    }
}
