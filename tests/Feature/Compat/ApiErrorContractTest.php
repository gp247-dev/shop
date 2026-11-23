<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * Every REST API error has a meaningful HTTP status and one body shape
 * {"error":1,"msg":…,"code":…} (+ "errors" on validation); "not found" is a 404 on
 * every detail endpoint instead of four different answers; successful v1 bodies keep
 * their shape.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-error-contract
 * @aidlc-adr compat-foundation_rest-api-hardening
 */
class ApiErrorContractTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_unknown_public_records_answer_404_not_found(): void
    {
        foreach (['product', 'category', 'brand', 'banner', 'page'] as $resource) {
            $this->getJson($this->frontApi() . '/' . $resource . '/detail/ZZ-NOT-THERE')
                ->assertStatus(404)
                ->assertJson(['error' => 1, 'code' => 'not_found']);
        }
    }

    public function test_unknown_admin_records_answer_404_not_found(): void
    {
        $this->pinAdminStoreContext(null, null);
        $token = $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin-supper']);

        foreach (['customer', 'order', 'product', 'category', 'brand', 'supplier', 'banner', 'page'] as $resource) {
            $this->getWithToken($this->coreApi() . '/' . $resource . '/detail/ZZ-NOT-THERE', $token)
                ->assertStatus(404)
                ->assertJson(['error' => 1, 'code' => 'not_found']);
        }
    }

    public function test_another_customers_order_answers_404(): void
    {
        $owner = $this->makeCustomer();
        $order = $this->makeOrder(['customer_id' => $owner->id]);

        $this->getWithToken($this->frontApi() . '/member/order/detail/' . $order->id, $this->tokenFor($this->makeCustomer(), ['user']))
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);
    }

    public function test_missing_token_answers_401_unauthenticated(): void
    {
        $this->getJson($this->coreApi() . '/order/list')
            ->assertStatus(401)
            ->assertJson(['error' => 1, 'code' => 'unauthenticated']);
    }

    public function test_missing_token_answers_401_json_even_without_a_json_accept_header(): void
    {
        foreach ([$this->coreApi() . '/order/list', $this->frontApi() . '/member/order/list',
            $this->v2Core() . '/order/list', $this->v2Front() . '/member/profile'] as $uri) {
            foreach (['*/*', 'text/html'] as $accept) {
                $this->app['auth']->forgetGuards();
                $this->flushHeaders();
                $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => $accept])
                    ->assertStatus(401)
                    ->assertJson(['error' => 1, 'code' => 'unauthenticated']);
            }
        }
    }

    public function test_token_without_the_required_ability_answers_403_forbidden(): void
    {
        $this->getWithToken($this->frontApi() . '/member/order/list', $this->tokenFor($this->makeCustomer(), ['user-guest']))
            ->assertStatus(403)
            ->assertJson(['error' => 1, 'code' => 'forbidden']);
    }

    public function test_invalid_input_answers_422_with_field_errors(): void
    {
        $this->postJson($this->coreApi() . '/login', [])
            ->assertStatus(422)
            ->assertJson(['error' => 1, 'code' => 'validation_failed'])
            ->assertJsonStructure(['msg', 'errors' => ['username', 'password']]);
    }

    public function test_unknown_api_path_answers_404_json(): void
    {
        $this->get($this->frontApi() . '/zz-no-such-endpoint')
            ->assertStatus(404)
            ->assertJson(['error' => 1, 'code' => 'not_found']);
    }

    public function test_successful_v1_detail_body_keeps_its_shape(): void
    {
        $product = $this->makeProduct();

        $this->getJson($this->frontApi() . '/product/detail/' . $product->id)
            ->assertOk()
            ->assertJson(['id' => $product->id])
            ->assertJsonMissing(['error' => 1]);
    }

    public function test_a_response_built_by_the_endpoint_itself_is_kept(): void
    {
        \Illuminate\Support\Facades\Route::middleware(GP247_API_MIDDLEWARE)
            ->get(GP247_API_FRONT_PREFIX . '/zz-custom-response', static function () {
                throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json(['custom' => true], 418));
            });

        $this->getJson($this->frontApi() . '/zz-custom-response')->assertStatus(418)->assertExactJson(['custom' => true]);
    }

    public function test_api_routes_of_the_host_application_keep_their_own_error_format(): void
    {
        \Illuminate\Support\Facades\Route::middleware('api')->get('api/zz-host-route', static fn () => abort(404, 'Host 404'));

        $this->getJson('/api/zz-host-route')->assertStatus(404)->assertJsonMissing(['code' => 'not_found']);
    }

    public function test_abort_codes_map_to_their_dedicated_error_codes(): void
    {
        \Illuminate\Support\Facades\Route::middleware(GP247_API_MIDDLEWARE)
            ->get(GP247_API_FRONT_PREFIX . '/zz-abort-403', static fn () => abort(403));

        $this->getJson($this->frontApi() . '/zz-abort-403')->assertStatus(403)->assertJson(['code' => 'forbidden']);
    }

    /**
     * A group turned off on the API management screen answers in the same error body
     * with its own code (US-CMP-api-admin-switch).
     */
    public function test_a_disabled_api_group_answers_404_api_disabled(): void
    {
        $this->setApiSwitch('api_v1_front', false);

        $this->getJson($this->frontApi() . '/product/list')
            ->assertStatus(404)
            ->assertExactJson(['error' => 1, 'msg' => 'This API is disabled on this site', 'code' => 'api_disabled']);
    }

    /**
     * Every GP247 API response carries an X-Request-Id: a valid id sent by the client is
     * echoed, a missing or malformed one is replaced by a fresh UUID.
     */
    public function test_every_response_carries_a_request_id(): void
    {
        $fresh = $this->getJson($this->frontApi() . '/product/list')->assertOk()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string) $fresh);

        $this->withHeaders(['X-Request-Id' => 'app-7f3a9c21.retry_2'])->getJson($this->frontApi() . '/product/list')
            ->assertHeader('X-Request-Id', 'app-7f3a9c21.retry_2');

        foreach (['short', str_repeat('a', 129), 'bad id with spaces', "x\r\nInjected: 1"] as $bad) {
            $id = $this->withHeaders(['X-Request-Id' => $bad])->getJson($this->frontApi() . '/product/list')->headers->get('X-Request-Id');
            $this->assertNotSame($bad, $id);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $id);
        }
    }

    /**
     * Error answers carry the id too, including a group turned off on the API screen.
     */
    public function test_error_answers_carry_a_request_id(): void
    {
        $this->getJson($this->coreApi() . '/order/list')->assertStatus(401)->assertHeader('X-Request-Id');
        $this->getJson($this->frontApi() . '/product/detail/ZZ-NOT-THERE')->assertStatus(404)->assertHeader('X-Request-Id');

        $this->setApiSwitch('api_v1_front', false);
        $this->getJson($this->frontApi() . '/product/list')->assertStatus(404)->assertHeader('X-Request-Id');
    }

    /**
     * The [site] line of gp247_report names the request id, so a report can be matched
     * to the id the client quotes.
     */
    public function test_the_error_report_context_names_the_request_id(): void
    {
        request()->attributes->set('gp247_request_id', 'app-7f3a9c21.retry_2');

        $this->assertStringContainsString('request_id=app-7f3a9c21.retry_2', gp247_report_context());

        request()->attributes->remove('gp247_request_id');
        $this->assertStringNotContainsString('request_id=', gp247_report_context());
    }

    public function test_session_errors_use_the_shared_error_body(): void
    {
        $this->apiCall('POST', '/api/v2/front/refresh', null, ['refresh_token' => 'zz-unknown'])
            ->assertStatus(401)->assertExactJson(['error' => 1, 'msg' => 'Invalid or expired refresh token', 'code' => 'refresh_token_invalid']);
        $this->apiCall('POST', '/api/v2/core/login/challenge', null, ['ticket' => 'zz-unknown'])
            ->assertStatus(401)->assertJson(['error' => 1, 'code' => 'challenge_invalid']);
    }
}
