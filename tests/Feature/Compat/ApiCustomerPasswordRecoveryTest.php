<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A customer who forgot the password asks for the reset mail and sets a new password
 * through API v2. The request answers the same whether the e-mail has an account or
 * not, a reset signs every API client out, a wrong token is refused, and a locked
 * customer can reset but still cannot sign in.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-password-recovery
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiCustomerPasswordRecoveryTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_forgot_password_answers_the_same_for_known_and_unknown_emails(): void
    {
        $customer = $this->makeCustomer();

        $known = $this->apiCall('POST', $this->v2Front() . '/password/forgot', null, ['email' => $customer->email]);
        $unknown = $this->apiCall('POST', $this->v2Front() . '/password/forgot', null, ['email' => 'zz-nobody-' . uniqid() . '@example.com']);

        $known->assertOk();
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame(['data' => ['sent' => true]], $known->json());
    }

    public function test_resetting_with_the_mailed_token_sets_the_password_and_signs_every_client_out(): void
    {
        $customer = $this->makeCustomer();
        $v1Token = $this->tokenFor($customer, ['user']);
        $resetToken = Password::broker('customer_password')->createToken($customer);

        $this->apiCall('POST', $this->v2Front() . '/password/reset', null, [
            'email' => $customer->email, 'token' => $resetToken,
            'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertOk()->assertJson(['data' => ['reset' => true]]);

        $this->assertTrue(Hash::check('NewSecret#456', $customer->fresh()->password));
        $this->apiCall('GET', $this->frontApi() . '/info', $v1Token)->assertStatus(401);
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
    }

    public function test_a_wrong_reset_token_is_refused(): void
    {
        $customer = $this->makeCustomer();

        $this->apiCall('POST', $this->v2Front() . '/password/reset', null, [
            'email' => $customer->email, 'token' => 'zz-wrong',
            'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
    }

    public function test_reset_answers_the_same_for_an_unknown_email_and_a_wrong_token(): void
    {
        $customer = $this->makeCustomer();
        $body = ['token' => 'zz-wrong', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456'];

        $known = $this->apiCall('POST', $this->v2Front() . '/password/reset', null, $body + ['email' => $customer->email]);
        $unknown = $this->apiCall('POST', $this->v2Front() . '/password/reset', null, $body + ['email' => 'zz-nobody-' . uniqid() . '@example.com']);

        $known->assertStatus(422);
        $this->assertSame($known->json(), $unknown->json());
    }

    public function test_a_locked_customer_can_reset_but_still_cannot_sign_in(): void
    {
        $customer = $this->makeCustomer(0);
        $resetToken = Password::broker('customer_password')->createToken($customer);

        $response = $this->apiCall('POST', $this->v2Front() . '/password/reset', null, [
            'email' => $customer->email, 'token' => $resetToken,
            'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertOk();

        $this->assertArrayNotHasKey('access_token', $response->json('data'));
        $this->apiCall('POST', $this->v2Front() . '/login', null, ['email' => $customer->email, 'password' => 'NewSecret#456'])
            ->assertStatus(401);
    }
}
