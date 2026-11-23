<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Controllers\Auth\RegisterController;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * The storefront forms and API v2 apply one set of customer-account rules: the
 * registration validator of the web form is the service's, and no web or API controller
 * writes customers or passwords around CustomerAccountService.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-api-customer-register, US-CMP-api-customer-profile, US-CMP-api-customer-address
 * @aidlc-adr compat-foundation_api-token-rotation
 */
class ApiAccountWebParityTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;

    public function test_the_registration_form_and_the_api_use_the_same_rules_for_every_store_setting(): void
    {
        $service = app(CustomerAccountService::class);
        $controller = app(RegisterController::class);
        $validator = new \ReflectionMethod($controller, 'validator');
        $data = ['first_name' => 'ZZ', 'email' => 'zz@example.com', 'password' => 'x'];

        foreach ([['customer_phone' => '0', 'customer_address2' => '0'], ['customer_phone' => '1', 'customer_address2' => '1']] as $settings) {
            $this->setStoreConfig($settings);
            $this->assertEquals(
                $this->ruleShape($service->registerValidator($data)->getRules()),
                $this->ruleShape($validator->invoke($controller, $data)->getRules()),
                'Register rules differ for ' . json_encode($settings)
            );
        }
    }

    public function test_no_controller_writes_customers_or_passwords_around_the_service(): void
    {
        $root = dirname((new \ReflectionClass(CustomerAccountService::class))->getFileName(), 2);
        $offenders = [];
        foreach (['Controllers', 'Api'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'Admin' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $code = file_get_contents($file->getPathname());
                if (preg_match('/ShopCustomer::(createCustomer|updateInfo)\(|->password\s*=\s*(bcrypt|Hash::make)/', $code)) {
                    $offenders[] = $file->getFilename();
                }
            }
        }

        $this->assertSame([], $offenders, 'Customer writes must go through CustomerAccountService');
    }

    /**
     * Rules reduced to comparable strings (rule objects by class name).
     *
     * @param array $rules
     * @return array
     */
    private function ruleShape(array $rules): array
    {
        return array_map(fn ($set) => array_map(fn ($r) => is_object($r) ? get_class($r) : (string) $r, (array) $set), $rules);
    }
}
