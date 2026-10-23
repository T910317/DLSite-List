<?php

namespace Tests\Feature;

use App\Livewire\IndexEditionGroupingSettings;
use App\Livewire\OptionsResetDefaults;
use App\Livewire\ProductIndex;
use App\Models\Option;
use App\Models\Product;
use App\Support\EditionGroups;
use App\Support\ProductIndexFilters;
use App\Support\ProductIndexResults;
use App\Support\RelatedEditions;
use App\Support\ReturnTarget;
use App\Support\Transfers\PortableOptions;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class IndexEditionGroupingTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = 'RJ01647180';

    private const TRANSLATION = 'RJ01668754';

    public function test_setting_defaults_off_and_supports_save_reset_and_transfer(): void
    {
        $this->assertFalse(Option::indexGroupEditionsEnabled());
        $this->assertFalse(Option::productIndexSettings()->indexGroupEditionsEnabled);

        $this->get(route('options.index'))
            ->assertOk()
            ->assertSee('Edition Grouping')
            ->assertSee('Show other editions of a work as one Index row');

        Livewire::test(IndexEditionGroupingSettings::class)
            ->assertSet('enabled', false)
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertSet('notice', 'Edition grouping setting saved.');

        $this->assertTrue(Option::indexGroupEditionsEnabled());
        $this->assertTrue(Option::productIndexSettings()->indexGroupEditionsEnabled);

        Livewire::test(IndexEditionGroupingSettings::class)
            ->assertSet('enabled', true)
            ->call('askResetToDefault')
            ->assertSet('confirmingResetToDefault', true)
            ->call('resetToDefault')
            ->assertHasNoErrors()
            ->assertSet('enabled', false)
            ->assertSet('notice', 'Edition grouping setting reset to default.');

        $this->assertFalse(Option::indexGroupEditionsEnabled());
        $this->assertDatabaseMissing('options', ['key' => Option::INDEX_GROUP_EDITIONS_ENABLED]);

        Option::setIndexGroupEditionsEnabled(true);

        Livewire::test(OptionsResetDefaults::class, ['activeTab' => 'general'])
            ->call('resetAll')
            ->assertRedirect(route('options.index', ['tab' => 'general']));

        $this->assertFalse(Option::indexGroupEditionsEnabled());
        $this->assertDatabaseMissing('options', ['key' => Option::INDEX_GROUP_EDITIONS_ENABLED]);

        $portable = app(PortableOptions::class);
        $this->assertArrayHasKey(Option::INDEX_GROUP_EDITIONS_ENABLED, $portable->defaults());
        $this->assertFalse($portable->current(Option::INDEX_GROUP_EDITIONS_ENABLED));

        $portable->apply(Option::INDEX_GROUP_EDITIONS_ENABLED, true);
        $this->assertTrue(Option::indexGroupEditionsEnabled());
        $this->assertTrue($portable->all()[Option::INDEX_GROUP_EDITIONS_ENABLED]);

        $this->expectException(ValidationException::class);
        $portable->validate(Option::INDEX_GROUP_EDITIONS_ENABLED, 'yes');
    }

    public function test_disabled_setting_keeps_every_edition_as_its_own_row(): void
    {
        $this->createLanguagePair();

        $html = Livewire::test(ProductIndex::class)
            ->assertSee('Showing 1-2 of 2')
            ->html();

        $this->assertSame([self::TRANSLATION, self::ORIGINAL], $this->rowIds($html));
        $this->assertStringNotContainsString('index-editions', $html);
    }

    public function test_enabled_setting_shows_editions_on_the_original_row_with_a_language_badge(): void
    {
        $this->createLanguagePair();
        Option::setIndexGroupEditionsEnabled(true);

        $html = Livewire::test(ProductIndex::class)
            ->assertSee('Showing 1-1 of 1')
            ->html();

        $this->assertSame([self::ORIGINAL], $this->rowIds($html));
        $this->assertSame('Multiple languages', $this->badge($html, self::ORIGINAL));
        $this->assertSame([
            ['label' => 'Japanese', 'code' => self::ORIGINAL, 'current' => true],
            ['label' => 'Traditional Chinese', 'code' => self::TRANSLATION, 'current' => false],
        ], $this->editions($html, self::ORIGINAL));

        $links = $this->xpath($html)->query('//tr[@id="' . self::ORIGINAL . '"]//a[contains(@class, "index-editions__code")]');
        $this->assertCount(2, $links);
        $translationLink = $links->item(1);
        $this->assertInstanceOf(DOMElement::class, $translationLink);
        $this->assertSame(
            route('products.edit', ['product' => self::TRANSLATION, 'return_fragment' => self::ORIGINAL], false),
            $translationLink->getAttribute('href'),
        );
        $this->assertTrue($translationLink->hasAttribute('data-work-form-modal-link'));
        $this->assertSame('true', $links->item(0)?->getAttribute('aria-current'));
    }

    public function test_the_original_row_wins_over_a_lower_code_and_unknown_original_labels_fall_back(): void
    {
        $original = 'RJ02000000';
        $english = 'RJ01000000';
        $this->createProduct($original, 'LATE_ORIGINAL', [
            ['code' => $english, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
        ]);
        $this->createProduct($english, 'EARLY_TRANSLATION', [
            ['code' => $original, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
        ]);
        Option::setIndexGroupEditionsEnabled(true);

        $html = Livewire::test(ProductIndex::class)->html();

        $this->assertSame([$original], $this->rowIds($html));
        $this->assertSame([
            ['label' => 'Original', 'code' => $original, 'current' => true],
            ['label' => 'English', 'code' => $english, 'current' => false],
        ], $this->editions($html, $original));
    }

    public function test_versions_without_an_original_use_the_lowest_code_row_and_a_version_badge(): void
    {
        $windows = 'RJ01200000';
        $mac = 'RJ01100000';
        $this->createProduct($windows, 'WINDOWS_WORK', [
            ['code' => $mac, 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Mac版'],
        ]);
        $this->createProduct($mac, 'MAC_WORK', [
            ['code' => $windows, 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Windows版'],
        ]);
        Option::setIndexGroupEditionsEnabled(true);

        $html = Livewire::test(ProductIndex::class)->html();

        $this->assertSame([$mac], $this->rowIds($html));
        $this->assertSame('Multiple versions', $this->badge($html, $mac));
        $this->assertSame([
            ['label' => 'Mac版', 'code' => $mac, 'current' => true],
            ['label' => 'Windows版', 'code' => $windows, 'current' => false],
        ], $this->editions($html, $mac));
    }

    public function test_translations_of_an_original_outside_the_library_share_a_row(): void
    {
        $original = 'RJ01587488';
        $english = 'RJ01625050';
        $chinese = 'RJ01616206';
        $this->createProduct($english, 'ENGLISH_WORK', [
            ['code' => $original, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
        ]);
        $this->createProduct($chinese, 'CHINESE_WORK', [
            ['code' => $original, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
        ]);
        $this->createProduct('RJ01000001', 'UNRELATED_WORK');
        Option::setIndexGroupEditionsEnabled(true);

        $html = Livewire::test(ProductIndex::class)->html();

        $this->assertSame([$chinese, 'RJ01000001'], $this->rowIds($html));
        $this->assertSame('Multiple languages', $this->badge($html, $chinese));
        $this->assertSame([$chinese, $english], array_column($this->editions($html, $chinese), 'code'));
        $this->assertSame([], $this->editions($html, 'RJ01000001'));
    }

    public function test_search_that_matches_only_the_translation_shows_the_translation_row(): void
    {
        $this->createLanguagePair();
        Option::setIndexGroupEditionsEnabled(true);

        $html = Livewire::withQueryParams(['search' => 'CHINESE_ONLY'])
            ->test(ProductIndex::class)
            ->assertSee('Showing 1-1 of 1')
            ->html();

        $this->assertSame([self::TRANSLATION], $this->rowIds($html));
        $this->assertSame([
            ['label' => 'Japanese', 'code' => self::ORIGINAL, 'current' => false],
            ['label' => 'Traditional Chinese', 'code' => self::TRANSLATION, 'current' => true],
        ], $this->editions($html, self::TRANSLATION));

        Livewire::flushState();

        $html = Livewire::withQueryParams(['search' => 'SHARED_WORD'])
            ->test(ProductIndex::class)
            ->html();

        $this->assertSame([self::ORIGINAL], $this->rowIds($html));
    }

    public function test_pagination_counts_one_row_per_work(): void
    {
        $this->createLanguagePair();
        $this->createProduct('RJ01700001', 'UNRELATED_ONE');
        $this->createProduct('RJ01700002', 'UNRELATED_TWO');
        $this->createProduct('RJ01700003', 'UNRELATED_THREE');
        Option::setIndexPerPage(2);
        Option::setIndexGroupEditionsEnabled(true);

        Livewire::test(ProductIndex::class)
            ->assertSee('Showing 1-2 of 4')
            ->assertSee('UNRELATED_THREE')
            ->assertSee('UNRELATED_TWO')
            ->call('nextPage')
            ->assertSee('Showing 3-4 of 4')
            ->assertSee('UNRELATED_ONE')
            ->assertSee('SHARED_WORD ORIGINAL')
            ->assertDontSee('SHARED_WORD CHINESE_ONLY');

        $results = app(ProductIndexResults::class);
        $filters = ProductIndexFilters::fromQuery([]);
        $this->assertSame(2, $results->lastPage($filters, 2, [], editionGroups: EditionGroups::load()));
        $this->assertSame(3, $results->lastPage($filters, 2, []));
        $this->assertSame(4, $results->pageForProduct($filters, Product::findOrFail(self::TRANSLATION), 1, [], editionGroups: EditionGroups::load()));
        $this->assertSame(4, $results->pageForProduct($filters, Product::findOrFail(self::ORIGINAL), 1, [], editionGroups: EditionGroups::load()));
        $this->assertSame(5, $results->pageForProduct($filters, Product::findOrFail(self::ORIGINAL), 1, []));

        Livewire::flushState();
        Option::setIndexPerPage(Option::INDEX_PER_PAGE_UNLIMITED);

        Livewire::test(ProductIndex::class)->assertSee('Showing all 4 works');

        Livewire::flushState();
        Option::setIndexGroupEditionsEnabled(false);

        Livewire::test(ProductIndex::class)->assertSee('Showing all 5 works');
    }

    public function test_return_target_points_to_the_row_that_shows_an_edited_edition(): void
    {
        $this->createLanguagePair();
        $this->createProduct('RJ01650000', 'BETWEEN_ONE');
        $this->createProduct('RJ01660000', 'BETWEEN_TWO');
        Option::setIndexPerPage(2);
        $translation = Product::findOrFail(self::TRANSLATION);

        $ungrouped = (new ReturnTarget)->forProduct($translation);
        $this->assertSame([], $ungrouped->query);
        $this->assertSame(self::TRANSLATION, $ungrouped->fragment);

        Option::setIndexGroupEditionsEnabled(true);

        $grouped = (new ReturnTarget)->forProduct($translation);
        $this->assertSame(['page' => '2'], $grouped->query);
        $this->assertSame(self::ORIGINAL, $grouped->fragment);

        $searched = (new ReturnTarget(query: ['search' => 'CHINESE_ONLY']))->forProduct($translation);
        $this->assertSame(self::TRANSLATION, $searched->fragment);

        $this->assertSame(['page' => '3'], (new ReturnTarget(query: ['page' => '4']))->afterDeleting(perPage: 1, groupEditions: true)->query);
        $this->assertSame(['page' => '4'], (new ReturnTarget(query: ['page' => '4']))->afterDeleting(perPage: 1, groupEditions: false)->query);
    }

    public function test_query_count_does_not_grow_with_the_number_of_grouped_rows(): void
    {
        Option::setIndexPerPage(Option::INDEX_PER_PAGE_UNLIMITED);
        Option::setIndexGroupEditionsEnabled(true);
        $this->createLanguageGroups(1, 10);
        Livewire::test(ProductIndex::class); // warm options read once per request
        $tenGroups = $this->indexQueryCount();

        $this->createLanguageGroups(11, 30);
        $thirtyGroups = $this->indexQueryCount();

        $html = Livewire::test(ProductIndex::class)->html();
        $this->assertCount(30, $this->rowIds($html));
        $this->assertSame($tenGroups, $thirtyGroups);
    }

    public function test_badge_and_language_names_follow_the_ui_language(): void
    {
        $this->createLanguagePair();
        Option::setIndexGroupEditionsEnabled(true);
        Option::setUiLanguage('zh_TW');
        app()->setLocale('zh_TW');

        $html = Livewire::test(ProductIndex::class)->html();

        $this->assertSame('多語言', $this->badge($html, self::ORIGINAL));
        $this->assertSame(['日文', '繁中'], array_column($this->editions($html, self::ORIGINAL), 'label'));
    }

    private function createLanguagePair(): void
    {
        $this->createProduct(self::ORIGINAL, 'SHARED_WORD ORIGINAL', [
            ['code' => self::TRANSLATION, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '繁体中文'],
        ]);
        $this->createProduct(self::TRANSLATION, 'SHARED_WORD CHINESE_ONLY', [
            ['code' => self::ORIGINAL, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
            ['code' => self::ORIGINAL, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '日本語'],
        ]);
    }

    private function createLanguageGroups(int $from, int $to): void
    {
        foreach (range($from, $to) as $number) {
            $original = sprintf('RJ01%06d', $number * 2);
            $translation = sprintf('RJ01%06d', $number * 2 + 1);
            $this->createProduct($original, "GROUP_{$number}_ORIGINAL", [
                ['code' => $translation, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
            ]);
            $this->createProduct($translation, "GROUP_{$number}_ENGLISH", [
                ['code' => $original, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => '日本語'],
            ]);
        }
    }

    private function indexQueryCount(): int
    {
        Livewire::flushState();
        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ProductIndex::class);

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    private function createProduct(string $id, string $name, array $relatedEditions = []): Product
    {
        return Product::factory()->create([
            'id' => $id,
            'work_name' => $name,
            'related_editions' => $relatedEditions === [] ? null : $relatedEditions,
        ]);
    }

    /** @return list<string> */
    private function rowIds(string $html): array
    {
        $ids = [];

        foreach ($this->xpath($html)->query('//tr[contains(@class, "list-table-data")]') as $row) {
            $this->assertInstanceOf(DOMElement::class, $row);
            $ids[] = $row->getAttribute('id');
        }

        return $ids;
    }

    private function badge(string $html, string $row): ?string
    {
        $badge = $this->xpath($html)->query('//tr[@id="' . $row . '"]//*[contains(@class, "index-editions__badge")]')->item(0);

        return $badge === null ? null : trim($badge->textContent);
    }

    /** @return list<array{label: ?string, code: string, current: bool}> */
    private function editions(string $html, string $row): array
    {
        $xpath = $this->xpath($html);
        $editions = [];

        foreach ($xpath->query('//tr[@id="' . $row . '"]//li[contains(@class, "index-editions__item")]') as $item) {
            $this->assertInstanceOf(DOMElement::class, $item);
            $label = $xpath->query('.//*[contains(@class, "index-editions__label")]', $item)->item(0);
            $editions[] = [
                'label' => $label === null ? null : trim($label->textContent),
                'code' => trim((string) $xpath->query('.//a[contains(@class, "index-editions__code")]', $item)->item(0)?->textContent),
                'current' => str_contains($item->getAttribute('class'), 'index-editions__item--current'),
            ];
        }

        return $editions;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);

        return new DOMXPath($document);
    }
}
