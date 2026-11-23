<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Models\ShopCustomer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\PersonalAccessToken;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A shopper creates an account from an app through API v2 with the same rules as the
 * storefront form (store settings, e-mail uniqueness, captcha when the site turned it on
 * for registration) and is signed in at once; a customer cannot set their own status,
 * and the endpoint is rate limited per IP.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-register
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiCustomerRegisterTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useMinimalCustomerForm();
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'first_name' => 'ZZ Reg', 'email' => 'zzreg' . uniqid() . '@example.com', 'address1' => '1 ZZ street',
            'password' => 'Secret#123', 'password_confirmation' => 'Secret#123',
        ], $override);
    }

    public function test_registering_creates_an_active_customer_and_signs_them_in(): void
    {
        $payload = $this->payload(['with_refresh' => true]);

        $data = $this->apiCall('POST', $this->v2Front() . '/register', null, $payload)->assertStatus(201)->json('data');

        $customer = ShopCustomer::where('email', $payload['email'])->first();
        $this->assertNotNull($customer);
        $this->assertSame(1, (int) $customer->status);
        $this->assertSame($payload['email'], $data['customer']['email']);
        $this->assertArrayNotHasKey('password', $data['customer']);
        $this->assertNotEmpty($data['refresh_token']);
        $this->apiCall('GET', $this->v2Front() . '/member/profile', $data['access_token'])->assertOk();
    }

    public function test_missing_fields_are_rejected_with_the_storefront_rules(): void
    {
        $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload(['first_name' => '', 'address1' => '']))
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['first_name', 'address1']);
    }

    public function test_a_body_without_the_password_is_rejected_field_by_field_not_crashed(): void
    {
        $payload = $this->payload();
        unset($payload['password'], $payload['password_confirmation']);

        $this->apiCall('POST', $this->v2Front() . '/register', null, $payload)
            ->assertStatus(422)->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['password']);
        $this->assertFalse(ShopCustomer::where('email', $payload['email'])->exists(), 'no account without a password');
    }

    public function test_an_empty_password_is_rejected_and_no_account_is_made(): void
    {
        $payload = $this->payload(['password' => '', 'password_confirmation' => '']);

        $this->apiCall('POST', $this->v2Front() . '/register', null, $payload)
            ->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertFalse(ShopCustomer::where('email', $payload['email'])->exists());
    }

    public function test_an_email_already_registered_is_rejected(): void
    {
        $existing = $this->makeCustomer();

        $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload(['email' => $existing->email]))
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_a_customer_cannot_choose_their_own_status(): void
    {
        $payload = $this->payload(['status' => 0]);

        $this->apiCall('POST', $this->v2Front() . '/register', null, $payload)->assertStatus(201);

        $this->assertSame(1, (int) ShopCustomer::where('email', $payload['email'])->value('status'));
    }

    public function test_captcha_enabled_for_registration_is_required_through_the_api_too(): void
    {
        $this->setStoreConfig([
            'captcha_mode' => '1', 'captcha_method' => ZzFakeCaptcha::class, 'captcha_page' => '["register"]',
            ZzFakeCaptcha::KEY => '1',
        ], (string) GP247_STORE_ID_ROOT);

        $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload())
            ->assertStatus(422)->assertJsonValidationErrors(['captcha_field']);
        $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload(['zz_captcha' => 'pass']))
            ->assertStatus(201);
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload(['first_name' => '']))->assertStatus(422);
        }

        $this->apiCall('POST', $this->v2Front() . '/register', null, $this->payload())
            ->assertStatus(429)->assertJson(['code' => 'too_many_attempts']);
    }

    public function test_v1_has_no_register_endpoint(): void
    {
        $this->apiCall('POST', $this->frontApi() . '/register', null, $this->payload())->assertStatus(404);
        $this->assertSame(0, PersonalAccessToken::where('name', 'like', 'Client:zzreg%')->count());
    }
}

/**
 * Captcha plugin double: field `zz_captcha`, accepts the value "pass".
 */
class ZzFakeCaptcha
{
    public const KEY = 'ZzFakeCaptcha';

    public $configKey = self::KEY;

    public function getField()
    {
        return 'zz_captcha';
    }

    public function validate($value)
    {
        return $value === 'pass';
    }

    public function msgError()
    {
        return 'zz captcha invalid';
    }
}
