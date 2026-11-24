<?php

/**
 * The signed-in customer's erasure that has not run yet, for the notice at the top of the
 * account area; null when there is none (or the site is not updated yet).
 *
 * @return array{status: string, runs_at: string|null}|null
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-self-delete
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
if (!function_exists('gp247_shop_privacy_pending_erasure') && !in_array('gp247_shop_privacy_pending_erasure', config('gp247_functions_except', []))) {
    function gp247_shop_privacy_pending_erasure(): ?array
    {
        try {
            $customer = customer()->user();
            if ($customer === null
                || !class_exists(\GP247\Core\PersonalData\Application\Requests\CustomerErasures::class)
                || !\GP247\Core\PersonalData\Application\Requests\RequestScheduler::ready()) {
                return null;
            }
            $pending = app(\GP247\Core\PersonalData\Application\Requests\CustomerErasures::class)->pendingFor((string) $customer->id);
        } catch (\Throwable $e) {
            return null;
        }

        return $pending === null ? null : ['status' => (string) $pending->status, 'runs_at' => $pending->wait_until === null ? null : (string) $pending->wait_until];
    }
}
