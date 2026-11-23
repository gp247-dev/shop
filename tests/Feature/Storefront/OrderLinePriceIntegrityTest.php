<?php

namespace GP247\Shop\Tests\Feature\Storefront;

use GP247\Core\Models\AdminLanguage;
use GP247\Core\Models\AdminRole;
use GP247\Core\Models\AdminUser;
use GP247\Shop\Admin\Models\AdminOrder;
use GP247\Shop\Models\ShopAttributeGroup;
use GP247\Shop\Models\ShopOrder;
use GP247\Shop\Models\ShopOrderDetail;
use GP247\Shop\Models\ShopOrderTotal;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Services\CartService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\ConfiguresQtyDecimal;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * Order line items store the commercial truth (ADR storefront_order-line-effective-price, domain model shop_order-line-item).
 *
 * Contract under test (NFR-MAINT-order-line-truth / MC-order-line-price-invariant):
 *  - shop_order_detail.price is the EFFECTIVE unit price — final price plus
 *    canonical attribute surcharges, in the order currency;
 *  - total_price = price * qty (tax excluded) on every writer;
 *  - tax is a line AMOUNT (never the percent the admin typed);
 *  - invariants: sum(total_price) == shop_order.subtotal and
 *    sum(detail.tax) == shop_order.tax, including after AdminOrder::updateSubTotal.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-order-line-effective-price
 * @aidlc-story US-SADM-order-create-line-normalization
 * @aidlc-adr storefront_order-line-effective-price
 */
class OrderLinePriceIntegrityTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresQtyDecimal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->resetQtyDecimalCache();
        $this->setQtyDecimalEnabled(false);
    }

    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Create an attribute group + one product attribute value with a known add_price.
     *
     * @param int|string $productId
     * @param string $name
     * @param float $addPrice
     * @return int The attribute group id.
     */
    private function makeAttribute($productId, string $name, float $addPrice): int
    {
        $group = ShopAttributeGroup::create([
            'name' => 'ZZ Line Color ' . uniqid(),
            'type' => 'radio',
            'status' => 1,
            'sort' => 0,
        ]);
        ShopProductAttribute::create([
            'name' => $name,
            'attribute_group_id' => $group->id,
            'product_id' => $productId,
            'add_price' => $addPrice,
            'sort' => 0,
            'status' => 1,
        ]);

        return (int) $group->id;
    }

    /**
     * Drive the real storefront pipeline up to addOrder(): add to cart over HTTP
     * (canonicalized options), then stage the checkout session the way
     * processCheckout/getCheckoutConfirm would, and POST the place-order route.
     *
     * @param ShopProduct $product
     * @param int $groupId Attribute group id for form_attr.
     * @param string $optionRaw The submitted "name__add_price" option string.
     * @param float $qty
     * @return string The buyer email, to look the created order up with.
     */
    private function checkoutWithAttribute(ShopProduct $product, int $groupId, string $optionRaw, float $qty): string
    {
        $response = $this->post(route('cart.add'), [
            'product_id' => (string) $product->id,
            'qty' => $qty,
            'form_attr' => [$groupId => $optionRaw],
        ]);
        $response->assertSessionHasNoErrors();
        $this->assertSame(
            302,
            $response->getStatusCode(),
            'cart.add did not redirect; got ' . $response->getStatusCode() . ' -> ' . (string) $response->headers->get('Location')
        );

        $this->assertSame('', (string) session('error'), 'cart.add flashed an error');
        $this->assertNotSame('', (string) session('success'), 'cart.add did not reach the success branch');
        $cart = (new CartService())->instance('default');
        $this->assertSame(1, $cart->count() > 0 ? 1 : 0, 'cart must hold the added item');

        $email = 'zz-line-' . uniqid() . '@example.com';
        session([
            'dataCheckout' => $cart->content()->values()->all(),
            'storeCheckout' => $this->storeId(),
            'shippingAddress' => [
                'email' => $email,
                'first_name' => 'ZZ Line',
                'phone' => '0123456789',
                'address1' => 'Addr 1',
                'country' => 'US',
            ],
            'paymentMethod' => '',
            'shippingMethod' => '',
            'address_process' => '',
            // The confirm step stamps this idempotency token (US-LW-checkout-idempotency)
            // and addOrder() refuses a POST without one, sending the buyer back to the
            // cart with "`checkout` not found" and creating no order. Staging the rest of
            // the confirm session but not this left the suite asserting against an order
            // that was never written.
            'checkoutToken' => gp247_token(32),
        ]);
        // dataTotal is derived from session('dataCheckout') exactly like the
        // confirm screen does (sumCartCheckout includes option surcharges).
        session(['dataTotal' => ShopOrderTotal::processDataTotal(ShopOrderTotal::getObjectOrderTotal())]);

        $this->post(route('order.add'), ['place_order' => '1'])->assertSessionHasNoErrors();

        return $email;
    }

    /**
     * Create + authenticate an administrator on the `admin` guard (the
     * administrator role short-circuits the URI permission middleware, so the
     * legacy postCreate controller runs without RBAC wiring in the way).
     *
     * @return AdminUser
     */
    private function loginAdministrator(): AdminUser
    {
        $user = AdminUser::create([
            'id' => 'AU-LIN-' . substr(uniqid(), -6),
            'username' => 'line_admin_' . substr(uniqid(), -6),
            'password' => bcrypt('secret'),
            'name' => 'Line Admin',
            'email' => 'line' . substr(uniqid(), -6) . '@test.local',
            'status' => 1,
        ]);

        $role = AdminRole::firstOrCreate(['slug' => 'administrator'], ['name' => 'Administrator']);
        $user->roles()->syncWithoutDetaching([$role->id]);

        $this->actingAs($user, 'admin');
        session(['adminStoreId' => $this->storeId()]);

        return $user;
    }

    /**
     * A product resolvable by AdminProduct::getDetail (postCreate reads it): it
     * left-joins shop_product_description on the active locale, so the row needs
     * a description per active language.
     *
     * @param array<string, mixed> $overrides
     * @return ShopProduct
     */
    private function makeCreatableProduct(array $overrides = []): ShopProduct
    {
        $product = ShopProduct::create(array_merge([
            'sku' => 'ZZ-LIN-' . uniqid(),
            'alias' => 'zz-line-' . uniqid(),
            'price' => 10,
            'stock' => 100,
            'status' => 1,
            'kind' => 0,
        ], $overrides));

        foreach (array_keys(AdminLanguage::getListActive()->all()) as $code) {
            ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => 'Line Item',
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }

        return $product;
    }

    // -------------------------------------------------------------------------
    // V1 — storefront checkout: effective line price
    // -------------------------------------------------------------------------

    public function test_checkout_persists_effective_line_price_including_attribute_surcharge(): void
    {
        $product = $this->makeProduct('ZZ Effective Price', ['price' => 100]);
        $groupId = $this->makeAttribute($product->id, 'Red', 5);

        $email = $this->checkoutWithAttribute($product, $groupId, 'Red__5', 2);

        $order = ShopOrder::where('email', $email)->first();
        $this->assertNotNull($order, 'order must be created through the real addOrder pipeline');

        $details = ShopOrderDetail::where('order_id', $order->id)->get();
        $this->assertCount(1, $details);
        $detail = $details->first();

        // price is the effective unit price: 100 (final) + 5 (surcharge).
        $this->assertSame(105.0, (float) $detail->price);
        // total_price = price * qty, tax excluded.
        $this->assertSame(210.0, (float) $detail->total_price);
        // The attribute JSON still carries the descriptive surcharge.
        $this->assertStringContainsString('Red__5', (string) $detail->attribute);

        // Invariants: sum(line) == subtotal, sum(line tax) == order tax.
        $this->assertSame((float) $order->subtotal, (float) $details->sum('total_price'), 'sum(total_price) must equal shop_order.subtotal');
        $this->assertSame((float) $order->tax, (float) $details->sum('tax'), 'sum(detail.tax) must equal shop_order.tax');
    }

    public function test_checkout_zero_surcharge_attribute_keeps_base_price(): void
    {
        $product = $this->makeProduct('ZZ Zero Surcharge', ['price' => 100]);
        $groupId = $this->makeAttribute($product->id, 'Standard', 0);

        $email = $this->checkoutWithAttribute($product, $groupId, 'Standard__0', 1);

        $order = ShopOrder::where('email', $email)->first();
        $this->assertNotNull($order);
        $detail = ShopOrderDetail::where('order_id', $order->id)->first();

        // No phantom surcharge: effective price equals the base price.
        $this->assertSame(100.0, (float) $detail->price);
        $this->assertSame(100.0, (float) $detail->total_price);
        $this->assertSame((float) $order->subtotal, (float) $detail->total_price);
    }

    // -------------------------------------------------------------------------
    // V2 — admin postCreate: tax amount + untaxed total_price
    // -------------------------------------------------------------------------

    public function test_admin_post_create_persists_tax_amount_and_untaxed_total_price(): void
    {
        $this->loginAdministrator();
        $product = $this->makeCreatableProduct();

        $response = $this->post(route('admin_order.post_create'), [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'buyer-line@example.com',
            'phone' => '0123456789',
            'address1' => 'Addr 1',
            'country' => 'US',
            'postcode' => '12345',
            'company' => 'ACME',
            'currency' => 'USD',
            'exchange_rate' => '1',
            'status' => '1',
            // WHY store_id: the admin create form requires it once a marketplace
            // or multi-store plugin is installed (the admin picks the store), and
            // this suite runs with MultiVendor active — without it the post is
            // rejected by validation and no order is written at all.
            'store_id' => (string) GP247_STORE_ID_ROOT,
            'payment_method' => 'Cash',
            'shipping_method' => 'Free',
            'shipping' => 0,
            'discount' => 0,
            'received' => 0,
            'products' => [
                // The form's tax field is a RATE: 10 (%) on 3 x 10 = 30 -> 3.0 amount.
                ['product_id' => (string) $product->id, 'name' => 'John item', 'qty' => 3, 'price' => 10, 'tax' => 10],
            ],
        ]);
        $response->assertRedirect();

        $detail = ShopOrderDetail::where('product_id', $product->id)->first();
        $this->assertNotNull($detail, 'postCreate must write the order line');

        // total_price excludes tax; tax holds the AMOUNT, not the typed percent.
        $this->assertSame(30.0, (float) $detail->total_price);
        $this->assertSame(3.0, (float) $detail->tax);

        // Header written at create time already used amounts — must match the line.
        $order = ShopOrder::find($detail->order_id);
        $this->assertSame(30.0, (float) $order->subtotal);
        $this->assertSame(3.0, (float) $order->tax);
        $this->assertSame(33.0, (float) $order->total);
    }

    public function test_admin_created_order_survives_update_sub_total_without_double_count(): void
    {
        $this->loginAdministrator();
        $product = $this->makeCreatableProduct();

        $this->post(route('admin_order.post_create'), [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'buyer-recalc@example.com',
            'phone' => '0123456789',
            'address1' => 'Addr 1',
            'country' => 'US',
            'postcode' => '12345',
            'company' => 'ACME',
            'currency' => 'USD',
            'exchange_rate' => '1',
            'status' => '1',
            // WHY store_id: the admin create form requires it once a marketplace
            // or multi-store plugin is installed (the admin picks the store), and
            // this suite runs with MultiVendor active — without it the post is
            // rejected by validation and no order is written at all.
            'store_id' => (string) GP247_STORE_ID_ROOT,
            'payment_method' => 'Cash',
            'shipping_method' => 'Free',
            'shipping' => 0,
            'discount' => 0,
            'received' => 0,
            'products' => [
                ['product_id' => (string) $product->id, 'name' => 'John item', 'qty' => 3, 'price' => 10, 'tax' => 10],
            ],
        ])->assertRedirect();

        $detail = ShopOrderDetail::where('product_id', $product->id)->first();
        $this->assertNotNull($detail);

        // WHY: this recalc path is what corrupted admin-created orders before the
        // fix — total_price included tax (double count) and the tax column held
        // the percent (read as money). It must now be a no-op on a fresh order.
        AdminOrder::updateSubTotal($detail->order_id);

        $order = ShopOrder::find($detail->order_id);
        $this->assertSame(30.0, (float) $order->subtotal, 'recalc must not fold tax into subtotal');
        $this->assertSame(3.0, (float) $order->tax, 'recalc must keep tax as the line amount');
        $this->assertSame(33.0, (float) $order->total, 'recalc must not double-count tax');
    }
}
