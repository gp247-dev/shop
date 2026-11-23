<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Api\Support\ApiSwitch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A package registers its own API switch with the core (key, route prefix, parent group,
 * default, refusal code): the site owner sees it with the other API switches, it is off
 * when the site never chose (if its default says so), it only matters while its parent
 * group is on, and a closed route answers with the package's own status and code.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-api-commerce-checkout-switch
 * @aidlc-adr api-commerce_switch-registry
 */
class ApiSwitchRegistryTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_the_order_through_api_switch_is_registered_by_the_shop_package(): void
    {
        $this->assertContains('api_v2_front_checkout', ApiSwitch::keys());
        $this->assertSame('api_v2_front', ApiSwitch::registered()['api_v2_front_checkout']['parent']);
    }

    public function test_a_registered_switch_without_a_saved_value_follows_its_default(): void
    {
        \DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'admin_config')
            ->where('key', 'api_v2_front_checkout')->where('store_id', GP247_STORE_ID_GLOBAL)->delete();
        $this->forgetConfigMemo();

        $this->assertFalse(ApiSwitch::states()['api_v2_front_checkout'], 'ordering through the API is off unless the owner opens it');
    }

    public function test_a_closed_registered_switch_answers_with_its_own_status_and_code(): void
    {
        $this->setApiSwitch('api_v2_front_checkout', false);

        $refusal = ApiSwitch::refusal('api/v2/front/checkout/place');

        $this->assertSame(403, $refusal['status']);
        $this->assertSame('api_checkout_disabled', $refusal['code']);
        $this->assertNull(ApiSwitch::refusal('api/v2/front/checkout/totals'), 'the other routes of the group stay open');
    }

    public function test_a_registered_switch_only_opens_while_its_parent_group_is_open(): void
    {
        $this->setApiSwitch('api_v2_front_checkout', true);
        $this->setApiSwitch('api_v2_front', false);

        $this->assertSame('api_disabled', ApiSwitch::refusal('api/v2/front/checkout/place')['code']);
        $this->assertFalse(ApiSwitch::allows('api/v2/front/checkout/place'));
    }

    public function test_an_open_registered_switch_lets_the_route_through(): void
    {
        $this->setApiSwitch('api_v2_front_checkout', true);

        $this->assertNull(ApiSwitch::refusal('api/v2/front/checkout/place'));
    }
}
