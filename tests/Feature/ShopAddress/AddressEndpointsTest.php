<?php

namespace GP247\Shop\Tests\Feature\ShopAddress;

use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAddress\Support\ConfiguresAddressFields;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressProvider;
use GP247\Shop\Tests\Feature\ShopAddress\Support\FakeAddressRegistry;
use GP247\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;

/**
 * A form that is not a Livewire component builds its address fields from three
 * read-only endpoints: the fields of a country, the choices of a field, the addresses
 * of a postcode.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-data-endpoints
 * @aidlc-story US-shop-address-postcode-lookup
 */
#[Group('shop-address')]
class AddressEndpointsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use ConfiguresAddressFields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        FakeAddressRegistry::bind(['FakeAddress' => new FakeAddressProvider()]);
        $this->configureAddress(['customer_city' => '1', 'customer_district' => '1', 'customer_ward' => '1',
            'customer_postcode' => '1', 'customer_country' => '1']);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    public function test_the_schema_lists_the_fields_of_a_country_and_how_each_is_entered(): void
    {
        $response = $this->getJson(route('address.schema', ['country' => 'vn']))->assertOk();

        $response->assertJsonPath('country', 'VN')->assertJsonPath('provider', 'FakeAddress')->assertJsonPath('strict', true);
        $fields = collect($response->json('fields'))->keyBy('name');
        $this->assertSame('select', $fields['city']['input']);
        $this->assertSame('city', $fields['ward']['parent_field']);
        $this->assertSame('text', $fields['district']['input']);
        $this->assertArrayNotHasKey('address2', $fields->all());
    }

    public function test_the_schema_answers_in_the_language_of_the_page_not_of_the_session(): void
    {
        session(['locale' => 'vi']);
        $label = function (string $lang): string {
            $fields = $this->getJson(route('address.schema', ['country' => 'VN', 'context' => 'customer_insert', 'lang' => $lang]))
                ->assertOk()->json('fields');

            return (string) collect($fields)->keyBy('name')['district']['label'];
        };
        $expected = function (string $lang): string {
            app()->setLocale($lang);

            return (string) gp247_language_render('customer.district');
        };

        $en = $label('en');
        $vi = $label('vi');
        $this->assertSame($expected('en'), $en);
        $this->assertSame($expected('vi'), $vi);
        $this->assertNotSame($en, $vi);
        // An unknown language is ignored: the session's language answers.
        $this->assertSame($vi, $label('xx'));
    }

    public function test_the_admin_forms_can_ask_for_their_schema_too(): void
    {
        $this->getJson(route('address.schema', ['country' => 'VN', 'context' => 'admin_order']))
            ->assertOk()->assertJsonPath('provider', 'FakeAddress');
        $this->getJson(route('address.schema', ['country' => 'VN', 'context' => 'nonsense']))->assertStatus(422);
    }

    public function test_a_country_without_a_plugin_gets_a_free_text_schema(): void
    {
        $this->getJson(route('address.schema', ['country' => 'US']))
            ->assertOk()->assertJsonPath('provider', null)->assertJsonPath('fields.0.input', 'text');
    }

    public function test_the_choices_of_a_child_field_are_those_of_the_given_parent(): void
    {
        $this->getJson(route('address.options', ['country' => 'VN', 'component' => 'ward', 'parent' => '79']))
            ->assertOk()
            ->assertJsonPath('fallback', false)
            ->assertJsonPath('options', [['code' => '26734', 'name' => 'Phường Sài Gòn']]);
    }

    public function test_a_postcode_returns_its_addresses(): void
    {
        $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => '100-0001']))
            ->assertOk()
            ->assertJsonPath('suggestions.0.components.prefecture.code', '13');
    }

    public function test_a_postcode_typed_with_a_japanese_keyboard_reaches_the_plugin(): void
    {
        // Full-width digits and dashes, the postal mark and the ideographic space are what a
        // Japanese input method types; the plugin turns them into a postcode, not the endpoint.
        foreach (['１００－０００１', '〒100-0001', '100ー0001', '１００　０００１'] as $typed) {
            $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => $typed]))
                ->assertOk()
                ->assertJsonPath('fallback', false);
        }
    }

    public function test_malformed_parameters_are_refused(): void
    {
        $this->getJson(route('address.schema', ['country' => 'VNM']))->assertStatus(422);
        $this->getJson(route('address.options', ['country' => 'VN', 'component' => "ward' OR 1=1"]))->assertStatus(422);
        $this->getJson(route('address.options', ['country' => 'VN']))->assertStatus(422);
        $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => str_repeat('1', 40)]))->assertStatus(422);
        $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => '<script>']))->assertStatus(422);
    }

    public function test_a_failing_plugin_answers_with_a_fallback_flag_not_an_error(): void
    {
        $provider = new FakeAddressProvider();
        $provider->failing = ['options', 'lookup'];
        FakeAddressRegistry::bind(['FakeAddress' => $provider]);

        $this->getJson(route('address.options', ['country' => 'VN', 'component' => 'province']))
            ->assertOk()->assertJsonPath('fallback', true)->assertJsonPath('options', []);
        $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => '100-0001']))
            ->assertOk()->assertJsonPath('fallback', true);
    }

    public function test_the_endpoints_are_rate_limited_and_never_shared_by_caches(): void
    {
        $options = $this->getJson(route('address.options', ['country' => 'VN', 'component' => 'province']));
        $lookup = $this->getJson(route('address.lookup', ['country' => 'JP', 'postcode' => '100-0001']));

        $this->assertNotNull($options->headers->get('X-RateLimit-Limit'));
        $this->assertStringContainsString('private', (string) $options->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=', (string) $options->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $lookup->headers->get('Cache-Control'));
    }
}
