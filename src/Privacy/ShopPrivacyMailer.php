<?php

namespace GP247\Shop\Privacy;

use GP247\Core\PersonalData\Domain\Requests\PrivacyMailer;
use Illuminate\Support\Facades\URL;

/**
 * The emails of personal data requests, written by the shop: one email view
 * (email/shop_privacy_notice) whose words are labels the owner edits on the Languages screen,
 * links built on the configured domain (never the Host of the request). Customer messages hold
 * nothing the recipient does not already know; messages to the shop hold a request id and a
 * link to the screen only.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-customer-emails, US-personal-data-requests-admin-notify
 * @aidlc-adr customer-privacy_self-service-surfaces
 */
class ShopPrivacyMailer implements PrivacyMailer
{
    /** Hours a guest download link from an email stays usable (the package lives 24 hours). */
    private const LINK_HOURS = 24;

    /** @inheritDoc */
    public function erasureScheduled(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
        // WHY until the request may close: an erasure held by an open order runs later than $runsAt,
        // and a shopper without an account has no other way to call it off.
        $until = \Illuminate\Support\Carbon::instance($runsAt)->addDays(\GP247\Core\PersonalData\Application\Requests\PrivacySettings::overdueDays());
        $url = gp247_mail_link_url(fn () => URL::temporarySignedRoute('customer.privacy_cancel_link', $until, ['requestId' => $requestId]));
        $this->send($email, 'scheduled', ['date' => $runsAt->format('Y-m-d H:i')], $url);
    }

    /** @inheritDoc */
    public function inactiveAccount(string $email, string $requestId, \DateTimeInterface $runsAt): void
    {
        // WHY the same signed link as a scheduled erasure: it calls off this request, like signing in does.
        $until = \Illuminate\Support\Carbon::instance($runsAt)->addDays(\GP247\Core\PersonalData\Application\Requests\PrivacySettings::overdueDays());
        $url = gp247_mail_link_url(fn () => URL::temporarySignedRoute('customer.privacy_cancel_link', $until, ['requestId' => $requestId]));
        $this->send($email, 'inactive', ['date' => $runsAt->format('Y-m-d H:i')], $url);
    }

    /** @inheritDoc */
    public function erased(string $email): void
    {
        $this->send($email, 'erased');
    }

    /** @inheritDoc */
    public function rejected(string $email, string $reason): void
    {
        $this->send($email, 'rejected', ['reason' => $reason]);
    }

    /** @inheritDoc */
    public function verifyGuest(string $email, string $requestId, string $kind): void
    {
        $url = gp247_mail_link_url(fn () => URL::temporarySignedRoute('shop.privacy_request_verify', now()->addHours(24), ['requestId' => $requestId]));
        $this->send($email, $kind === 'erase' ? 'verify_erase' : 'verify_export', [], $url);
    }

    /** @inheritDoc */
    public function signInInstead(string $email): void
    {
        $this->send($email, 'sign_in', [], gp247_mail_link_url(fn () => gp247_route_front('customer.privacy')));
    }

    /** @inheritDoc */
    public function exportReady(string $email, string $requestId, string $exportId, bool $guest): void
    {
        // WHY a signed page and no token: the page asks for a click (mail scanners open links) and
        // the download is made in that POST, so nothing else can use it up.
        $url = $guest
            ? gp247_mail_link_url(fn () => URL::temporarySignedRoute('shop.privacy_request_download', now()->addHours(self::LINK_HOURS), ['export' => $exportId]))
            : gp247_mail_link_url(fn () => gp247_route_front('customer.privacy'));
        $this->send($email, 'export_ready', [], $url);
    }

    /** @inheritDoc */
    public function adminApprovalNeeded(string $requestId): void
    {
        $this->sendToShop('admin_approval', ['id' => $requestId]);
    }

    /** @inheritDoc */
    public function adminDueSoon(string $requestId, \DateTimeInterface $dueAt): void
    {
        $this->sendToShop('admin_due', ['id' => $requestId, 'date' => $dueAt->format('Y-m-d')]);
    }

    /**
     * @param string               $type
     * @param array<string, mixed> $replace
     * @return void
     */
    private function sendToShop(string $type, array $replace): void
    {
        $to = (string) gp247_store_info('email');
        if ($to === '') {
            return;
        }
        $url = gp247_mail_link_url(fn () => gp247_route_admin('admin.personal_data_requests'));
        $this->send($to, $type, $replace, $url);
    }

    /**
     * @param string               $to
     * @param string               $type   Label group under email.privacy.
     * @param array<string, mixed> $replace
     * @param string|null          $url    Button link.
     * @return void
     */
    private function send(string $to, string $type, array $replace = [], ?string $url = null): void
    {
        $key = 'email.privacy.' . $type . '.';
        gp247_mail_send(gp247_shop_mail_view('email.shop_privacy_notice'), [
            'title' => gp247_language_render($key . 'title', $replace),
            'body' => gp247_language_render($key . 'body', $replace),
            'button' => $url === null ? null : gp247_language_render($key . 'button', $replace),
            'url' => $url,
        ], [
            'to' => $to,
            'subject' => gp247_language_render($key . 'subject', $replace),
        ]);
    }
}
