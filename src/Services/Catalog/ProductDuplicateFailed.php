<?php

namespace GP247\Shop\Services\Catalog;

/**
 * A plugin registered in `gp247-config.shop.product_duplicators` failed while copying its
 * own data, so the whole duplicate was rolled back. Carries the plugin key for the admin
 * message; the plugin's own exception is kept as the previous one for the log.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-duplicate-plugin-hook
 * @aidlc-adr shop-admin_product-duplicate
 */
final class ProductDuplicateFailed extends \RuntimeException
{
    /**
     * @param string          $pluginKey Key the plugin registered under.
     * @param \Throwable|null $previous  The plugin's exception.
     */
    public function __construct(public readonly string $pluginKey, ?\Throwable $previous = null)
    {
        parent::__construct('Product duplicator "' . $pluginKey . '" failed', 0, $previous);
    }
}
