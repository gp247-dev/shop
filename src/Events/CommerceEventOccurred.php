<?php

namespace GP247\Shop\Events;

use GP247\Shop\Tracking\CommercePayload;
use Illuminate\Support\Facades\Event;

/**
 * A storefront e-commerce event (view_item, add_to_cart, begin_checkout, purchase) for
 * whoever forwards it to analytics / advertising services — in GP247 the tracking layer of
 * gp247/front, which sends it only through the visitor's cookie consent. The shop knows
 * nothing about that layer: with no listener (an older front, or none) nothing happens.
 * Plugins and templates may fire the same event with the same payload shape.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-commerce-tracking-events
 * @aidlc-adr tracking-consent_commerce-event-contract
 */
final class CommerceEventOccurred
{
    /**
     * @param string               $type     One of CommercePayload::TYPES.
     * @param array<string, mixed> $payload  Built by CommercePayload (allowed fields only).
     * @param bool                 $nextPage The response redirects: show the event on the next page.
     * @throws \InvalidArgumentException For any other type.
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly bool $nextPage = false,
    ) {
        if (!in_array($type, CommercePayload::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown commerce event [{$type}]");
        }
    }

    /**
     * Fire the event when someone listens; the payload is built only then.
     * WHY never throw: reporting an event must not break a product, cart or checkout page.
     *
     * @param string   $type
     * @param callable $build    fn(): array — the payload.
     * @param bool     $nextPage
     * @return void
     */
    public static function emit(string $type, callable $build, bool $nextPage = false): void
    {
        try {
            if (!Event::hasListeners(self::class) || !self::wanted($type)) {
                return;
            }
            event(new self($type, $build(), $nextPage));
        } catch (\Throwable $e) {
            if (function_exists('gp247_report')) {
                gp247_report('[commerce-event] ' . $type . ' not reported: ' . $e->getMessage());
            }
        }
    }

    /**
     * Whether the tracking layer wants this event on the current page (config
     * `gp247-config.shop.commerce_event_gate`); no gate set = yes.
     * WHY: building a payload costs queries (cart lines are re-priced); a site with no
     * tracking service must not pay for it on every checkout or add to cart.
     *
     * @param string $type
     * @return bool
     */
    public static function wanted(string $type): bool
    {
        $gate = config('gp247-config.shop.commerce_event_gate');

        return !is_callable($gate) || (bool) $gate($type);
    }
}
