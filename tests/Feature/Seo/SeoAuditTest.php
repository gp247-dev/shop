<?php

namespace GP247\Shop\Tests\Feature\Seo;

use GP247\Front\Admin\Livewire\SeoAuditScreen;
use GP247\Front\Library\SeoAudit;
use GP247\Front\Library\SeoContentMeta;
use GP247\Front\Models\FrontRedirect;
use GP247\Front\Models\FrontSeoMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Front\Testing\ConfiguresSeoStore;
use GP247\Shop\Tests\Feature\ShopAdmin\Support\MakesShopProducts;
use GP247\Shop\Tests\TestCase;

/**
 * The SEO check finds what loses rankings — blocked pages, redirect loops, missing
 * descriptions — and links each example to the screen that fixes it
 * (SEO phase 3).
 *
 * @aidlc-unit seo
 * @aidlc-story US-SEO-audit
 * @aidlc-adr ADR-seo-audit
 */
class SeoAuditTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;
    use MakesShopProducts;
    use ConfiguresSeoStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetSeoConfigCache();
    }

    public function test_a_product_marked_noindex_is_listed_to_fix_with_a_link_to_its_edit_screen(): void
    {
        $before = $this->findingCount('content_noindex');
        $product = $this->makeProduct('ZZ Audit Noindex');
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        $run = SeoAudit::run($this->storeId());

        $this->assertSame($before + 1, $run['findings']['content_noindex']['count']);
        $this->assertSame(SeoAudit::LEVEL_FIX, $run['findings']['content_noindex']['level']);
        $ids = array_column($run['findings']['content_noindex']['examples'], 'id');
        $this->assertContains((string) $product->id, $ids);
        $example = $run['findings']['content_noindex']['examples'][array_search((string) $product->id, $ids, true)];
        $this->assertStringContainsString((string) $product->id, $example['url']);
    }

    public function test_content_without_any_description_is_listed_to_review(): void
    {
        $before = $this->findingCount('missing_description');
        $this->makeProduct('ZZ Audit No Description');
        $withSeo = $this->makeProduct('ZZ Audit Seo Description');
        SeoContentMeta::save('product', $withSeo->id, ['desc' => [$this->defaultLang() => ['description' => 'ZZ own description']]]);

        $this->assertSame($before + 1, $this->findingCount('missing_description'));
    }

    public function test_two_products_with_the_same_title_are_listed_as_duplicates(): void
    {
        $before = $this->findingCount('duplicate_title');
        $name = 'ZZ Audit Same Title ' . uniqid();
        $this->makeProduct($name);
        $this->makeProduct($name);

        $this->assertSame($before + 2, $this->findingCount('duplicate_title'));
    }

    public function test_redirect_chains_and_loops_are_found(): void
    {
        $chains = $this->findingCount('redirect_chain');
        $loops = $this->findingCount('redirect_loop');
        $u = '/zz-audit-' . uniqid();
        $this->redirect($u . '-a', $u . '-b');
        $this->redirect($u . '-b', $u . '-c');
        $this->redirect($u . '-x', $u . '-y');
        $this->redirect($u . '-y', $u . '-x');

        $run = SeoAudit::run($this->storeId());

        $this->assertGreaterThanOrEqual($chains + 1, $run['findings']['redirect_chain']['count'] ?? 0);
        $this->assertSame($loops + 1, $run['findings']['redirect_loop']['count'] ?? 0);
    }

    public function test_a_canonical_on_another_domain_is_listed_to_fix(): void
    {
        $before = $this->findingCount('canonical_offsite');
        $product = $this->makeProduct('ZZ Audit Canonical');
        SeoContentMeta::save('product', $product->id, ['desc' => [$this->defaultLang() => ['canonical' => 'https://other-shop.example.test/x']]]);

        $this->assertSame($before + 1, $this->findingCount('canonical_offsite'));
    }

    public function test_a_canonical_on_the_same_domain_with_or_without_www_is_not_listed(): void
    {
        $host = (string) parse_url(\GP247\Front\Library\IndexNow::baseUrl($this->storeId()), PHP_URL_HOST);
        $bare = preg_replace('/^www\./', '', strtolower($host));
        $before = $this->findingCount('canonical_offsite');
        $lang = $this->defaultLang();
        SeoContentMeta::save('product', $this->makeProduct('ZZ Audit WWW')->id, ['desc' => [$lang => ['canonical' => 'https://www.' . $bare . '/a']]]);
        SeoContentMeta::save('product', $this->makeProduct('ZZ Audit Bare')->id, ['desc' => [$lang => ['canonical' => 'https://' . $bare . '/b']]]);

        $this->assertSame($before, $this->findingCount('canonical_offsite'));
    }

    public function test_a_robots_file_that_blocks_the_whole_site_is_listed_to_fix(): void
    {
        $this->setSeoConfig('seo.robots_txt', "User-agent: *\nDisallow: /");
        $this->assertArrayHasKey('robots_blocks_site', SeoAudit::run(config('app.storeId'))['findings']);

        $this->setSeoConfig('seo.robots_txt', "User-agent: *\nDisallow: /cart");
        $this->assertArrayNotHasKey('robots_blocks_site', SeoAudit::run(config('app.storeId'))['findings']);
    }

    public function test_seo_rows_of_deleted_content_are_counted_and_cleaned(): void
    {
        $before = $this->findingCount('orphan_seo_rows');
        $meta = FrontSeoMeta::create(['type' => 'product', 'content_id' => 'zz-gone-' . uniqid(), 'noindex' => 1, 'nofollow' => 0]);
        $this->assertSame($before + 1, $this->findingCount('orphan_seo_rows'));

        $removed = SeoAudit::cleanOrphans();

        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertNull(FrontSeoMeta::find($meta->id));
    }

    public function test_orphan_rows_beyond_one_delete_batch_are_all_cleaned(): void
    {
        $prefix = 'zz-gone-batch-' . uniqid() . '-';
        $now = now();
        $rows = [];
        for ($i = 0; $i < 1005; $i++) {
            $rows[] = ['type' => 'product', 'content_id' => $prefix . $i, 'noindex' => 1, 'nofollow' => 0, 'created_at' => $now, 'updated_at' => $now];
        }
        FrontSeoMeta::insert($rows);

        $removed = SeoAudit::cleanOrphans();

        $this->assertGreaterThanOrEqual(1005, $removed);
        $this->assertSame(0, FrontSeoMeta::where('content_id', 'like', $prefix . '%')->count());
    }

    public function test_the_last_result_is_kept_for_the_store_with_its_time(): void
    {
        $run = SeoAudit::run($this->storeId());

        $latest = SeoAudit::latest($this->storeId());
        $this->assertSame($run['checked_at'], $latest['checked_at']);
        $this->assertTrue($latest['complete']);
    }

    public function test_the_screen_runs_the_check_and_shows_findings_escaped(): void
    {
        $this->asUser(slugs: ['admin_seo_audit']);
        $this->useAdminStore();
        $product = $this->makeProduct('ZZ <b>Audit</b> Screen');
        SeoContentMeta::save('product', $product->id, ['noindex' => true]);

        Livewire::test(SeoAuditScreen::class)
            ->call('runAudit')
            ->assertSeeHtml('data-testid="seo-audit-finding-content_noindex"')
            ->assertSeeHtml('data-testid="seo-audit-checked-at"')
            ->assertDontSeeHtml('<b>Audit</b>');
    }

    public function test_the_browser_cannot_overwrite_the_shown_result(): void
    {
        $this->asUser(slugs: ['admin_seo_audit']);
        $this->useAdminStore();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(SeoAuditScreen::class)->set('result', ['findings' => []]);
    }

    public function test_the_screen_cleans_orphan_rows(): void
    {
        $this->asUser(slugs: ['admin_seo_audit']);
        $this->useAdminStore();
        $meta = FrontSeoMeta::create(['type' => 'product', 'content_id' => 'zz-gone-screen-' . uniqid(), 'noindex' => 1, 'nofollow' => 0]);

        Livewire::test(SeoAuditScreen::class)->call('cleanOrphans');

        $this->assertNull(FrontSeoMeta::find($meta->id));
    }

    /**
     * A redirect whose target returned 404 long ago (the page works now) is not reported:
     * the 404 log keeps rows for 90 days and a fixed page must not stay "to fix".
     */
    public function test_a_redirect_to_a_page_that_returned_404_long_ago_is_not_listed(): void
    {
        $this->setSeoConfig('seo.log_404', '1');
        $u = '/zz-audit-old404-' . uniqid();
        $this->redirect($u . '-from', $u . '-to');
        $this->notFound($u . '-to', now()->subDays(SeoAudit::RECENT_404_DAYS + 1));

        $examples = SeoAudit::run($this->storeId())['findings']['redirect_to_missing']['examples'] ?? [];

        $this->assertNotContains($u . '-from → ' . $u . '-to', array_column($examples, 'label'));
    }

    /**
     * A redirect whose target still returns 404 is reported, with the time of the last 404.
     */
    public function test_a_redirect_to_a_page_that_returns_404_now_is_listed_with_the_last_404_time(): void
    {
        $this->setSeoConfig('seo.log_404', '1');
        $u = '/zz-audit-new404-' . uniqid();
        $this->redirect($u . '-from', $u . '-to');
        $seen = now()->subHours(2)->startOfMinute();
        $this->notFound($u . '-to', $seen);

        $run = SeoAudit::run($this->storeId());
        $examples = $run['findings']['redirect_to_missing']['examples'] ?? [];
        $labels = array_column($examples, 'label');

        $this->assertContains($u . '-from → ' . $u . '-to', $labels);
        $this->assertSame(SeoAudit::LEVEL_FIX, $run['findings']['redirect_to_missing']['level']);
        $example = $examples[array_search($u . '-from → ' . $u . '-to', $labels, true)];
        $this->assertSame($seen->format('Y-m-d H:i'), $example['last_404'] ?? null);
    }

    /**
     * The finding is titled with what it checks now (a 404 in the last 7 days), in every language.
     */
    public function test_the_redirect_finding_has_a_title_about_recent_404s_in_three_languages(): void
    {
        $key = SeoAudit::labelKey('redirect_to_missing');
        $this->assertSame('admin.seo_audit.code_redirect_to_recent_404', $key);
        $this->assertSame('admin.seo_audit.code_redirect_loop', SeoAudit::labelKey('redirect_loop'));

        // The language seeder is what `gp247:update` runs on an existing site.
        (new \GP247\Front\Admin\Database\Seeders\DataFrontLanguageSeeder())->run();
        foreach ([$key, 'admin.seo_audit.last_404'] as $code) {
            $texts = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')
                ->where('code', $code)->pluck('text', 'location')->all();
            foreach (['en', 'vi', 'ja'] as $lang) {
                $this->assertNotEmpty($texts[$lang] ?? '', $code . ' in ' . $lang);
            }
        }
        $titles = DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'languages')
            ->where('code', $key)->pluck('text')->all();
        foreach ($titles as $title) {
            $this->assertStringContainsString('7', $title, 'the title names the 7 days');
        }
    }

    /**
     * Put a row in the 404 log of the store.
     *
     * @param string                     $path
     * @param \Illuminate\Support\Carbon $lastSeen
     * @return void
     */
    private function notFound(string $path, $lastSeen): void
    {
        DB::connection(GP247_DB_CONNECTION)->table(GP247_DB_PREFIX . 'front_not_found_log')->insert([
            'store_id' => (string) $this->storeId(), 'path' => $path, 'path_hash' => sha1($path), 'hits' => 3,
            'referer' => null, 'first_seen_at' => $lastSeen, 'last_seen_at' => $lastSeen,
        ]);
    }

    private function findingCount(string $code): int
    {
        return (int) (SeoAudit::run($this->storeId())['findings'][$code]['count'] ?? 0);
    }

    private function redirect(string $from, string $to): void
    {
        FrontRedirect::create(['from' => $from, 'to' => $to, 'code' => 301, 'store_id' => $this->storeId(), 'status' => 1]);
    }

    private function defaultLang(): string
    {
        return (string) (gp247_store_info('language', null, $this->storeId()) ?: config('app.locale'));
    }
}
