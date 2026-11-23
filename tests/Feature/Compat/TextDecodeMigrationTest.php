<?php

namespace GP247\Shop\Tests\Feature\Compat;

use GP247\Core\Support\StoredTextDecoder;
use GP247\Shop\Models\ShopBrand;
use GP247\Shop\Models\ShopProductDescription;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The upgrade migrations of core, front and shop give back, once, the text gp247_clean()
 * HTML-encoded on save before core 3.12: "Cron &amp; Queue" becomes "Cron & Queue" in the
 * listed plain-text columns, while language strings, rich content, secrets and JSON config
 * values stay as they are, and a second run changes nothing (flag).
 *
 * Runs the migrations' up() on rows created inside the test transaction (DML only, rolled
 * back); the one-time flags are cleared inside the same transaction first.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-text-stored-as-typed
 * @aidlc-adr compat-foundation_text-stored-as-typed
 */
class TextDecodeMigrationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesShopProducts;

    private const FLAGS = ['text_storage_decoded_core', 'text_storage_decoded_front', 'text_storage_decoded_shop'];

    private const MIGRATIONS = [
        'vendor/gp247/core/src/Database/Migrations/upgrade/2026_11_12_230000_decode_stored_text_core.php',
        'vendor/gp247/front/src/Admin/Database/Migrations/upgrade/2026_11_12_230100_decode_stored_text_front.php',
        'vendor/gp247/shop/src/Admin/Database/Migrations/upgrade/2026_11_12_230200_decode_stored_text_shop.php',
    ];

    /**
     * @return \Illuminate\Database\ConnectionInterface
     */
    private function db()
    {
        return DB::connection(GP247_DB_CONNECTION);
    }

    /**
     * Run up() of the three decode migrations.
     *
     * @return void
     */
    private function migrate(): void
    {
        foreach (self::MIGRATIONS as $path) {
            (require base_path($path))->up();
        }
    }

    /**
     * Insert an admin_config row inside the transaction and return its id.
     *
     * @param string $key
     * @param string $value
     * @param int $security
     * @return int
     */
    private function config(string $key, string $value, int $security = 0): int
    {
        return (int) $this->db()->table(GP247_DB_PREFIX . 'admin_config')->insertGetId([
            'group' => 'zz_decode', 'code' => 'zz_decode', 'key' => $key, 'value' => $value, 'security' => $security,
            'store_id' => (string) GP247_STORE_ID_GLOBAL, 'sort' => 0, 'detail' => '',
        ]);
    }

    /**
     * @param int $id
     * @return string
     */
    private function configValue(int $id): string
    {
        return (string) $this->db()->table(GP247_DB_PREFIX . 'admin_config')->where('id', $id)->value('value');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->db()->table(GP247_DB_PREFIX . 'admin_config')->whereIn('key', self::FLAGS)->delete();
    }

    public function test_text_encoded_on_save_is_given_back_as_typed_once(): void
    {
        $tag = 'zz' . uniqid();
        $brand = ShopBrand::create(['name' => 'Cron &amp; Queue &quot;' . $tag . '&quot;', 'alias' => $tag, 'status' => 1, 'store_id' => (string) GP247_STORE_ID_ROOT]);
        $product = $this->makeProduct('Free &amp; Pro &lt;' . $tag . '&gt;');
        $this->db()->table(GP247_DB_PREFIX . 'shop_product_description')->where('product_id', $product->id)
            ->update(['content' => '&lt;p&gt;rich&lt;/p&gt;']);
        $typedEntity = $this->config('zz_decode_typed_' . $tag, 'Use &amp;copy; for ' . $tag);
        $plain = $this->config('zz_decode_plain_' . $tag, 'a &amp; b');

        $this->migrate();

        $this->assertSame('Cron & Queue "' . $tag . '"', ShopBrand::find($brand->id)->name);
        $description = ShopProductDescription::where('product_id', $product->id)->first();
        $this->assertSame('Free & Pro <' . $tag . '>', $description->name, 'a composite-key description table is decoded too');
        $this->assertSame('&lt;p&gt;rich&lt;/p&gt;', $description->content, 'rich content is not touched');
        $this->assertSame('a & b', $this->configValue($plain));
        $this->assertSame('Use &copy; for ' . $tag, $this->configValue($typedEntity), 'an entity the person typed comes back as typed, not as ©');

        foreach (self::FLAGS as $flag) {
            $this->assertTrue(StoredTextDecoder::hasRun($flag), $flag);
        }

        // A second run (flag set) changes nothing, even text that looks encoded.
        ShopBrand::where('id', $brand->id)->update(['name' => 'Again &amp; again']);
        $this->migrate();
        $this->assertSame('Again &amp; again', ShopBrand::find($brand->id)->name);
    }

    public function test_language_strings_secrets_and_json_config_values_are_not_touched(): void
    {
        $tag = 'zz' . uniqid();
        $this->db()->table(GP247_DB_PREFIX . 'languages')->insert([
            'code' => 'zz.decode.' . $tag, 'text' => 'Tom &amp; Jerry', 'position' => 'zz', 'location' => 'en',
        ]);
        $secret = $this->config('zz_decode_secret_' . $tag, 'p&amp;ss', 1);
        $json = $this->config('zz_decode_json_' . $tag, '[&quot;a&quot;,&quot;b&quot;]');

        $this->migrate();

        $this->assertSame('Tom &amp; Jerry', $this->db()->table(GP247_DB_PREFIX . 'languages')->where('code', 'zz.decode.' . $tag)->value('text'));
        $this->assertSame('p&amp;ss', $this->configValue($secret));
        $this->assertSame('[&quot;a&quot;,&quot;b&quot;]', $this->configValue($json));
    }

    public function test_the_decoder_is_the_exact_inverse_of_gp247_clean(): void
    {
        foreach (['Cron & Queue', '5<10 > 3 "quoted" \'single\'', '&copy; typed', 'Café'] as $typed) {
            $this->assertSame($typed, StoredTextDecoder::decode((string) gp247_clean($typed)));
        }
    }
}
