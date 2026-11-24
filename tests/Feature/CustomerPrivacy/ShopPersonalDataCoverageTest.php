<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Core\PersonalData\Application\PersonalDataRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guard: every gp247/shop table with a column that looks like personal data (email, phone,
 * names, address, IP, browser, customer id) is either declared in the personal data registry
 * or listed below with the reason it is not customer data. Adding such a column without a
 * decision makes this test fail.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-declare-shop-data
 * @aidlc-adr personal-data-registry_source-declaration
 */
#[Group('customer-privacy')]
class ShopPersonalDataCoverageTest extends TestCase
{
    /** Column names that suggest personal data. */
    private const PATTERN = '/(^|_)(email|phone|first_name|last_name|address\d*|postcode|ip|ip_address|user_agent|customer_id|address_meta|identity|identifier|tokenable_id|rel_id|party_name|request|message|note|content)$/';

    /** Tables of gp247/shop (logical names). */
    private const PREFIXES = ['shop_', 'payment_'];


    public function test_every_shop_table_with_personal_looking_columns_is_declared_or_explained(): void
    {
        $declared = array_map(fn ($s) => $s->table, PersonalDataRegistry::sources());
        $missing = [];
        foreach ($this->tablesWithPersonalColumns() as $table => $columns) {
            if (!in_array($table, $declared, true) && !array_key_exists($table, \GP247\Core\PersonalData\Application\PersonalDataRegistry::notCustomerTables())) {
                $missing[] = $table . ' (' . implode(', ', $columns) . ')';
            }
        }

        $this->assertSame([], $missing, 'Declare these tables in ShopPersonalData, or explain here why they hold no customer data.');
    }

    public function test_customer_sign_ins_of_the_core_api_are_declared_by_shop(): void
    {
        foreach (['api_session', 'api_refresh_token', 'personal_access_tokens', 'admin_custom_field_detail'] as $table) {
            $this->assertSame('gp247/shop', PersonalDataRegistry::source($table)?->owner(), $table);
        }
    }

    /**
     * @return array<string, list<string>> Logical table => matching columns.
     */
    private function tablesWithPersonalColumns(): array
    {
        $db = DB::connection(GP247_DB_CONNECTION);
        $schema = $db->getSchemaBuilder();
        $out = [];
        foreach ($schema->getTables() as $table) {
            // WHY: on a shared server getTables() lists every database; only this one counts.
            if (isset($table['schema']) && $table['schema'] !== $db->getDatabaseName()) {
                continue;
            }
            $name = (string) $table['name'];
            if (GP247_DB_PREFIX !== '' && !str_starts_with($name, GP247_DB_PREFIX)) {
                continue;
            }
            $logical = substr($name, strlen(GP247_DB_PREFIX));
            $isShop = false;
            foreach (self::PREFIXES as $prefix) {
                $isShop = $isShop || str_starts_with($logical, $prefix);
            }
            if (!$isShop) {
                continue;
            }
            $matches = preg_grep(self::PATTERN, array_column($schema->getColumns($name), 'name'));
            if ($matches !== []) {
                $out[$logical] = array_values($matches);
            }
        }

        return $out;
    }
}
