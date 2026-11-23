<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use GP247\Shop\Models\ShopCustomer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\ShopEventFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * A CRM locks a customer through the admin API (and unlocks them): the lock works at once
 * on the customer's API sessions, only the status can be written, in the account's store,
 * with the Customers screen's change permission — and the change leaves a thin event.
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-customer-status
 * @aidlc-adr api-admin-write_write-authorization-as-admin-action
 */
#[Group('api-admin-write')]
class ApiCustomerStatusWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;
    use ShopEventFixtures;

    public function test_a_crm_locks_a_customer_whose_api_token_is_refused_at_once_and_unlocks_them_again(): void
    {
        $customer = $this->makeCustomer();
        $customerToken = $this->tokenFor($customer, ['user']);
        $this->profile($customerToken)->assertOk();

        $this->setStatus($this->writer(), $customer, ['status' => 0])->assertOk()
            ->assertJsonPath('data.id', $customer->id)->assertJsonPath('data.status', 0);
        $this->assertSame(0, (int) ShopCustomer::find($customer->id)->status);
        $this->profile($customerToken)->assertStatus(401);

        $this->setStatus($this->writer(), $customer, ['status' => 1])->assertOk();
        $this->profile($customerToken)->assertOk();
    }

    public function test_another_field_or_an_empty_body_is_refused(): void
    {
        $customer = $this->makeCustomer();

        $this->setStatus($this->writer(), $customer, ['status' => 0, 'email' => 'hijack@example.com'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->setStatus($this->writer(), $customer, ['status' => 2])->assertStatus(422);
        $this->setStatus($this->writer(), $customer, [])->assertStatus(422);

        $fresh = ShopCustomer::find($customer->id);
        $this->assertSame(1, (int) $fresh->status);
        $this->assertSame($customer->email, $fresh->email);
    }

    public function test_a_customer_of_another_store_is_not_found_and_view_only_accounts_are_refused(): void
    {
        $customer = $this->makeCustomer();
        $readOnly = $this->tokenFor($this->makeAdmin(['admin_customer.index']), ['admin']);
        $this->setStatus($readOnly, $customer, ['status' => 0])->assertStatus(403);

        $storeId = $this->otherStoreId();
        $this->pinAdminStoreContext(fn () => $storeId, fn () => true);
        $this->setStatus($this->tokenFor($this->adminWithUris(['ANY::' . $this->customerScreen() . '/*']), ['admin']), $customer, ['status' => 0])
            ->assertStatus(404);
        $this->assertSame(1, (int) ShopCustomer::find($customer->id)->status);
    }

    public function test_locking_records_a_customer_updated_event_with_the_field_names_and_the_status_only(): void
    {
        $customer = $this->makeCustomer();
        $mark = $this->startEventLog();

        $this->setStatus($this->writer(), $customer, ['status' => 0])->assertOk();

        $events = $this->eventsAfter($mark, 'customer.updated', (string) $customer->id);
        $this->assertCount(1, $events);
        $this->assertSame(['fields' => ['status'], 'status' => 0], $events[0]->data);
        $this->assertSame('api', $events[0]->source);
        $this->assertStringNotContainsString((string) $customer->email, json_encode($events[0]));
    }

    /**
     * @param string       $token
     * @param ShopCustomer $customer
     * @param array        $body
     * @return \Illuminate\Testing\TestResponse
     */
    private function setStatus(string $token, ShopCustomer $customer, array $body)
    {
        return $this->apiCall('PATCH', $this->v2Core() . '/customer/' . $customer->id, $token, $body);
    }

    /**
     * @param string $customerToken
     * @return \Illuminate\Testing\TestResponse
     */
    private function profile(string $customerToken)
    {
        return $this->apiCall('GET', $this->v2Front() . '/member/profile', $customerToken);
    }

    /**
     * @return string
     */
    private function writer(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
