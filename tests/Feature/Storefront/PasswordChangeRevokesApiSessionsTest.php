<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Shop\Admin\Livewire\CustomerManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Changing the password on the storefront account page, or resetting it through
 * "forgot password", signs the customer out of every app using an API token, while the
 * web session in use stays signed in — and so does an administrator changing the
 * customer's password on the Customers screen, or any code saving a new password
 * through the model.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-password-change-revokes-api-sessions
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class PasswordChangeRevokesApiSessionsTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use InteractsWithAdminUser;

    public function test_changing_the_password_on_the_account_page_revokes_every_api_token(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $v2 = $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true])->json('data');
        $this->flushHeaders();
        $this->actingAs($customer, 'customer');

        $this->post(gp247_route_front('customer.post_change_password'), [
            'password_old' => 'Secret#123', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertRedirect(gp247_route_front('customer.index'));

        $this->assertTrue(Hash::check('NewSecret#456', $customer->fresh()->password));
        $this->assertTrue(Auth::guard('customer')->check(), 'The web session must stay signed in');
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $v2['refresh_token']])->assertStatus(401);
    }

    public function test_a_wrong_old_password_changes_nothing(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $this->actingAs($customer, 'customer');

        $this->post(gp247_route_front('customer.post_change_password'), [
            'password_old' => 'zz-wrong', 'password' => 'NewSecret#456', 'password_confirmation' => 'NewSecret#456',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertOk();
    }

    public function test_an_empty_new_password_is_refused_and_changes_nothing(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $this->actingAs($customer, 'customer');

        $this->post(gp247_route_front('customer.post_change_password'), [
            'password_old' => 'Secret#123', 'password' => '', 'password_confirmation' => '',
        ])->assertRedirect()->assertSessionHasErrors(['password']);

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertOk();
    }

    public function test_resetting_the_password_on_the_storefront_revokes_every_api_token(): void
    {
        $customer = $this->makeCustomer();
        $this->tokenFor($customer, ['user']);
        $controller = app(\GP247\Shop\Controllers\Auth\ResetPasswordController::class);
        $reset = new \ReflectionMethod($controller, 'resetPassword');

        $reset->invoke($controller, $customer, 'NewSecret#456');

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
        $this->assertTrue(Auth::guard('customer')->check());
    }

    public function test_an_administrator_changing_the_customer_password_revokes_every_api_token(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $v2 = $this->apiCall('POST', $this->v2Front() . '/login', null,
            ['email' => $customer->email, 'password' => 'Secret#123', 'with_refresh' => true])->json('data');
        $this->flushHeaders();
        $this->asUser(administrator: true);

        Livewire::test(CustomerManager::class, ['id' => $customer->id])
            ->set('formStoreId', (string) $customer->store_id)
            ->set('form.country', 'VN')
            ->set('form.address1', 'Some street')
            ->set('form.password', 'NewSecret#456')
            ->set('form.password_confirmation', 'NewSecret#456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('NewSecret#456', $customer->fresh()->password));
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertStatus(401);
        $this->apiCall('POST', $this->v2Front() . '/refresh', null, ['refresh_token' => $v2['refresh_token']])->assertStatus(401);
    }

    public function test_an_administrator_editing_other_customer_fields_keeps_the_api_tokens(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);
        $this->asUser(administrator: true);

        Livewire::test(CustomerManager::class, ['id' => $customer->id])
            ->set('formStoreId', (string) $customer->store_id)
            ->set('form.country', 'VN')
            ->set('form.address1', 'Some street')
            ->set('form.last_name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Secret#123', $customer->fresh()->password));
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertOk();
    }

    public function test_a_password_saved_through_the_model_by_other_code_also_revokes_every_api_token(): void
    {
        $customer = $this->makeCustomer();
        $v1 = $this->tokenFor($customer, ['user']);

        $customer->update(['password' => Hash::make('NewSecret#456')]);

        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $customer->id)->count());
        $this->apiCall('GET', $this->frontApi() . '/info', $v1)->assertStatus(401);
    }
}
