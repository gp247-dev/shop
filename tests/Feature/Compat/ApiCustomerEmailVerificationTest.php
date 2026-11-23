<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An app shows whether the customer's e-mail still needs verifying and asks for the
 * verification mail again; nothing is sent when the store does not require
 * verification or the e-mail is already verified.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-email-verification
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiCustomerEmailVerificationTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_nothing_is_sent_when_the_store_does_not_require_verification(): void
    {
        $this->setStoreConfig(['customer_verify' => '0']);
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->apiCall('POST', $this->v2Front() . '/member/email/verify', $token)->assertOk()->assertJson(['data' => ['sent' => false]]);
    }

    public function test_an_unverified_customer_gets_the_mail_when_the_store_requires_it(): void
    {
        $this->setStoreConfig(['customer_verify' => '1']);
        $token = $this->tokenFor($this->makeCustomer(), ['user']);

        $this->apiCall('GET', $this->v2Front() . '/member/profile', $token)
            ->assertOk()->assertJson(['data' => ['email_verified' => false, 'email_verification_required' => true]]);
        $this->apiCall('POST', $this->v2Front() . '/member/email/verify', $token)->assertOk()->assertJson(['data' => ['sent' => true]]);
    }

    public function test_an_already_verified_customer_gets_no_mail(): void
    {
        $this->setStoreConfig(['customer_verify' => '1']);
        $customer = $this->makeCustomer();
        $customer->forceFill(['email_verified_at' => now()])->save();
        $token = $this->tokenFor($customer, ['user']);

        $this->apiCall('GET', $this->v2Front() . '/member/profile', $token)
            ->assertJson(['data' => ['email_verified' => true, 'email_verification_required' => false]]);
        $this->apiCall('POST', $this->v2Front() . '/member/email/verify', $token)->assertJson(['data' => ['sent' => false]]);
    }
}
