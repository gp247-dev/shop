<?php

namespace GP247\Shop\Api\V2;

use GP247\Core\Api\Support\ApiErrorResponder;
use GP247\Core\PersonalData\Application\ErasureStepUp;
use GP247\Core\PersonalData\Application\Export\ExportService;
use GP247\Core\PersonalData\Application\Requests\CustomerErasures;
use GP247\Core\PersonalData\Application\Requests\PrivacyExports;
use GP247\Core\PersonalData\Application\Requests\RequestScheduler;
use GP247\Core\PersonalData\Domain\Requests\RequestSource;
use GP247\Core\PersonalData\Infrastructure\Export\ExportRepository;
use GP247\Core\PersonalData\Infrastructure\Requests\RequestLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use GP247\Core\Support\StepUp\StepUpRefused;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's data package through the API (same rules as the web account area): ask with
 * the password (and two-step code) in the body, follow its progress (each call builds one more
 * slice), download it with the customer's own token — a few times at most, within 24 hours.
 * One package per 24 hours; rows found by email only when the account's email is verified.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-api, US-customer-privacy-self-export, US-customer-privacy-self-delete
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class PrivacyController
{
    /**
     * POST member/export — body: password, code (two-step code, when the account uses one).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string|max:200', 'code' => 'nullable|string|max:64']);
        $customer = $request->user();
        if (!ExportService::ready()) {
            return ApiErrorResponder::respond(503, 'export_unavailable', 'Data packages cannot be made on this server');
        }
        app(ExportService::class)->pruneSometimes();
        if ($refused = $this->confirm($customer, $request)) {
            return $refused;
        }

        try {
            $result = app(PrivacyExports::class)->request(
                (string) $customer->id,
                (string) ($customer->store_id ?? GP247_STORE_ID_ROOT),
                RequestSource::Api,
                $this->actor($request),
                $customer->email_verified_at !== null,
            );
        } catch (\RuntimeException $e) {
            return ApiErrorResponder::respond(503, 'export_unavailable', 'Data packages cannot be made on this server');
        }
        if (!$result['reused']) {
            app(ExportService::class)->continue($result['export_id']);
        }

        return response()->json(['data' => $this->state((string) $result['export_id']) + ['reused' => $result['reused']]], $result['reused'] ? 200 : 202);
    }

    /**
     * POST member/delete — body: password, code. The erasure waits the days set for the site
     * (0: it runs now and the token stops working).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteStore(Request $request): JsonResponse
    {
        $request->validate(['password' => 'required|string|max:200', 'code' => 'nullable|string|max:64']);
        $customer = $request->user();
        if (!RequestScheduler::ready()) {
            return ApiErrorResponder::respond(503, 'erasure_unavailable', 'Erasure is not available on this server');
        }
        if ($refused = $this->confirm($customer, $request)) {
            return $refused;
        }
        try {
            $result = app(CustomerErasures::class)->request((string) $customer->id, (string) ($customer->store_id ?? GP247_STORE_ID_ROOT), RequestSource::Api, $this->actor($request));
        } catch (\RuntimeException $e) {
            return ApiErrorResponder::respond(503, 'busy', 'Try again in a moment');
        }

        if ($result['state'] === 'failed') {
            return ApiErrorResponder::respond(409, 'erasure_failed', 'An earlier erasure of this account failed; the shop has been told');
        }

        return response()->json(['data' => $result], $result['state'] === 'erasing' ? 200 : 202);
    }

    /**
     * GET member/delete — the erasure that has not run yet, if any.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteShow(Request $request): JsonResponse
    {
        $pending = RequestScheduler::ready() ? app(CustomerErasures::class)->pendingFor((string) $request->user()->id) : null;

        return response()->json(['data' => $pending === null ? null : [
            'request_id' => (string) $pending->id,
            'status' => (string) $pending->status,
            'runs_at' => $pending->wait_until === null ? null : (string) $pending->wait_until,
        ]]);
    }

    /**
     * DELETE member/delete — call the erasure off.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteCancel(Request $request): JsonResponse
    {
        $customerId = (string) $request->user()->id;
        $pending = RequestScheduler::ready() ? app(CustomerErasures::class)->pendingFor($customerId) : null;
        if ($pending === null || !app(CustomerErasures::class)->cancel((string) $pending->id, $customerId, $this->actor($request))) {
            return ApiErrorResponder::notFound();
        }

        return response()->json(['data' => ['cancelled' => true]]);
    }

    /**
     * GET member/export/{id}
     *
     * @param Request $request
     * @param string  $id
     * @return JsonResponse
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $package = $this->own($request, $id);
        if ($package === null) {
            return ApiErrorResponder::notFound();
        }
        if ((string) $package->status === ExportRepository::BUILDING) {
            app(ExportService::class)->continue($id);
        }

        return response()->json(['data' => $this->state($id)]);
    }

    /**
     * GET member/export/{id}/download — the ZIP, once.
     *
     * @param Request $request
     * @param string  $id
     * @return Response
     */
    public function download(Request $request, string $id): Response
    {
        $package = $this->own($request, $id);
        if ($package === null) {
            return ApiErrorResponder::notFound();
        }
        $owner = 'customer:' . $request->user()->id;
        $service = app(ExportService::class);
        $token = $service->issueToken($id, $owner);
        $path = $token === null ? null : $service->claim($id, $token, $owner);
        if ($path === null) {
            return ApiErrorResponder::respond(410, 'export_gone', 'The package was already downloaded, has expired, or is not ready');
        }
        app(PrivacyExports::class)->downloaded($package, $this->actor($request));
        $response = response()->download($path, 'my-data-' . now()->format('Ymd') . '.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, private',
        ]);

        // WHY only the last time: a download that breaks halfway can be tried again.
        return $service->lastDownload($id) ? $response->deleteFileAfterSend() : $response;
    }

    /**
     * @param object  $customer
     * @param Request $request
     * @return JsonResponse|null The refusal, or null when the password (and code) are right.
     */
    private function confirm(object $customer, Request $request): ?JsonResponse
    {
        try {
            // WHY the same gate as the website: same password check, same two-step code, same lock-out.
            ErasureStepUp::customer(fn () => $customer)->confirm((string) $request->input('password'), (string) $request->input('code', ''));
        } catch (StepUpRefused $e) {
            return match ($e->reason) {
                StepUpRefused::THROTTLED => ApiErrorResponder::respond(429, 'throttled', 'Too many wrong attempts', ['Retry-After' => (string) max(1, $e->retryAfter)]),
                StepUpRefused::WRONG_CODE => ApiErrorResponder::respond(422, 'wrong_code', gp247_language_render('customer.privacy.wrong_code')),
                default => ApiErrorResponder::respond(422, 'wrong_password', gp247_language_render('customer.password_old_notcorrect')),
            };
        }

        return null;
    }

    /**
     * @param Request $request
     * @param string  $id
     * @return object|null The package when it belongs to the signed-in customer.
     */
    private function own(Request $request, string $id): ?object
    {
        if (preg_match('/^[0-9A-Za-z]{26}$/', $id) !== 1 || !ExportService::ready()) {
            return null;
        }
        $package = app(ExportService::class)->find($id);

        return $package !== null && (string) $package->customer_id === (string) $request->user()->id ? $package : null;
    }

    /**
     * @param string $id
     * @return array{export_id: string, status: string, expires_at: string|null, downloads_left: int}
     */
    private function state(string $id): array
    {
        $package = app(ExportService::class)->find($id);

        return [
            'export_id' => $id,
            'status' => (string) ($package->status ?? ExportRepository::FAILED),
            'expires_at' => $package?->expires_at === null ? null : (string) $package->expires_at,
            'downloads_left' => max(0, ExportRepository::MAX_DOWNLOADS - (int) ($package->downloads ?? 0)),
        ];
    }

    /**
     * @param Request $request
     * @return array{type: string, id: string}
     */
    private function actor(Request $request): array
    {
        return ['type' => RequestLog::ACTOR_CUSTOMER, 'id' => (string) $request->user()->id];
    }
}
