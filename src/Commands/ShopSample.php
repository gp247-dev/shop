<?php

namespace GP247\Shop\Commands;

use GP247\Core\Console\GP247Command;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Commands\Sample\ShopSampleCatalog;
use GP247\Shop\Models\ShopCategory;
use GP247\Shop\Models\ShopCategoryDescription;
use GP247\Shop\Models\ShopProduct;
use GP247\Shop\Models\ShopProductDescription;
use GP247\Shop\Models\ShopProductPromotion;
use GP247\Shop\Models\ShopProductCategory;
use GP247\Shop\Models\ShopProductGroup;
use GP247\Shop\Models\ShopProductBuild;
use Carbon\Carbon;

/**
 * Create demo catalog data (categories, brands, suppliers, single/bundle/group
 * products, sample promotions) for an electronics store. Truncates existing shop
 * tables first — dev/demo only, never on a live site.
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-005
 * @aidlc-adr system-cli_output-contract
 */
class ShopSample extends GP247Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gp247:shop-sample';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'GP247 shop sample';

    /**
     * Base URL of the hosted sample product photos (product-1.png … product-N.png).
     */
    private const PRODUCT_IMAGE_BASE = 'https://static.gp247.net/file/s-cart/product/product-';

    /**
     * Base URL of the hosted sample photos for categories, brands and suppliers:
     * each kind has its own set, <base><kind>/<kind>-1.png … <kind>-N.png.
     */
    private const CATALOG_IMAGE_BASE = 'https://static.gp247.net/file/s-cart/';

    /**
     * Placeholder body shared by every sample product, per language.
     */
    private const SAMPLE_CONTENT = [
        'vi' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. <br>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. <br>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>',
        'en' => '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. <br>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. <br>Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.</p>',
        'ja' => '<p>こちらはサンプル商品の説明用のダミーテキストです。実際の商品情報に合わせて内容を編集してください。素材、サイズ、使い方などの詳細をここに記載できます。<br>品質にこだわった商品を、お求めやすい価格でお届けします。ご不明な点がございましたら、お気軽にお問い合わせください。</p><p>こちらはサンプル商品の説明用のダミーテキストです。実際の商品情報に合わせて内容を編集してください。素材、サイズ、使い方などの詳細をここに記載できます。<br>品質にこだわった商品を、お求めやすい価格でお届けします。ご不明な点がございましたら、お気軽にお問い合わせください。</p>',
    ];

    /**
     * Short description of a sample product, per language; ":name" is the product name.
     */
    private const SAMPLE_SHORT_DESCRIPTION = [
        'vi' => ':name — sản phẩm mẫu, hãy sửa nội dung cho phù hợp với cửa hàng của bạn.',
        'en' => ':name — sample product, edit this text to fit your store.',
        'ja' => ':name — サンプル商品です。お店に合わせて内容を編集してください。',
    ];

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    protected function handleGp247(): int
    {
        // WHY the clear phase runs BEFORE the transaction: MySQL TRUNCATE is DDL
        // and commits implicitly, so inside the transaction it silently ended it
        // and the final commit failed with "There is no active transaction"
        // (exit 1 after the data was written). Creation stays in one transaction
        // so a mid-run failure leaves the tables empty, never partially seeded.
        $this->clearCatalog();
        DB::connection(GP247_DB_CONNECTION)->transaction(function () {
            $this->seed();
        });

        return $this->respondSuccess(['msg' => 'Created sample data successfully!']);
    }

    /**
     * Empty the catalog tables the sample replaces.
     *
     * Runs outside any transaction on purpose: TRUNCATE commits implicitly on MySQL.
     *
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function clearCatalog(): void
    {
        $this->info('Clearing existing data...');
        foreach ([
            'shop_category_description', 'shop_category', 'shop_brand', 'shop_supplier', 'shop_product',
            'shop_product_description', 'shop_product_category', 'shop_product_promotion',
            'shop_attribute_group', 'shop_product_attribute',
        ] as $table) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.$table)->truncate();
        }
    }

    /**
     * Populate the store with demo catalog data.
     *
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function seed(): void
    {
        $this->info('Creating sample categories...');
        $leafCategories = $this->createCategories();

        $this->info('Creating sample brands...');
        $brandIds = $this->createBrands();

        $this->info('Creating sample suppliers...');
        $this->createSuppliers();

        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'shop_attribute_group')->insert([
            'name' => 'Color',
            'status' => 1,
            'sort' => 0,
            'type' => 'radio',
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $this->info('Creating sample products...');
        $singleImages = $this->createSingleProducts($leafCategories, $brandIds);

        $categoryIds = array_column($leafCategories, 'id');
        $this->createProductBundle($singleImages, $categoryIds);
        $this->createProductGroup($singleImages, $categoryIds);

        $this->info('Created sample data successfully!');
    }

    /**
     * Create the root categories and their subcategories from the sample catalog.
     *
     * @return array<int, array{id: string, products: array<int, array{image: int, names: array<string, string>}>}>
     *         Subcategories in catalog order, each with its id and the products it holds.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createCategories(): array
    {
        $leaves = [];
        foreach (ShopSampleCatalog::categories() as $rootSort => $root) {
            $rootId = $this->createCategory($root, '', 1, $rootSort);
            foreach ($root['children'] as $childSort => $child) {
                $leaves[] = [
                    'id' => $this->createCategory($child, $rootId, 0, $childSort + 1),
                    'products' => $child['products'],
                ];
            }
        }

        return $leaves;
    }

    /**
     * Create one category row with its vi/en/ja descriptions.
     *
     * @param array<string, mixed> $category Catalog entry: alias, image, descriptions.
     * @param string               $parent   Parent category id, '' for a root category.
     * @param int                  $top      1 to show the category in the top menu.
     * @param int                  $sort     Sort order among its siblings.
     * @return string Id of the created category.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createCategory(array $category, string $parent, int $top, int $sort): string
    {
        $id = gp247_generate_id();
        ShopCategory::create([
            'id' => $id,
            'alias' => $category['alias'],
            'image' => $this->catalogImage('cate', $category['image']),
            'parent' => $parent,
            'top' => $top,
            'sort' => $sort,
            'status' => 1,
            // WHY: 1-1 ownership — the category owns its store via store_id column.
            'store_id' => GP247_STORE_ID_ROOT,
        ]);
        foreach ($category['descriptions'] as $lang => $description) {
            ShopCategoryDescription::create([
                'category_id' => $id,
                'lang' => $lang,
                'name' => $description['name'],
                'keyword' => $description['keyword'],
                'description' => $description['description'],
            ]);
        }

        return $id;
    }

    /**
     * Create the sample brands.
     *
     * @return array<int, string> Ids of the created brands.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createBrands(): array
    {
        $ids = [];
        foreach (ShopSampleCatalog::brands() as $brand) {
            $ids[] = $id = gp247_generate_id();
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'shop_brand')->insert([
                'id' => $id,
                'name' => $brand['name'],
                'alias' => $brand['alias'],
                'image' => $this->catalogImage('brand', $brand['image']),
                'url' => $brand['url'],
                'status' => 1,
                'sort' => 0,
            ]);
        }

        return $ids;
    }

    /**
     * Create the sample suppliers.
     *
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createSuppliers(): void
    {
        foreach (ShopSampleCatalog::suppliers() as $supplier) {
            DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX.'shop_supplier')->insert([
                'id' => gp247_generate_id(),
                'name' => $supplier['name'],
                'alias' => $supplier['alias'],
                'email' => $supplier['email'],
                'phone' => $supplier['phone'],
                'image' => $this->catalogImage('supplier', $supplier['image']),
                'address' => $supplier['address'],
                'url' => $supplier['url'],
                'status' => 1,
                'store_id' => GP247_STORE_ID_ROOT,
                'sort' => 0,
            ]);
        }
    }

    /**
     * Create the single products of every subcategory, each with the photo that shows it.
     *
     * SKU `SAMPLE-<subcategory index>-<position>` and alias `sample-product-<n>` keep the
     * pattern E2E journeys look up (SAMPLE-0-1, SAMPLE-2-1, sample-product-1).
     *
     * @param array<int, array{id: string, products: array<int, array{image: int, names: array<string, string>}>}> $leafCategories
     * @param array<int, string> $brandIds Ids of the sample brands.
     * @return array<string, string> Photo URL of every created product, keyed by product id.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createSingleProducts(array $leafCategories, array $brandIds): array
    {
        $images = [];
        $productNumber = 0;
        foreach ($leafCategories as $categoryKey => $category) {
            foreach ($category['products'] as $position => $product) {
                $productNumber++;
                $productId = gp247_generate_id();
                $images[$productId] = $this->productImage($product['image']);
                // WHY rand up to count(): the extra value leaves some products without a brand.
                $brandPick = rand(0, count($brandIds));
                $price = rand(100, 500);

                ShopProduct::create($this->productRow([
                    'id' => $productId,
                    'sku' => 'SAMPLE-' . $categoryKey . '-' . ($position + 1),
                    'alias' => 'sample-product-' . $productNumber,
                    'image' => $images[$productId],
                    'brand_id' => $brandIds[$brandPick] ?? null,
                    'price' => $price,
                    'kind' => 0, // Single product
                    'created_at' => Carbon::now()->format('Y-m-d 00:00:00'),
                    'updated_at' => Carbon::now()->format('Y-m-d 00:00:00'),
                ]));
                $this->createProductDescriptions($productId, $product['names'], 'sample, product');
                ShopProductCategory::create(['product_id' => $productId, 'category_id' => $category['id']]);

                // First 2 products of each subcategory get a 20% promotion.
                if ($position < 2) {
                    ShopProductPromotion::create([
                        'product_id' => $productId,
                        'price_promotion' => floor($price * 0.8),
                        'date_start' => Carbon::now()->format('Y-m-d H:i:s'),
                        'date_end' => Carbon::now()->addMonths(2)->format('Y-m-d H:i:s'),
                    ]);
                }
            }
        }

        return $images;
    }

    /**
     * Fill the columns every sample product shares around the given specific ones.
     *
     * @param array<string, mixed> $specific Columns that differ per product.
     * @return array<string, mixed> Complete product row.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function productRow(array $specific): array
    {
        return array_merge([
            // WHY: 1-1 ownership — product owns its store via store_id column.
            'store_id' => GP247_STORE_ID_ROOT,
            'brand_id' => null,
            'supplier_id' => null,
            'cost' => 0,
            'stock' => 100,
            'sold' => 0,
            'minimum' => 1,
            'weight_class' => 'kg',
            'weight' => 1,
            'length_class' => 'cm',
            'length' => 10,
            'width' => 10,
            'height' => 10,
            'product_type' => 0, // Physical product
            'tax_id' => 0,
            'status' => 1,
            'sort' => 0,
            'view' => 0,
            'date_available' => Carbon::now()->format('Y-m-d H:i:s'),
            'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
        ], $specific);
    }

    /**
     * Create the vi/en/ja descriptions of a sample product.
     *
     * @param string                $productId Product id.
     * @param array<string, string> $names     Product name per language.
     * @param string                $keyword   Keyword stored for every language.
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createProductDescriptions(string $productId, array $names, string $keyword): void
    {
        foreach ($names as $lang => $name) {
            ShopProductDescription::create([
                'product_id' => $productId,
                'lang' => $lang,
                'name' => $name,
                'keyword' => $keyword,
                'description' => str_replace(':name', $name, self::SAMPLE_SHORT_DESCRIPTION[$lang]),
                'content' => self::SAMPLE_CONTENT[$lang],
            ]);
        }
    }

    /**
     * URL of one hosted sample product photo.
     *
     * @param int $number Photo number, 1 … ShopSampleCatalog::PRODUCT_IMAGE_COUNT.
     * @return string Absolute URL of the photo.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function productImage(int $number): string
    {
        return self::PRODUCT_IMAGE_BASE . $number . '.png';
    }

    /**
     * URL of one hosted photo of a catalog kind (category, brand or supplier).
     *
     * @param string $kind   Photo set: 'cate', 'brand' or 'supplier'.
     * @param int    $number Photo number, 1 … ShopSampleCatalog::CATALOG_IMAGE_COUNT.
     * @return string Absolute URL of the photo.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function catalogImage(string $kind, int $number): string
    {
        return self::CATALOG_IMAGE_BASE . $kind . '/' . $kind . '-' . $number . '.png';
    }

    /**
     * Pick two distinct single products at random.
     *
     * @param array<string, string> $singleImages Photo URL of every single product, keyed by id.
     * @return array<int, string> Ids of the two picked products.
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function pickTwoSingles(array $singleImages): array
    {
        $ids = array_keys($singleImages);
        shuffle($ids);

        return array_slice($ids, 0, 2);
    }

    /**
     * Create 3 bundle products, each built from 2 random single products.
     *
     * @param array<string, string> $singleImages Photo URL of every single product, keyed by id.
     * @param array<int, string>    $categoryIds  Ids of the subcategories.
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createProductBundle(array $singleImages, array $categoryIds): void
    {
        $this->info('Creating product bundles...');
        for ($i = 1; $i <= 3; $i++) {
            $productId = gp247_generate_id();
            $parts = $this->pickTwoSingles($singleImages);
            $product = ShopProduct::create($this->productRow([
                'id' => $productId,
                'sku' => 'SAMPLE-BUNDLE-' . $i,
                'alias' => 'sample-bundle-' . $i,
                // WHY the first part's photo: a bundle has no photo of its own, its parts do.
                'image' => $singleImages[$parts[0]],
                'price' => rand(500, 1000),
                'kind' => GP247_PRODUCT_BUILD,
            ]));
            $this->createProductDescriptions($productId, [
                'vi' => 'Combo mẫu ' . $i,
                'en' => 'Sample bundle ' . $i,
                'ja' => 'セット商品 ' . $i,
            ], 'sample, bundle');
            ShopProductCategory::create([
                'product_id' => $productId,
                'category_id' => $categoryIds[array_rand($categoryIds)],
            ]);

            $builds = [];
            foreach ($parts as $partId) {
                $builds[$partId] = new ShopProductBuild(['product_id' => $partId, 'quantity' => 1]);
            }
            $product->builds()->saveMany($builds);
        }
    }

    /**
     * Create 3 group products, each grouping 2 random single products.
     *
     * @param array<string, string> $singleImages Photo URL of every single product, keyed by id.
     * @param array<int, string>    $categoryIds  Ids of the subcategories.
     * @return void
     *
     * @aidlc-unit system-cli
     * @aidlc-story US-CLI-005
     */
    private function createProductGroup(array $singleImages, array $categoryIds): void
    {
        $this->info('Creating product groups...');
        for ($i = 1; $i <= 3; $i++) {
            $productId = gp247_generate_id();
            $members = $this->pickTwoSingles($singleImages);
            $product = ShopProduct::create($this->productRow([
                'id' => $productId,
                'sku' => 'SAMPLE-GROUP-' . $i,
                'alias' => 'sample-group-' . $i,
                // WHY the first member's photo: a group has no photo of its own, its members do.
                'image' => $singleImages[$members[0]],
                'price' => 0,
                'kind' => GP247_PRODUCT_GROUP,
            ]));
            $this->createProductDescriptions($productId, [
                'vi' => 'Nhóm sản phẩm mẫu ' . $i,
                'en' => 'Sample product group ' . $i,
                'ja' => 'グループ商品 ' . $i,
            ], 'sample, group');
            ShopProductCategory::create([
                'product_id' => $productId,
                'category_id' => $categoryIds[array_rand($categoryIds)],
            ]);

            $groups = [];
            foreach ($members as $memberId) {
                $groups[$memberId] = new ShopProductGroup(['product_id' => $memberId]);
            }
            $product->groups()->saveMany($groups);
        }
    }
}
