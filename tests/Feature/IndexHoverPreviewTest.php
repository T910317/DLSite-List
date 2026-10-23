<?php

namespace Tests\Feature;

use App\Enums\ContentFocus;
use App\Enums\ProductContributorRole;
use App\Enums\ProductField;
use App\Enums\ProductFormat;
use App\Enums\ProductScore;
use App\Enums\UiLanguage;
use App\Livewire\IndexHoverPreviewSettings;
use App\Livewire\OptionsResetDefaults;
use App\Livewire\ProductIndex;
use App\Models\Genre;
use App\Models\Option;
use App\Models\Product;
use App\Support\ProductContributorSync;
use App\Support\ProductGenreSync;
use App\Support\ProductHoverPreview;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class IndexHoverPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_defaults_off_and_supports_save_individual_reset_and_global_reset(): void
    {
        $this->assertFalse(Option::indexHoverPreviewEnabled());

        $this->get(route('options.index'))
            ->assertOk()
            ->assertSee('Hover Preview')
            ->assertSee('Show work details and sample images when hovering a cover');

        Livewire::test(IndexHoverPreviewSettings::class)
            ->assertSet('enabled', false)
            ->set('enabled', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Hover preview setting saved.');

        $this->assertTrue(Option::indexHoverPreviewEnabled());
        // The hover preview is independent from the Image Viewer setting.
        $this->assertFalse(Option::indexImageViewerEnabled());

        Livewire::test(IndexHoverPreviewSettings::class)
            ->assertSet('enabled', true)
            ->call('askResetToDefault')
            ->call('resetToDefault')
            ->assertSet('enabled', false)
            ->assertSet('notice', 'Hover preview setting reset to default.');

        $this->assertDatabaseMissing('options', ['key' => Option::INDEX_HOVER_PREVIEW_ENABLED]);

        Option::setIndexHoverPreviewEnabled(true);

        Livewire::test(OptionsResetDefaults::class, ['activeTab' => 'general'])
            ->call('resetAll')
            ->assertRedirect(route('options.index', ['tab' => 'general']));

        $this->assertFalse(Option::indexHoverPreviewEnabled());
    }

    public function test_disabled_preview_renders_no_hover_markup_and_rejects_the_action(): void
    {
        $product = Product::factory()->create([
            'id' => 'RJ200000001',
            'work_name' => 'HOVER_DISABLED_WORK',
        ]);

        Livewire::test(ProductIndex::class)
            ->assertSee('HOVER_DISABLED_WORK')
            ->assertDontSee('data-index-hover-preview', false)
            ->assertDontSee('index-hover-preview', false);

        $this->get(route('index'))
            ->assertOk()
            ->assertDontSee('scripts/index-hover-preview.js', false);

        $action = Livewire::test(ProductIndex::class)
            ->call('hoverPreview', $product->id)
            ->assertReturned(null);

        $this->assertSame(404, data_get($action->effects, 'returnsMeta.0.status'));
    }

    public function test_enabled_preview_keeps_the_dlsite_cover_link_and_adds_hover_markup(): void
    {
        Option::setIndexHoverPreviewEnabled(true);

        $product = Product::factory()->create([
            'id' => 'RJ200000002',
            'work_name' => 'HOVER_LINK_WORK',
            'work_image' => 'storage/Works/RJ200000002/cover.jpg',
        ]);

        $component = Livewire::test(ProductIndex::class);
        $xpath = $this->htmlXPath($component->html());

        $cover = $xpath->query('//tr[@id="RJ200000002"]//a[contains(@class, "index-cover-thumbnail")]');
        $this->assertCount(1, $cover);
        $this->assertSame($product->dlsiteWorkUrl(false), $cover->item(0)->getAttribute('href'));
        $this->assertSame('RJ200000002', $cover->item(0)->getAttribute('data-index-hover-preview'));

        $toggle = $xpath->query('//tr[@id="RJ200000002"]//button[@data-index-hover-preview-toggle="RJ200000002"]');
        $this->assertCount(1, $toggle);
        $this->assertSame('index-hover-preview', $toggle->item(0)->getAttribute('aria-controls'));
        $this->assertSame('false', $toggle->item(0)->getAttribute('aria-expanded'));

        $card = $xpath->query('//*[@id="index-hover-preview"]');
        $this->assertCount(1, $card);
        $this->assertSame('dialog', $card->item(0)->getAttribute('role'));
        $this->assertSame('false', $card->item(0)->getAttribute('aria-modal'));
        $this->assertSame('Scroll to change images', $card->item(0)->getAttribute('data-label-scroll'));
        $this->assertTrue($card->item(0)->hasAttribute('hidden'));
        $this->assertSame('Work code', $card->item(0)->getAttribute('data-label-code'));

        $this->get(route('index'))
            ->assertOk()
            ->assertSee('scripts/index-hover-preview.js', false)
            ->assertDontSee('scripts/index-image-viewer.js', false);
    }

    public function test_preview_works_alongside_the_image_viewer_button(): void
    {
        Option::setIndexHoverPreviewEnabled(true);
        Option::setIndexImageViewerEnabled(true);

        Product::factory()->create([
            'id' => 'RJ200000003',
            'work_name' => 'HOVER_VIEWER_WORK',
        ]);

        $xpath = $this->htmlXPath(Livewire::test(ProductIndex::class)->html());
        $trigger = $xpath->query('//tr[@id="RJ200000003"]//button[contains(@class, "index-image-viewer-trigger")]');

        $this->assertCount(1, $trigger);
        $this->assertSame('RJ200000003', $trigger->item(0)->getAttribute('data-index-image-viewer-product'));
        $this->assertSame('RJ200000003', $trigger->item(0)->getAttribute('data-index-hover-preview'));
        $this->assertSame('index-image-viewer-dialog', $trigger->item(0)->getAttribute('aria-controls'));
    }

    public function test_hidden_image_column_does_not_render_hover_markup(): void
    {
        Option::setIndexHoverPreviewEnabled(true);
        Option::setIndexFieldLayout([
            ['field' => ProductField::Image->value, 'visible' => false],
        ]);

        Product::factory()->create([
            'id' => 'RJ200000004',
            'work_name' => 'HOVER_NO_IMAGE_COLUMN',
        ]);

        Livewire::test(ProductIndex::class)
            ->assertSee('HOVER_NO_IMAGE_COLUMN')
            ->assertDontSee('data-index-hover-preview', false)
            ->assertDontSee('id="index-hover-preview"', false);
    }

    public function test_preview_returns_saved_details_even_when_their_columns_are_hidden(): void
    {
        Storage::fake('public');
        Option::setIndexHoverPreviewEnabled(true);
        Option::setIndexFieldLayout([
            ['field' => ProductField::Circle->value, 'visible' => false],
            ['field' => ProductField::Brand->value, 'visible' => false],
            ['field' => ProductField::ProductFormat->value, 'visible' => false],
            ['field' => ProductField::Tags->value, 'custom_visible' => false, 'fetched_visible' => false],
            ['field' => ProductField::DescriptionJapanese->value, 'visible' => false],
            ['field' => ProductField::DescriptionEnglish->value, 'visible' => false],
        ]);

        $samples = [
            'storage/Works/RJ200000005/sample_1.jpg',
            'storage/Works/RJ200000005/sample_2.png',
        ];
        $product = Product::factory()->create([
            'id' => 'RJ200000005',
            'work_name' => 'ホバー作品',
            'work_name_english' => 'Hover Work',
            'circle' => 'Fallback Circle',
            'brand' => 'Hover Brand',
            'product_format' => [ProductFormat::Adventure->value],
            'description' => str_repeat('あ', 300),
            'description_english' => 'English summary',
            'work_image' => 'storage/Works/RJ200000005/cover.jpg',
            'sample_images' => $samples,
        ]);

        foreach ($samples as $path) {
            Storage::disk('public')->put(substr($path, strlen('storage/')), 'image');
        }

        app(ProductContributorSync::class)->sync($product, [
            ProductContributorRole::Circle->value => ['Hover Circle'],
        ]);

        $visible = Genre::query()->create(['title' => 'HOVER_VISIBLE_TAG', 'description' => null]);
        $hidden = Genre::query()->create([
            'title' => 'HOVER_HIDDEN_TAG',
            'description' => null,
            'hidden_on_index' => true,
        ]);
        app(ProductGenreSync::class)->syncCustom($product, [$visible->getKey(), $hidden->getKey()]);

        $preview = Livewire::test(ProductIndex::class)
            ->call('hoverPreview', $product->id)
            ->effects['returns'][0];

        $this->assertSame('RJ200000005', $preview['id']);
        $this->assertSame('ホバー作品', $preview['title']);
        $this->assertSame('Hover Work', $preview['alternateTitle']);
        $this->assertSame(['Hover Circle', 'Hover Brand'], $preview['makers']);
        $this->assertSame([ProductFormat::Adventure->label()], $preview['formats']);
        $this->assertSame(['HOVER_VISIBLE_TAG'], $preview['tags']);
        // English UI prefers the saved English description; nothing is translated.
        $this->assertSame('English summary', $preview['description']);
        // Preview pages start with the cover, followed by the saved sample images.
        $this->assertCount(3, $preview['images']);
        $this->assertStringEndsWith('storage/Works/RJ200000005/cover.jpg', $preview['images'][0]);
        $this->assertStringContainsString('sample_1.jpg?v=', $preview['images'][1]);
        $this->assertStringContainsString('sample_2.png?v=', $preview['images'][2]);

        Option::setUiLanguage(UiLanguage::Japanese);
        app()->setLocale(UiLanguage::Japanese->value);

        $japanese = app(ProductHoverPreview::class)->build($product->id, Option::productIndexSettings());

        $this->assertSame(
            Str::limit(str_repeat('あ', 300), ProductHoverPreview::DESCRIPTION_EXCERPT_LENGTH),
            $japanese['description'],
        );
    }

    public function test_preview_omits_missing_details_and_uses_the_plain_circle_fallback(): void
    {
        Option::setIndexHoverPreviewEnabled(true);

        Product::factory()->create([
            'id' => 'RJ200000006',
            'work_name' => 'SPARSE_WORK',
            'work_name_english' => null,
            'circle' => 'Only Circle',
            'publisher' => null,
            'brand' => null,
            'product_format' => null,
            'description' => null,
            'description_english' => '   ',
            'work_image' => null,
            'sample_images' => null,
        ]);

        $preview = Livewire::test(ProductIndex::class)
            ->call('hoverPreview', 'RJ200000006')
            ->effects['returns'][0];

        $this->assertNull($preview['alternateTitle']);
        $this->assertSame(['Only Circle'], $preview['makers']);
        $this->assertSame([], $preview['formats']);
        $this->assertSame([], $preview['tags']);
        $this->assertNull($preview['description']);
        $this->assertNull($preview['score']);
        $this->assertNull($preview['scoreLabel']);
        $this->assertSame([], $preview['images']);
    }

    public function test_preview_returns_the_own_score_with_the_content_focus_label(): void
    {
        Option::setIndexHoverPreviewEnabled(true);
        Option::setContentFocus(ContentFocus::General);

        Product::factory()->create([
            'id' => 'RJ200000009',
            'work_name' => 'SCORED_WORK',
            'score' => ProductScore::Six->value,
        ]);
        Product::factory()->create([
            'id' => 'RJ200000010',
            'work_name' => 'TOP_SCORED_WORK',
            'score' => ProductScore::Ten->value,
        ]);

        $preview = Livewire::test(ProductIndex::class)
            ->call('hoverPreview', 'RJ200000009')
            ->effects['returns'][0];

        // The saved string score is returned as a number so the card can draw 3 of 5 stars.
        $this->assertSame(6, $preview['score']);
        $this->assertSame('(6) Fine', $preview['scoreLabel']);

        $top = app(ProductHoverPreview::class)->build('RJ200000010', Option::productIndexSettings());

        $this->assertSame(10, $top['score']);
        $this->assertSame('(10) Masterpiece', $top['scoreLabel']);

        // The label follows the selected content focus wording, like the score column does.
        Option::setContentFocus(ContentFocus::Listening);
        app()->forgetScopedInstances();

        $listening = app(ProductHoverPreview::class)->build('RJ200000009', Option::productIndexSettings());

        $this->assertSame(6, $listening['score']);
        $this->assertSame('(6) Nice', $listening['scoreLabel']);
    }

    public function test_preview_query_count_does_not_grow_with_the_library(): void
    {
        Option::setIndexHoverPreviewEnabled(true);

        foreach (range(1, 40) as $number) {
            $product = Product::factory()->create([
                'id' => 'RJ3' . str_pad((string) $number, 8, '0', STR_PAD_LEFT),
            ]);
            $genre = Genre::query()->create(['title' => "BULK_TAG_{$number}", 'description' => null]);
            app(ProductGenreSync::class)->syncCustom($product, [$genre->getKey()]);
        }

        $component = Livewire::test(ProductIndex::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $preview = app(ProductHoverPreview::class)->build('RJ300000007', Option::productIndexSettings());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(['BULK_TAG_7'], $preview['tags']);
        $this->assertLessThanOrEqual(6, count($queries));

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('limit 40', strtolower($query['query']));
        }

        $component->call('hoverPreview', 'RJ300000040')->assertHasNoErrors();
    }

    public function test_preview_labels_follow_the_ui_language(): void
    {
        Option::setIndexHoverPreviewEnabled(true);
        Option::setUiLanguage(UiLanguage::TraditionalChinese);

        Product::factory()->create(['id' => 'RJ200000008', 'work_name' => '作品名稱不翻譯']);

        $this->get(route('index'))
            ->assertOk()
            ->assertSee('data-label-code="作品代碼"', false)
            ->assertSee('data-label-makers="社團／品牌"', false)
            ->assertSee('data-label-description="簡介摘要"', false)
            ->assertSee('data-label-score="分數"', false)
            ->assertSee('data-label-rating=":value / :max 分"', false)
            ->assertSee('顯示 作品名稱不翻譯 的作品預覽', false);

        $this->get(route('options.index'))
            ->assertOk()
            ->assertSee('懸浮預覽')
            ->assertSee('儲存懸浮預覽設定');
    }

    private function htmlXPath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }
}
