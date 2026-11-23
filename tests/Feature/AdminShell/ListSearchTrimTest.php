<?php

namespace GP247\Shop\Tests\Feature\AdminShell;

use GP247\Core\AdminShell\Http\Livewire\AdminLogList;
use GP247\Core\AdminShell\Http\Livewire\LanguageStringManager;
use GP247\Core\AdminShell\Http\Livewire\NoticeList;
use GP247\Core\Models\AdminLog;
use GP247\Core\Models\AdminNotice;
use GP247\Core\Models\AdminUser;
use GP247\Core\Models\Languages;
use GP247\Shop\Admin\Livewire\BrandManager;
use GP247\Shop\Models\ShopBrand;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use GP247\Core\Testing\InteractsWithAdminUser;
use GP247\Shop\Tests\TestCase;

/**
 * Spaces around a search keyword never change what an admin list finds: plain
 * spaces, tabs, the full-width space of Japanese input methods and the
 * non-breaking space of pasted text are ignored at both ends, a keyword of only
 * spaces does not filter, spaces between words are kept, and the search box keeps
 * exactly what the admin typed. Covers the two-panel base (ResourcePanel via
 * BrandManager), the one-column base (DataTableComponent via AdminLogList) and the
 * core screens that build their own search query (NoticeList, LanguageStringManager).
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-admin-shell-list-search-trim
 * @aidlc-adr ADR-005
 */
class ListSearchTrimTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithAdminUser;

    /** Token isolating this test's rows from the live data. */
    private const TOKEN = 'zqlsearch';

    /**
     * Seed brands whose names start with the token.
     *
     * @param string[] $names
     * @return void
     */
    private function seedBrands(array $names): void
    {
        foreach ($names as $name) {
            ShopBrand::create([
                'name' => $name, 'alias' => str_replace(' ', '-', $name), 'image' => 'path/seed.png', 'sort' => 0, 'status' => 1,
            ]);
        }
    }

    /**
     * Names shown in the list of a rendered component.
     *
     * @param Testable $component
     * @param string   $field Column holding the shown name.
     * @return string[]
     */
    private function listed(Testable $component, string $field = 'name'): array
    {
        return collect($component->viewData('rows')->items())->pluck($field)->map(fn ($v) => (string) $v)->all();
    }

    public function test_spaces_around_the_keyword_give_the_same_result_on_a_two_panel_screen(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands([self::TOKEN . '-alpha', self::TOKEN . '-beta']);

        $plain = $this->listed(Livewire::test(BrandManager::class)->set('keyword', self::TOKEN . '-alpha'));
        $padded = $this->listed(Livewire::test(BrandManager::class)->set('keyword', "  \t" . self::TOKEN . "-alpha \t "));

        $this->assertSame([self::TOKEN . '-alpha'], $plain);
        $this->assertSame($plain, $padded);
    }

    public function test_full_width_and_non_breaking_spaces_around_the_keyword_are_ignored(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands([self::TOKEN . '-alpha']);

        foreach (["\u{3000}" . self::TOKEN . "-alpha\u{3000}", "\u{00A0}" . self::TOKEN . "-alpha\u{00A0}", "\u{3000}\u{00A0} " . self::TOKEN . '-alpha'] as $keyword) {
            $rows = $this->listed(Livewire::test(BrandManager::class)->set('keyword', $keyword));
            $this->assertSame([self::TOKEN . '-alpha'], $rows, 'keyword ' . json_encode($keyword));
        }
    }

    public function test_a_keyword_of_only_spaces_does_not_filter_the_list(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands([self::TOKEN . '-alpha']);

        $all = Livewire::test(BrandManager::class)->viewData('rows')->total();
        $blank = Livewire::test(BrandManager::class)->set('keyword', " \u{3000}\u{00A0}\t ")->viewData('rows')->total();

        $this->assertSame($all, $blank);
    }

    public function test_spaces_between_words_are_kept_when_searching(): void
    {
        $this->asUser(slugs: ['admin_brand']);
        $this->seedBrands([self::TOKEN . ' two words', self::TOKEN . 'twowords']);

        $rows = $this->listed(Livewire::test(BrandManager::class)->set('keyword', '  ' . self::TOKEN . ' two words  '));

        $this->assertSame([self::TOKEN . ' two words'], $rows);
    }

    public function test_the_search_box_keeps_what_the_admin_typed(): void
    {
        $this->asUser(slugs: ['admin_brand']);

        Livewire::test(BrandManager::class)
            ->set('keyword', 'sample ')
            ->assertSet('keyword', 'sample ');
    }

    public function test_one_column_lists_and_screens_with_their_own_query_ignore_spaces_too(): void
    {
        // One-column base (DataTableComponent).
        $this->asUser(slugs: ['admin_log']);
        AdminLog::create(['user_id' => 'AU-' . self::TOKEN, 'path' => self::TOKEN . '/path', 'method' => 'GET', 'ip' => '10.9.8.7', 'user_agent' => 'phpunit', 'input' => '{}']);
        $logs = $this->listed(Livewire::test(AdminLogList::class)->set('keyword', "\u{3000}" . self::TOKEN . '/path '), 'path');
        $this->assertSame([self::TOKEN . '/path'], $logs);

        // NoticeList builds its own owner-scoped query.
        $this->asUser(slugs: ['admin_notice']);
        $owner = AdminUser::create([
            'id' => 'AU-' . self::TOKEN, 'username' => 'u_' . self::TOKEN, 'password' => bcrypt('secret'),
            'name' => 'Admin', 'email' => self::TOKEN . '@test.local', 'status' => 1,
        ]);
        $this->actingAs($owner, 'admin');
        AdminNotice::create([
            'type' => 'gp247_order_created', 'type_id' => '1', 'status' => 0, 'admin_id' => $owner->id,
            'admin_created' => 'AU-SYSTEM', 'content' => self::TOKEN . ' notice',
        ]);
        $notices = $this->listed(Livewire::test(NoticeList::class)->set('keyword', ' ' . self::TOKEN . " notice\u{00A0}"), 'content');
        $this->assertSame([self::TOKEN . ' notice'], $notices);

        // LanguageStringManager builds its own query over code/text.
        $this->asUser(slugs: ['admin_language']);
        Languages::create(['code' => 'admin.' . self::TOKEN . '.probe', 'text' => 'Probe', 'position' => 'admin.' . self::TOKEN, 'location' => 'en']);
        $codes = $this->listed(Livewire::test(LanguageStringManager::class)->set('keyword', "  admin." . self::TOKEN . ".probe\u{3000}"), 'code');
        $this->assertSame(['admin.' . self::TOKEN . '.probe'], $codes);
    }
}
