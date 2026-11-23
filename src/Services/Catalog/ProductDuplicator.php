<?php

namespace GP247\Shop\Services\Catalog;

use GP247\Core\Models\AdminCustomFieldDetail;
use GP247\Shop\Admin\Models\AdminProduct;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductAttribute;
use GP247\Shop\Models\ShopProductBuild;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Models\ShopProductDownload;
use GP247\Shop\Models\ShopProductGroup;
use GP247\Shop\Models\ShopProductImage;
use GP247\Shop\Models\ShopProductPromotion;

/**
 * Duplicates a product in one transaction: a new, switched-off product with no stock that
 * carries the original's texts, prices, tax, variants, images, categories, brand, supplier,
 * tags, custom fields, store and bundle / group links — then lets plugins copy their own data
 * through `gp247-config.shop.product_duplicators`.
 *
 * What is copied is an allowlist (see the logical design shop-admin_product-duplicate):
 * a column or table added later is not copied until it is listed here. Not copied: stock,
 * sold, views, last view, barcodes (upc / ean / jan / isbn / mpn), content SEO (SEO tab: title,
 * description, canonical, robots, OG image — the copy starts with an empty SEO tab, because
 * people duplicating rarely open it and the copy would go live with the original's meta),
 * reviews, flash sales and any other plugin data unless the plugin copies it. Changed:
 * status off, sku and alias get a `-copy` / `-copy-<n>` suffix unique in the product's store,
 * each name gets the "(copy)" label of its language. Image and download files are shared, never copied.
 *
 * Plugin hook — register from the plugin's Provider.php:
 *
 *   $list = config('gp247-config.shop.product_duplicators', []);
 *   $list[] = ['key' => 'MyPlugin', 'callback' => [MyDuplicator::class, 'copy']];
 *   config(['gp247-config.shop.product_duplicators' => $list]);
 *
 * The callback receives (ShopProduct $source, ShopProduct $copy, WriteContext $ctx), runs
 * after the shop's own parts are copied and inside the same transaction. An exception is
 * NOT swallowed: the duplicate is rolled back and {@see ProductDuplicateFailed} names the
 * plugin. Public contract of gp247/shop — a breaking change is a major release.
 *
 * @aidlc-unit shop-admin
 * @aidlc-story US-SADM-product-duplicate, US-SADM-product-duplicate-plugin-hook
 * @aidlc-adr shop-admin_product-duplicate
 */
class ProductDuplicator extends CatalogWriter
{
    /** Columns of shop_product copied as they are. */
    public const COPIED_COLUMNS = [
        'image', 'brand_id', 'supplier_id', 'price', 'cost', 'minimum', 'weight_class', 'weight', 'length_class', 'length',
        'width', 'height', 'kind', 'product_type', 'tax_id', 'approve', 'sort', 'date_available', 'store_id',
    ];

    /** Config key of the plugin hook. */
    public const HOOK_CONFIG = 'gp247-config.shop.product_duplicators';

    /** Max length of a sku (column and form rule). */
    public const SKU_MAX = 50;

    /** Max length of an alias (as CatalogWriter::alias() cuts it). */
    public const ALIAS_MAX = 100;

    /** Suffix candidates tried before giving up. */
    private const SUFFIX_ATTEMPTS = 1000;

    /** Name label used when the language row of the label is missing (site not updated yet). */
    private const NAME_SUFFIX_FALLBACK = '(copy)';

    /**
     * Duplicate a product of the context's store.
     *
     * @param WriteContext $ctx      Store of the product (the copy stays in it) and actor.
     * @param string       $sourceId Product to duplicate.
     * @return ShopProduct The copy.
     * @throws \Illuminate\Validation\ValidationException When the product belongs to another store.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException When the product does not exist.
     * @throws ProductDuplicateFailed When a registered plugin fails (nothing is kept).
     */
    public function duplicate(WriteContext $ctx, string $sourceId): ShopProduct
    {
        /** @var ShopProduct $source */
        $source = $this->ownedOrFail(ShopProduct::class, $sourceId, $ctx);

        return $this->transaction(function () use ($ctx, $source): ShopProduct {
            $copy = $this->createProductRow($source, $ctx->storeId);
            $this->copyDescriptions($source, $copy);
            $this->copyCustomFields($source, $copy);
            $copy->categories()->sync($source->categories()->pluck('category_id')->all());
            $copy->tags()->sync($source->tags()->pluck('tag_id')->all());
            $this->copyChildren($source, $copy);
            $this->runPluginDuplicators($source, $copy, $ctx);

            return $copy;
        });
    }

    /**
     * First free value of `$base-copy`, `$base-copy-2`… for a product column in a store,
     * the base cut so that the suffix always fits.
     *
     * @param string $field   `sku` or `alias`.
     * @param string $base    Value of the original.
     * @param int    $max     Max length of the column.
     * @param string $storeId Store the value must be unique in.
     * @return string
     * @throws \RuntimeException When every candidate is taken.
     */
    public function uniqueSuffixed(string $field, string $base, int $max, string $storeId): string
    {
        for ($n = 1; $n <= self::SUFFIX_ATTEMPTS; $n++) {
            $candidate = self::suffixed($base, $n, $max);
            if (!ShopProduct::where($field, $candidate)->where('store_id', $storeId)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('No free ' . $field . ' for the copy of "' . $base . '".');
    }

    /**
     * `$base-copy` (n = 1) or `$base-copy-n`, the base cut (and trailing dashes dropped) to fit $max.
     *
     * @param string $base
     * @param int    $n   Candidate number, from 1.
     * @param int    $max Max length in characters.
     * @return string
     */
    public static function suffixed(string $base, int $n, int $max): string
    {
        $suffix = $n === 1 ? '-copy' : '-copy-' . $n;
        $room = max(0, $max - mb_strlen($suffix));

        return rtrim(mb_substr($base, 0, $room), '-') . $suffix;
    }

    /**
     * Insert the shop_product row of the copy.
     *
     * @param ShopProduct $source
     * @param string      $storeId
     * @return ShopProduct
     */
    private function createProductRow(ShopProduct $source, string $storeId): ShopProduct
    {
        $attributes = array_intersect_key($source->getAttributes(), array_flip(self::COPIED_COLUMNS));
        $attributes['store_id'] = $storeId;
        $attributes['sku'] = $this->uniqueSuffixed('sku', (string) $source->sku, self::SKU_MAX, $storeId);
        $attributes['alias'] = $this->uniqueSuffixed('alias', (string) $source->alias, self::ALIAS_MAX, $storeId);
        // WHY: a copy is never on sale before the admin has checked it, and owns no stock or history.
        $attributes['status'] = 0;
        $attributes['stock'] = 0;
        $attributes['sold'] = 0;
        $attributes['view'] = 0;

        return AdminProduct::createProductAdmin($attributes);
    }

    /**
     * Copy every language row, each name with the copy label of its own language.
     *
     * @param ShopProduct $source
     * @param ShopProduct $copy
     * @return void
     */
    private function copyDescriptions(ShopProduct $source, ShopProduct $copy): void
    {
        $rows = [];
        foreach (ShopProductDescription::where('product_id', $source->id)->get() as $row) {
            $suffix = ' ' . $this->nameSuffix((string) $row->lang);
            $name = (string) $row->name;
            $rows[] = [
                'product_id' => $copy->id,
                'lang' => $row->lang,
                'name' => trim(mb_substr($name, 0, max(0, 255 - mb_strlen($suffix))) . $suffix),
                'keyword' => $row->keyword,
                'description' => $row->description,
                'content' => $row->content,
            ];
        }
        if ($rows !== []) {
            ShopProductDescription::insert($rows);
        }
    }

    /**
     * The "(copy)" label of a language.
     *
     * @param string $lang
     * @return string
     */
    private function nameSuffix(string $lang): string
    {
        $key = 'admin.product.duplicate_name_suffix';
        $text = function_exists('gp247_language_render') ? (string) gp247_language_render($key, [], $lang) : '';

        // WHY: before gp247:shop-update seeds the label, rendering returns the key itself.
        return ($text === '' || $text === $key) ? self::NAME_SUFFIX_FALLBACK : $text;
    }

    /**
     * Copy the custom-field values of the product's own custom fields.
     *
     * @param ShopProduct $source
     * @param ShopProduct $copy
     * @return void
     */
    private function copyCustomFields(ShopProduct $source, ShopProduct $copy): void
    {
        $detail = (new AdminCustomFieldDetail)->getTable();
        $field = GP247_DB_PREFIX . 'admin_custom_field';
        $values = AdminCustomFieldDetail::join($field, $field . '.id', $detail . '.custom_field_id')
            ->where($detail . '.rel_id', $source->id)
            ->where($field . '.type', $source->getTable())
            ->get([$detail . '.custom_field_id', $detail . '.text']);
        $now = now();
        $rows = [];
        foreach ($values as $value) {
            $rows[] = ['id' => gp247_generate_id(), 'custom_field_id' => $value->custom_field_id, 'rel_id' => $copy->id, 'text' => $value->text, 'created_at' => $now, 'updated_at' => $now];
        }
        if ($rows !== []) {
            AdminCustomFieldDetail::insert($rows);
        }
    }

    /**
     * Copy images, variants, bundle / group links, promotion and download — one insert per
     * table (a product with 100 variants and 50 images stays a handful of queries).
     *
     * @param ShopProduct $source
     * @param ShopProduct $copy
     * @return void
     */
    private function copyChildren(ShopProduct $source, ShopProduct $copy): void
    {
        $id = $copy->id;
        $this->insertRows(ShopProductImage::class, ShopProductImage::where('product_id', $source->id)->get(['image'])
            ->map(static fn ($r): array => ['id' => gp247_generate_id(), 'product_id' => $id, 'image' => $r->image])->all());
        $this->insertRows(ShopProductAttribute::class, ShopProductAttribute::where('product_id', $source->id)->orderBy('id')
            ->get(['attribute_group_id', 'name', 'add_price', 'slug', 'sort', 'status'])
            ->map(static fn ($r): array => ['product_id' => $id] + $r->getAttributes())->all());
        $this->insertRows(ShopProductBuild::class, ShopProductBuild::where('build_id', $source->id)->get(['product_id', 'quantity'])
            ->map(static fn ($r): array => ['build_id' => $id, 'product_id' => $r->product_id, 'quantity' => $r->quantity])->all());
        $this->insertRows(ShopProductGroup::class, ShopProductGroup::where('group_id', $source->id)->get(['product_id'])
            ->map(static fn ($r): array => ['group_id' => $id, 'product_id' => $r->product_id])->all());
        $this->insertRows(ShopProductDownload::class, ShopProductDownload::where('product_id', $source->id)->get(['path'])
            ->map(static fn ($r): array => ['id' => gp247_uuid(), 'product_id' => $id, 'path' => $r->path])->all());

        $promotion = ShopProductPromotion::where('product_id', $source->id)->first();
        if ($promotion !== null) {
            ShopProductPromotion::insert([
                'product_id' => $id, 'price_promotion' => $promotion->price_promotion, 'date_start' => $promotion->date_start,
                'date_end' => $promotion->date_end, 'status_promotion' => $promotion->status_promotion, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private function insertRows(string $modelClass, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $modelClass::insert($chunk);
        }
    }

    /**
     * Let registered plugins copy their own data; a failure cancels the duplicate.
     *
     * @param ShopProduct  $source
     * @param ShopProduct  $copy
     * @param WriteContext $ctx
     * @return void
     * @throws ProductDuplicateFailed
     */
    private function runPluginDuplicators(ShopProduct $source, ShopProduct $copy, WriteContext $ctx): void
    {
        $duplicators = config(self::HOOK_CONFIG, []);
        if (!is_array($duplicators)) {
            return;
        }
        foreach ($duplicators as $duplicator) {
            $key = (string) ($duplicator['key'] ?? '?');
            $callback = $duplicator['callback'] ?? null;
            if (!is_callable($callback)) {
                // A misconfigured plugin must not stop the site owner from duplicating.
                gp247_report(msg: 'Product duplicator without a callable callback skipped (' . $key . ')', channel: null);
                continue;
            }
            try {
                $callback($source, $copy, $ctx);
            } catch (\Throwable $e) {
                throw new ProductDuplicateFailed($key, $e);
            }
        }
    }
}
