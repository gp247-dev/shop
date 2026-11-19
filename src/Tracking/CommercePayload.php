<?php

namespace GP247\Shop\Tracking;

use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Services\CartItem;

/**
 * Payload of the storefront e-commerce events, built from server data only and limited to
 * an allow-list: per item the id (SKU, else product id), name, first category, brand, unit
 * price and quantity; then currency, value and — for a purchase — the order id, tax and
 * shipping (value is the order total). Nothing
 * that identifies the customer (NFR-SEC-tracking-no-personal-data). Amounts are in the
 * currency the visitor sees: catalogue prices are converted like the checkout does; an
 * order already stores its amounts in its own currency.
 *
 * @aidlc-unit storefront
 * @aidlc-story US-LW-commerce-tracking-events
 * @aidlc-adr tracking-consent_commerce-event-contract
 */
final class CommercePayload
{
    public const TYPES = ['view_item', 'add_to_cart', 'begin_checkout', 'purchase'];

    /**
     * One product, e.g. the product page or an add to cart.
     *
     * @param ShopProduct          $product
     * @param int|float            $qty
     * @param array<string, mixed> $options Chosen attributes (their surcharge is in the price).
     * @return array<string, mixed>
     */
    public static function forProduct(ShopProduct $product, $qty = 1, array $options = []): array
    {
        $price = self::money(gp247_currency_value($product->getFinalPrice() + ($options ? gp247_cart_options_price($options) : 0)));
        $item = self::item($product, (string) $product->name, $price, $qty);

        return ['currency' => (string) gp247_currency_code(), 'value' => self::money($price * (float) $qty), 'items' => [$item]];
    }

    /**
     * Cart lines being checked out, re-priced from the catalogue like the order will be.
     *
     * @param iterable $lines CartItem objects or their array form.
     * @return array<string, mixed>
     */
    public static function forCartLines(iterable $lines): array
    {
        $items = [];
        $value = 0.0;
        foreach ($lines as $line) {
            $line = CartItem::hydrate($line);
            $product = (new ShopProduct())->getDetail(key: $line->id, type: 'id', storeId: $line->storeId);
            if (!$product) {
                continue;
            }
            $price = self::money(gp247_currency_value($product->getFinalPrice() + gp247_cart_options_price($line->options)));
            $items[] = self::item($product, (string) $line->name, $price, $line->qty);
            $value += $price * (float) $line->qty;
        }

        return ['currency' => (string) gp247_currency_code(), 'value' => self::money($value), 'items' => $items];
    }

    /**
     * A placed order, from what was stored (its own currency).
     *
     * @param array<string, mixed> $order Order with `details` (ShopOrder::with('details')->toArray()).
     * @return array<string, mixed>
     */
    public static function forOrder(array $order): array
    {
        $details = is_array($order['details'] ?? null) ? $order['details'] : [];
        $products = ShopProduct::with(['categories', 'brand'])
            ->whereIn('id', array_values(array_filter(array_column($details, 'product_id'))))
            ->get()
            ->keyBy('id');
        $items = [];
        foreach ($details as $detail) {
            $product = $products->get($detail['product_id'] ?? '');
            $items[] = [
                'item_id' => (string) (($detail['sku'] ?? '') !== '' ? $detail['sku'] : ($detail['product_id'] ?? '')),
                // WHY decode: the order stored the name HTML-escaped (gp247_text_plain); the
                // other events send the plain product name, and one product must match.
                'item_name' => html_entity_decode((string) ($detail['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'item_category' => $product ? self::category($product) : '',
                'item_brand' => $product ? self::brand($product) : '',
                'price' => (float) ($detail['price'] ?? 0),
                'quantity' => (float) ($detail['qty'] ?? 0),
            ];
        }

        return [
            'transaction_id' => (string) ($order['id'] ?? ''),
            'currency' => (string) ($order['currency'] ?? gp247_currency_code()),
            'value' => (float) ($order['total'] ?? 0),
            'tax' => (float) ($order['tax'] ?? 0),
            'shipping' => (float) ($order['shipping'] ?? 0),
            'items' => $items,
        ];
    }

    /**
     * @param ShopProduct $product
     * @param string      $name
     * @param float       $price Unit price in the visitor's currency.
     * @param int|float   $qty
     * @return array<string, mixed>
     */
    private static function item(ShopProduct $product, string $name, float $price, $qty): array
    {
        return [
            'item_id' => (string) ($product->sku !== null && $product->sku !== '' ? $product->sku : $product->id),
            'item_name' => $name,
            'item_category' => self::category($product),
            'item_brand' => self::brand($product),
            'price' => $price,
            'quantity' => (float) $qty,
        ];
    }

    /**
     * @param ShopProduct $product
     * @return string Name of its first category, '' when none.
     */
    private static function category(ShopProduct $product): string
    {
        $category = $product->categories->first();

        return $category ? (string) $category->getTitle() : '';
    }

    /**
     * @param ShopProduct $product
     * @return string Brand name, '' when none.
     */
    private static function brand(ShopProduct $product): string
    {
        return (string) ($product->brand->name ?? '');
    }

    /**
     * @param float $amount
     * @return float Rounded to the decimals of the visitor's currency.
     */
    private static function money(float $amount): float
    {
        $precision = (int) (\GP247\Shop\Models\ShopCurrency::getCurrency()['precision'] ?? 2);

        return round($amount, max(0, $precision));
    }
}
