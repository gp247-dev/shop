<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A locked customer (status = 0) cannot sign in on the storefront nor through the API,
 * a token or web session issued before the lock stops working at the next request,
 * and active customers are unaffected.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-customer-locked-login-block, US-CMP-api-refresh-token-rotation
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class CustomerLockedLoginTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_locked_customer_cannot_sign_in_through_the_api(): void
    {
        $customer = $this->makeCustomer(0);

        $this->postJson($this->frontApi() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertStatus(401)
            ->assertJson(['error' => 1]);
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
    }

    public function test_active_customer_api_login_gets_the_user_scope_only(): void
    {
        $customer = $this->makeCustomer(1);

        $this->postJson($this->frontApi() . '/login', ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertOk()
            ->assertJson(['scopes' => ['user']]);
    }

    public function test_token_of_a_customer_locked_afterwards_is_refused(): void
    {
        $customer = $this->makeCustomer(1);
        $token = $this->tokenFor($customer, ['user']);
        $customer->update(['status' => 0]);

        $this->getWithToken($this->frontApi() . '/member/order/list', $token)->assertStatus(401);
        $this->getWithToken($this->frontApi() . '/info', $token)->assertStatus(401);
    }

    public function test_legacy_guest_scope_token_no_longer_opens_member_routes(): void
    {
        $customer = $this->makeCustomer(1);

        $this->getWithToken($this->frontApi() . '/member/order/list', $this->tokenFor($customer, ['user-guest']))
            ->assertStatus(403);
    }

    public function test_locked_customer_cannot_sign_in_on_the_storefront(): void
    {
        $customer = $this->makeCustomer(0);

        $this->post(gp247_route_front('customer.postLogin'), ['email' => $customer->email, 'password' => 'Secret#123'])
            ->assertSessionHasErrors('email');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_active_customer_still_signs_in_on_the_storefront(): void
    {
        $customer = $this->makeCustomer(1);

        $this->post(gp247_route_front('customer.postLogin'), ['email' => $customer->email, 'password' => 'Secret#123']);

        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_web_session_of_a_customer_locked_afterwards_is_closed_at_the_next_request(): void
    {
        $customer = $this->makeCustomer(1);
        $this->actingAs($customer, 'customer');
        $customer->update(['status' => 0]);

        $this->get(gp247_route_front('customer.index'))->assertRedirect();
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_locked_customer_is_signed_out_on_any_storefront_page(): void
    {
        $customer = $this->makeCustomer(1);
        $this->actingAs($customer, 'customer');
        $customer->update(['status' => 0]);

        $this->get(gp247_route_front('front.home'));

        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_password_reset_does_not_sign_in_a_locked_customer(): void
    {
        $customer = $this->makeCustomer(0);
        $controller = app(\GP247\Shop\Controllers\Auth\ResetPasswordController::class);
        $reset = new \ReflectionMethod($controller, 'resetPassword');

        $reset->invoke($controller, $customer, 'New#Secret456');

        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('New#Secret456', $customer->fresh()->password));
    }

    public function test_password_reset_still_signs_in_an_active_customer(): void
    {
        $customer = $this->makeCustomer(1);
        $controller = app(\GP247\Shop\Controllers\Auth\ResetPasswordController::class);
        $reset = new \ReflectionMethod($controller, 'resetPassword');

        $reset->invoke($controller, $customer, 'New#Secret456');

        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_a_customer_locked_after_sign_in_cannot_refresh_and_the_refresh_token_is_not_spent(): void
    {
        $customer = $this->makeCustomer(1);
        $data = $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true])->assertOk()->json('data');
        $customer->update(['status' => 0]);

        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $data['refresh_token']])
            ->assertStatus(401)->assertJson(['code' => 'unauthenticated']);

        $this->assertNull(\GP247\Core\Models\AdminApiRefreshToken::where('session_id', $data['session_id'])->value('used_at'));
    }
}
