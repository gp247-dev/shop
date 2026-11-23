<?php

namespace GP247\Shop\Tests\Feature\ShopAdmin\Support;

use GP247\Core\Models\AdminLanguage;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;

/**
 * Shared fixtures for shop-admin Product (group F) feature tests: build a minimal
 * SINGLE product attached to the admin (root) store, with one description row per
 * active language. Also exposes the active language codes and pins the admin
 * store id in the session so the product_sku_unique / product_alias_unique
 * validators (which read session('adminStoreId')) resolve consistently with the
 * component's store scope. Domain unchanged (ShopProduct / ShopProductDescription).
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-001
 * @aidlc-adr ADR-006
 */
trait MakesShopProducts
{
    /**
     * The admin store id the ProductManager scopes to (root store in tests).
     *
     * @return int|string
     */
    private function storeId()
    {
        return defined('GP247_STORE_ID_ROOT') ? GP247_STORE_ID_ROOT : 1;
    }

    /**
     * Pin the admin store in the session (component scope + unique validators).
     *
     * @return void
     */
    private function useAdminStore(): void
    {
        session(['adminStoreId' => $this->storeId()]);
    }

    /**
     * Active language codes the product descriptions are edited in.
     *
     * @return array<int, string>
     */
    private function langCodes(): array
    {
        return array_keys(AdminLanguage::getListActive()->all());
    }

    /**
     * Create a SINGLE product attached to the root store, with a name per language.
     *
     * @param string $name Product name (all languages).
     * @param array<string, mixed> $overrides Column overrides.
     * @return ShopProduct
     */
    private function makeProduct(string $name = 'ZZ Product', array $overrides = []): ShopProduct
    {
        $product = ShopProduct::create(array_merge([
            'sku' => 'ZZ-SKU-' . uniqid(),
            'alias' => 'zz-prod-' . uniqid(),
            'price' => 10,
            'cost' => 5,
            'stock' => 100,
            'status' => 1,
            'approve' => 1,
            'kind' => defined('GP247_PRODUCT_SINGLE') ? GP247_PRODUCT_SINGLE : 0,
            'sort' => 0,
            // Store ownership is a plain column since the 1-1 normalization
            // (US-CMP-store-1to1-schema): the shop_product_store pivot and the
            // stores() relation are both gone, so attaching would fatal here.
            'store_id' => $this->storeId(),
        ], $overrides));

        foreach ($this->langCodes() as $code) {
            ShopProductDescription::insert([
                'product_id' => $product->id,
                'lang' => $code,
                'name' => $name,
                'keyword' => '',
                'description' => '',
                'content' => 'Content',
            ]);
        }

        return $product;
    }

    /**
     * Set the product name for every active language on the component.
     *
     * @param \Livewire\Features\SupportTesting\Testable $component
     * @param string $name
     * @return \Livewire\Features\SupportTesting\Testable
     */
    private function withNames($component, string $name)
    {
        foreach ($this->langCodes() as $code) {
            $component->set("desc.{$code}.name", $name)->set("desc.{$code}.content", 'Body');
        }

        return $component;
    }
}
