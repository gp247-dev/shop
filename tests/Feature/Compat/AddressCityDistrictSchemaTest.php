<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Models\AdminConfig;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use GP247\Shop\Tests\TestCase;

/**
 * Schema + config guarantees for the administrative address fields city /
 * district (US-CMP-address-city-district-schema, QĐ-5/QĐ-7):
 *   - both columns exist on ALL THREE denormalized address blocks
 *     (shop_order, shop_customer, shop_customer_address);
 *   - the upgrade migration is idempotent (its hasColumn guards let up() run
 *     again on an already-migrated DB without a "duplicate column" error);
 *   - the four config toggles seed default-off (QĐ-4);
 *   - gp247_customer_address_mapping honours the customer_*_required toggle
 *     (regression guard for the fix where it forced required-when-shown, so an
 *     optional-but-shown field wrongly failed server-side validation).
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-address-city-district-schema
 * @aidlc-adr shop-admin_address-city-district
 */
class AddressCityDistrictSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The three tables carrying the denormalized customer address block.
     *
     * @var string[]
     */
    private array $addressTables = ['shop_order', 'shop_customer', 'shop_customer_address'];

    /**
     * Path to the idempotent upgrade migration under test.
     *
     * @return string
     */
    private function upgradeMigrationPath(): string
    {
        return base_path(
            'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/'
            . '2026_08_20_140000_add_city_district_to_address_blocks.php'
        );
    }

    /**
     * Upsert a global config key and reset AdminConfig's static caches so
     * gp247_config() re-reads from DB immediately (rolled back by the txn).
     *
     * @param string $key
     * @param string $value
     * @param string $code
     * @return void
     */
    private function setCustomerConfig(string $key, string $value, string $code = 'customer_config_attribute'): void
    {
        $storeId = defined('GP247_STORE_ID_GLOBAL') ? GP247_STORE_ID_GLOBAL : 0;

        AdminConfig::updateOrCreate(
            ['key' => $key, 'store_id' => $storeId],
            ['group' => 'gp247_cart', 'code' => $code, 'value' => $value, 'sort' => 0, 'detail' => '']
        );

        $store = new \ReflectionProperty(AdminConfig::class, 'getAllConfigOfStore');
        $store->setAccessible(true);
        $store->setValue(null, []);

        $global = new \ReflectionProperty(AdminConfig::class, 'getAllGlobal');
        $global->setAccessible(true);
        $global->setValue(null, null);
    }

    public function test_city_district_columns_exist_on_all_address_tables(): void
    {
        foreach ($this->addressTables as $table) {
            $full = GP247_DB_PREFIX . $table;
            $this->assertTrue(Schema::hasColumn($full, 'city'), "{$full}.city must exist (QĐ-5)");
            $this->assertTrue(Schema::hasColumn($full, 'district'), "{$full}.district must exist (QĐ-5)");
        }
    }

    public function test_upgrade_migration_is_idempotent(): void
    {
        // The DB already has the columns (create-tables ships them on a fresh
        // install). Running the upgrade's up() must therefore be a no-op guarded
        // by hasColumn — WITHOUT the guard it would throw "duplicate column".
        $migration = require $this->upgradeMigrationPath();

        $migration->up();
        $migration->up();

        foreach ($this->addressTables as $table) {
            $full = GP247_DB_PREFIX . $table;
            $this->assertTrue(Schema::hasColumn($full, 'city'));
            $this->assertTrue(Schema::hasColumn($full, 'district'));
        }
    }

    public function test_config_toggles_default_off(): void
    {
        foreach (['customer_city', 'customer_city_required', 'customer_district', 'customer_district_required'] as $key) {
            $row = AdminConfig::where('key', $key)->first();
            $this->assertNotNull($row, "config {$key} must be seeded (QĐ-4)");
            $this->assertSame('0', (string) $row->value, "config {$key} must default off (QĐ-4)");
        }
    }

    public function test_address_mapping_omits_city_district_when_disabled(): void
    {
        $this->setCustomerConfig('customer_city', '0');
        $this->setCustomerConfig('customer_district', '0');

        $result = gp247_customer_address_mapping(['first_name' => 'A', 'address1' => 'B', 'city' => 'X', 'district' => 'Y']);

        $this->assertArrayNotHasKey('city', $result['validate']);
        $this->assertArrayNotHasKey('district', $result['validate']);
        $this->assertArrayNotHasKey('city', $result['dataAddress']);
        $this->assertArrayNotHasKey('district', $result['dataAddress']);
    }

    public function test_address_mapping_optional_when_shown_but_not_required(): void
    {
        // Regression guard: shown + not required must map to a nullable rule, not
        // a forced "required" (which rejected a legitimately blank optional field).
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_city_required', '0', 'customer_config_attribute_required');
        $this->setCustomerConfig('customer_district', '1');
        $this->setCustomerConfig('customer_district_required', '0', 'customer_config_attribute_required');

        $result = gp247_customer_address_mapping(['first_name' => 'A', 'address1' => 'B']);

        $this->assertStringContainsString('nullable', $result['validate']['city']);
        $this->assertStringNotContainsString('required', $result['validate']['city']);
        $this->assertStringContainsString('nullable', $result['validate']['district']);
        $this->assertStringNotContainsString('required', $result['validate']['district']);
    }

    public function test_address_mapping_required_when_required_toggle_on(): void
    {
        $this->setCustomerConfig('customer_city', '1');
        $this->setCustomerConfig('customer_city_required', '1', 'customer_config_attribute_required');
        $this->setCustomerConfig('customer_district', '1');
        $this->setCustomerConfig('customer_district_required', '1', 'customer_config_attribute_required');

        $result = gp247_customer_address_mapping(['first_name' => 'A', 'address1' => 'B', 'city' => 'Ha Noi', 'district' => 'Cau Giay']);

        $this->assertStringContainsString('required', $result['validate']['city']);
        $this->assertStringContainsString('required', $result['validate']['district']);
        $this->assertSame('Ha Noi', $result['dataAddress']['city']);
        $this->assertSame('Cau Giay', $result['dataAddress']['district']);
    }
}
