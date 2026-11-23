<?php

namespace GP247\Shop\Tests\Feature\TrackingConsent;

use GP247\Front\Tracking\Application\CommerceEventCollector;
use GP247\Front\Tracking\Application\TrackingHead;
use GP247\Front\Tracking\Application\TrackingSettingsService;
use GP247\Shop\Events\CommerceEventOccurred;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use GP247\Front\Testing\InteractsWithFrontStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Front\Testing\ConfiguresTracking;
use GP247\Shop\Tests\TestCase;

/**
 * The tracking layer receives the storefront's e-commerce events and prints them for the
 * consent loader — only when GA4, GTM or Meta Pixel is set, never on admin pages, with
 * the allowed fields only and escaped so no field can break out of the page; an event of
 * a redirecting request (add to cart by form) is printed on the next page.
 *
 * @aidlc-unit tracking-consent
 * @aidlc-story US-tracking-consent-commerce-events
 * @aidlc-adr tracking-consent_commerce-event-contract
 */
#[Group('tracking-consent')]
class TrackingCommerceEventsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithFrontStore;
    use MakesShopProducts;
    use ConfiguresTracking;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontStore();
        $this->setUpTracking();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->tearDownFrontStore();
        parent::tearDown();
    }

    /**
     * @param string $type
     * @return array<string, mixed>
     */
    private function payload(string $type = 'view_item'): array
    {
        return [
            'currency' => 'USD',
            'value' => 25,
            'items' => [['item_id' => 'SKU-1', 'item_name' => 'Shirt', 'item_category' => 'Tops', 'item_brand' => 'GP', 'price' => 25, 'quantity' => 1]],
        ] + ($type === 'purchase' ? ['transaction_id' => 'ORDER-1'] : []);
    }

    /**
     * @param string $html
     * @return array<int, array<string, mixed>>
     */
    private function printedEvents(string $html): array
    {
        if (!preg_match('/<script type="application\/json" id="gp247-commerce-events">(.*?)<\/script>/s', $html, $m)) {
            return [];
        }

        return json_decode($m[1], true);
    }

    public function test_the_shop_event_reaches_the_tracking_layer_when_front_and_shop_are_both_installed(): void
    {
        $this->assertTrue(Event::hasListeners(CommerceEventOccurred::class));
    }

    public function test_an_event_of_the_page_is_printed_for_the_loader_when_a_service_is_set(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');

        event(new CommerceEventOccurred('view_item', $this->payload()));

        $events = $this->printedEvents(TrackingHead::build(TrackingSettingsService::rootId()));
        $this->assertSame([['type' => 'view_item', 'payload' => $this->payload()]], $events);
    }

    public function test_without_ga4_gtm_or_pixel_no_event_data_is_printed(): void
    {
        $this->addSnippetRow('<script>custom()</script>');

        event(new CommerceEventOccurred('purchase', $this->payload('purchase')));

        $html = TrackingHead::build(TrackingSettingsService::rootId());
        $this->assertStringContainsString('gp247-tracking-config', $html, 'the custom code is still there');
        $this->assertStringNotContainsString('gp247-commerce-events', $html);
    }

    public function test_only_the_allowed_fields_are_printed_and_a_field_cannot_break_out_of_the_page(): void
    {
        $this->setTracking('tracking_pixel_id', '1234567890');
        $payload = $this->payload('purchase');
        $payload['email'] = 'buyer@example.com';
        $payload['items'][0]['item_name'] = '</script><script>alert(1)</script>';
        $payload['items'][0]['customer_phone'] = '0900000000';

        event(new CommerceEventOccurred('purchase', $payload));

        $html = TrackingHead::build(TrackingSettingsService::rootId());
        $this->assertStringNotContainsString('buyer@example.com', $html);
        $this->assertStringNotContainsString('0900000000', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $events = $this->printedEvents($html);
        $this->assertSame('</script><script>alert(1)</script>', $events[0]['payload']['items'][0]['item_name']);
        $this->assertArrayNotHasKey('email', $events[0]['payload']);
    }

    public function test_switching_tracking_off_prints_no_event(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');
        $this->setTracking('tracking_enabled', '0');

        event(new CommerceEventOccurred('view_item', $this->payload()));

        $this->assertSame('', TrackingHead::build(TrackingSettingsService::rootId()));
    }

    public function test_the_product_page_carries_its_view_item_event(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');
        $product = $this->makeProduct('ZZ Event Page Product');

        $html = $this->get($product->getUrl())->assertOk()->getContent();

        $events = $this->printedEvents($html);
        $this->assertSame('view_item', $events[0]['type'] ?? null);
        $this->assertSame('ZZ Event Page Product', $events[0]['payload']['items'][0]['item_name']);
    }

    public function test_adding_to_cart_with_the_form_shows_the_event_on_the_cart_page(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');
        $product = $this->makeProduct('ZZ Event Form Product');

        $redirect = $this->post(route('cart.add'), ['product_id' => (string) $product->id, 'qty' => 2]);
        $redirect->assertRedirect();
        $html = $this->get($redirect->headers->get('Location'))->assertOk()->getContent();

        $events = $this->printedEvents($html);
        $this->assertSame('add_to_cart', $events[0]['type'] ?? null);
        $this->assertEquals(2, $events[0]['payload']['items'][0]['quantity']);

        $this->assertSame([], $this->printedEvents($this->get($redirect->headers->get('Location'))->getContent()), 'shown once');
    }

    public function test_events_are_kept_per_request(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');
        event(new CommerceEventOccurred('view_item', $this->payload()));

        $this->assertCount(1, app(CommerceEventCollector::class)->pending());
        $this->app->forgetScopedInstances();
        $this->assertSame([], app(CommerceEventCollector::class)->pending());
    }

    public function test_the_shop_is_told_to_build_events_only_when_a_service_runs_on_the_store(): void
    {
        $this->assertFalse(TrackingHead::wantsCommerceEvents('view_item'), 'nothing set');

        $this->addSnippetRow('<script>custom()</script>');
        $this->assertFalse(TrackingHead::wantsCommerceEvents('view_item'), 'custom code gets no events');

        $this->setTracking('tracking_pixel_id', '1234567890');
        $this->assertTrue(TrackingHead::wantsCommerceEvents('view_item'));

        $this->setTracking('tracking_enabled', '0');
        $this->assertFalse(TrackingHead::wantsCommerceEvents('view_item'), 'switched off');
    }

    public function test_tax_and_shipping_of_a_purchase_are_printed(): void
    {
        $this->setTracking('tracking_ga4_id', 'G-EVENTS123');

        event(new CommerceEventOccurred('purchase', $this->payload('purchase') + ['tax' => 2.5, 'shipping' => 4]));

        $events = $this->printedEvents(TrackingHead::build(TrackingSettingsService::rootId()));
        $this->assertEquals(2.5, $events[0]['payload']['tax']);
        $this->assertEquals(4, $events[0]['payload']['shipping']);
    }
}
