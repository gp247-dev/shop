<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The verification link from the mail works in any browser, signed in or not — the
 * customer who registered from an app just clicks it. The signature, the id and the
 * e-mail hash prove the link; a session of another customer is refused and nobody is
 * signed in by the link.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-email-verify-signed-link
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class EmailVerifySignedLinkTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    private function link($customer, ?string $email = null): string
    {
        return URL::temporarySignedRoute('customer.verify_process', now()->addMinutes(60),
            ['id' => $customer->id, 'token' => sha1($email ?? $customer->email)]);
    }

    public function test_the_link_verifies_without_a_web_session_and_signs_nobody_in(): void
    {
        $customer = $this->makeCustomer();

        $this->get($this->link($customer))->assertRedirect(gp247_route_front('customer.login'));

        $this->assertNotNull($customer->fresh()->email_verified_at);
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_the_link_verifies_the_signed_in_customer(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($customer, 'customer');

        $this->get($this->link($customer))->assertRedirect(gp247_route_front('customer.index'));

        $this->assertNotNull($customer->fresh()->email_verified_at);
    }

    public function test_a_link_for_an_old_email_does_not_verify(): void
    {
        $customer = $this->makeCustomer();

        $this->get($this->link($customer, 'zz-old-' . $customer->email));

        $this->assertNull($customer->fresh()->email_verified_at);
    }

    public function test_a_session_of_another_customer_cannot_verify_this_account(): void
    {
        $owner = $this->makeCustomer();
        $this->actingAs($this->makeCustomer(), 'customer');

        $this->get($this->link($owner));

        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_an_unsigned_link_is_refused(): void
    {
        $customer = $this->makeCustomer();

        $this->get(route('customer.verify_process', ['id' => $customer->id, 'token' => sha1($customer->email)]))->assertStatus(401);

        $this->assertNull($customer->fresh()->email_verified_at);
    }
}
