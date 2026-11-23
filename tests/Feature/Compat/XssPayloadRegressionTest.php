<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Shop\Admin\Livewire\OrderManager;
use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Models\ShopCustomer;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Models\ShopProductCategory;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Services\Order\HistoricalOrderWriter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresProductGate;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopOrders;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Text that carries markup — a script tag, an attribute break-out with an event handler, a
 * javascript: URL, or the same already entity-encoded — is shown as text wherever the main
 * input paths display it: the admin order screen, the customer's order page, the product
 * page (category, brand, attribute group and option names) and the search page. The data
 * is written AS TYPED (the way HistoricalOrderWriter and, after US-CMP-text-stored-as-typed,
 * every writer stores it), so the page must not rely on an encode-on-save to be safe.
 *
 * Data is created inside the test transaction with a per-run marker; no DDL.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-xss-sqli-payload-regression
 * @aidlc-story US-CMP-raw-output-guard
 * @aidlc-adr compat-foundation_static-security-guards
 */
class XssPayloadRegressionTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use InteractsWithFrontStore;
    use ConfiguresProductGate;
    use MakesShopOrders;
    use MakesShopProducts {
        MakesShopProducts::makeProduct insteadof MakesShopOrders;
        MakesShopProducts::storeId insteadof MakesShopOrders;
    }

    /** Marker every payload carries; a live element/attribute holding it is an XSS. */
    private const MARK = 'zzXss';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
    }

    protected function tearDown(): void
    {
        // WHY: the product gates written in the transaction are rolled back, but the static
        // AdminConfig memo would keep them for the next test of this process.
        $this->resetProductGateCache();
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * Payloads keyed by name.
     *
     * @return array<string, string>
     */
    private function payloads(): array
    {
        return [
            'script' => '<script>window.' . self::MARK . '=1</script>',
            'breakout' => '"><img src=x onerror=window.' . self::MARK . '=1>',
            'jsurl' => 'javascript:window.' . self::MARK . '=1',
            'encoded' => '&lt;script&gt;window.' . self::MARK . '=2&lt;/script&gt;',
            'numeric' => '&#106;avascript:window.' . self::MARK . '=3',
        ];
    }

    /**
     * Fail when the HTML holds a live script, event-handler attribute or javascript: URL
     * that carries the payload marker. Text (escaped) occurrences are fine.
     *
     * @param string $html
     * @param string $where
     * @return void
     */
    private function assertNoLivePayload(string $html, string $where): void
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($dom);

        $live = [];
        foreach ($xpath->query('//script') as $script) {
            if (str_contains($script->textContent, self::MARK) && !str_contains($script->textContent, '\\u003C')) {
                // WHY: JSON inside <script> (Livewire/JSON-LD) carries the marker as data;
                // only a script whose own code is the payload counts.
                if (preg_match('/^\s*window\.' . self::MARK . '=/', $script->textContent)) {
                    $live[] = '<script>' . trim($script->textContent) . '</script>';
                }
            }
        }
        foreach ($xpath->query('//@*') as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;
            if (!str_contains($value, self::MARK)) {
                continue;
            }
            if (str_starts_with($name, 'on')) {
                $live[] = $attribute->parentNode->nodeName . '[' . $name . '="' . $value . '"]';
            }
            if (in_array($name, ['href', 'src', 'action', 'formaction'], true) && preg_match('/^\s*javascript:/i', $value)) {
                $live[] = $attribute->parentNode->nodeName . '[' . $name . '="' . $value . '"]';
            }
        }

        $this->assertSame([], $live, $where . ' renders the payload as live HTML');
    }

    /**
     * Write an archived order whose texts are the payloads (stored as typed).
     *
     * @param string $customerId
     * @return \GP247\Shop\Models\ShopOrder
     */
    private function historicalOrderWithMarkup(string $customerId = ''): \GP247\Shop\Models\ShopOrder
    {
        $p = $this->payloads();

        return (new HistoricalOrderWriter)->write(new WriteContext(GP247_STORE_ID_ROOT, WriteContext::ACTOR_IMPORT), [
            'order' => [
                'customer_id' => $customerId,
                'first_name' => $p['script'], 'last_name' => $p['breakout'], 'company' => $p['encoded'],
                'email' => 'zz-xss-' . uniqid() . '@example.test',
                'phone' => '<b onclick=' . self::MARK . '>', 'address1' => $p['script'], 'address2' => $p['breakout'], 'address3' => $p['numeric'],
                'city' => $p['breakout'], 'district' => $p['script'], 'ward' => $p['jsurl'], 'postcode' => '70000', 'country' => 'VN',
                'comment' => $p['breakout'],
                'currency' => 'USD', 'exchange_rate' => 1,
                'subtotal' => 10, 'tax' => 0, 'shipping' => 0, 'discount' => 0, 'other_fee' => 0, 'total' => 10, 'received' => 0,
                'status' => 1, 'shipping_status' => 1,
                'payment_method' => 'cod', 'shipping_method' => 'none',
                'created_at' => '2024-03-01 10:00:00',
            ],
            'lines' => [
                ['sku' => 'ZZ-XSS', 'name' => $p['breakout'], 'price' => 10, 'qty' => 1, 'total_price' => 10, 'tax' => 0, 'tax_rate' => 0],
            ],
            'history' => [
                ['order_status_id' => 1, 'content' => $p['breakout'], 'add_date' => '2024-03-01 10:00:00'],
                ['order_status_id' => 1, 'content' => $p['script'], 'add_date' => '2024-03-01 11:00:00'],
            ],
            'source' => 'zz-xss',
        ]);
    }

    public function test_an_imported_order_with_markup_shows_as_text_on_the_admin_order_screen(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $order = $this->historicalOrderWithMarkup();

        $html = Livewire::test(OrderManager::class, ['id' => (string) $order->id])->html();

        $this->assertStringContainsString(self::MARK, $html, 'the order texts are on the screen');
        $this->assertNoLivePayload($html, 'admin order screen');
    }

    public function test_an_order_line_option_with_markup_shows_as_text_on_the_admin_order_screen(): void
    {
        $this->asUser(slugs: ['admin_order']);
        $p = $this->payloads();
        $order = $this->makeOrder();
        $group = ShopAttributeGroup::create(['name' => $p['breakout'], 'type' => 'select']);
        $this->makeOrderItem($order, ['attribute' => json_encode([(string) $group->id => $p['breakout'] . '__0'])]);

        $html = Livewire::test(OrderManager::class, ['id' => (string) $order->id])->html();

        $this->assertStringContainsString(self::MARK, $html);
        $this->assertNoLivePayload($html, 'admin order items');
    }

    public function test_an_imported_order_with_markup_shows_as_text_on_the_customer_order_page(): void
    {
        $customer = ShopCustomer::create([
            'first_name' => 'ZZ', 'email' => 'zz-xss-cus-' . uniqid() . '@example.com', 'password' => bcrypt('Secret#123'),
            'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT,
        ]);
        $order = $this->historicalOrderWithMarkup((string) $customer->id);
        $this->actingAs($customer, 'customer');

        $response = $this->get(gp247_route_front('customer.order_detail', ['id' => $order->id]));

        $response->assertOk();
        $this->assertStringContainsString(self::MARK, $response->getContent());
        $this->assertNoLivePayload($response->getContent(), 'customer order page');
    }

    public function test_category_brand_and_attribute_names_with_markup_show_as_text_on_the_product_page(): void
    {
        $p = $this->payloads();
        $this->setProductGate('product_brand', true);
        $this->setProductGate('product_attribute', true);

        $brand = ShopBrand::create(['name' => $p['breakout'], 'alias' => 'zz-xss-brand-' . uniqid(), 'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        $product = $this->makeProduct('ZZ XSS product', ['brand_id' => $brand->id]);
        $category = ShopCategory::create(['alias' => 'zz-xss-cat-' . uniqid(), 'parent' => 0, 'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        foreach (array_unique([gp247_get_locale(), 'en', 'vi', 'ja']) as $lang) {
            ShopCategoryDescription::create(['category_id' => $category->id, 'lang' => $lang, 'name' => $p['script'], 'keyword' => '', 'description' => '']);
        }
        ShopProductCategory::create(['product_id' => $product->id, 'category_id' => $category->id]);
        $group = ShopAttributeGroup::create(['name' => $p['breakout'], 'type' => 'radio']);
        ShopProductAttribute::create(['name' => $p['breakout'], 'attribute_group_id' => $group->id, 'product_id' => $product->id, 'add_price' => 0, 'sort' => 0, 'status' => 1]);

        $response = $this->get($product->getUrl());

        $response->assertOk();
        $this->assertStringContainsString(self::MARK, $response->getContent());
        $this->assertNoLivePayload($response->getContent(), 'product page');
    }

    public function test_the_display_helpers_escape_the_text_they_wrap_once(): void
    {
        $p = $this->payloads();

        $image = gp247_image_render('', '20px', '20px', $p['breakout'], $p['script']);
        $this->assertNoLivePayload('<div>' . $image . '</div>', 'gp247_image_render');
        $this->assertStringContainsString('alt="&quot;&gt;&lt;img', $image);
        $this->assertStringContainsString('alt="Cron &amp; Queue"', gp247_image_render('', null, null, 'Cron &amp; Queue'), 'text stored encoded is not encoded twice');

        $option = gp247_render_option_price($p['breakout'] . '__0');
        $this->assertNoLivePayload('<div>' . $option . '</div>', 'gp247_render_option_price');
        $this->assertStringStartsWith('&quot;&gt;&lt;img', $option);

        $history = gp247_render_basic_markup('Change <b>status</b> from 1 to 2<br />' . $p['breakout'] . $p['script'] . '<b onclick="window.' . self::MARK . '=1">x</b>');
        $this->assertNoLivePayload('<div>' . $history . '</div>', 'gp247_render_basic_markup');
        $this->assertStringStartsWith('Change <b>status</b> from 1 to 2<br>', $history, 'simple markup written by code survives');

        $this->assertSame(e($p['script'], false), gp247_language_label($p['script']), 'text that is not a language key is escaped');
        $this->assertSame(gp247_language_render('admin.sort'), gp247_language_label('admin.sort'), 'a language key gives its translation');
    }

    public function test_a_product_name_and_a_search_term_with_markup_show_as_text_on_the_search_page(): void
    {
        $p = $this->payloads();
        $this->makeProduct('ZZXSSNAME ' . $p['breakout']);
        $this->makeProduct('ZZXSSNAME ' . $p['encoded']);

        foreach (['ZZXSSNAME', $p['breakout'], $p['script'], $p['encoded']] as $keyword) {
            $response = $this->get(gp247_route_front('front.search', ['keyword' => $keyword]));

            $response->assertOk();
            $this->assertNoLivePayload($response->getContent(), 'search page for "' . $keyword . '"');
        }
    }
}
