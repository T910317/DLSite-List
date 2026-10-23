<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\RelatedEditions;
use App\Support\Refetch\RefetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RelatedEditionsTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGINAL = 'RJ01587488';

    private const ENGLISH = 'RJ01625050';

    private const CHINESE = 'RJ01616206';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Process::fake(['*' => Process::result(output: '{"failed_images":[]}')])->preventStrayProcesses();
    }

    public function test_import_stores_dlsite_editions_and_warns_about_an_edition_it_lists(): void
    {
        Product::factory()->create(['id' => self::ORIGINAL, 'work_name' => 'Original title']);
        $this->putScrapedWork(self::ENGLISH, [
            'translation_info' => ['is_child' => true, 'original_workno' => self::ORIGINAL, 'parent_workno' => self::ORIGINAL, 'lang' => 'ENG'],
            'editions' => [],
            'language_editions' => [],
        ]);

        $this->post('/store', ['id' => self::ENGLISH])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('related_editions_warning', [
                ['code' => self::ORIGINAL, 'title' => 'Original title'],
            ]);

        $this->assertSame([
            ['code' => self::ORIGINAL, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
        ], Product::findOrFail(self::ENGLISH)->related_editions);
    }

    public function test_import_warns_when_a_library_work_already_lists_the_new_code(): void
    {
        Product::factory()->create([
            'id' => self::ORIGINAL,
            'work_name' => 'Original title',
            'related_editions' => [
                ['code' => self::ENGLISH, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
            ],
        ]);
        // No edition data at all, e.g. the edition lookup failed.
        $this->putScrapedWork(self::ENGLISH, null);

        $this->post('/store', ['id' => self::ENGLISH])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('related_editions_warning', [
                ['code' => self::ORIGINAL, 'title' => 'Original title'],
            ]);

        $this->assertNull(Product::findOrFail(self::ENGLISH)->related_editions);
    }

    public function test_import_warns_about_another_translation_of_the_same_original(): void
    {
        Product::factory()->create([
            'id' => self::CHINESE,
            'work_name' => 'Chinese title',
            'related_editions' => [
                ['code' => self::ORIGINAL, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
            ],
        ]);
        $this->putScrapedWork(self::ENGLISH, [
            'translation_info' => ['original_workno' => self::ORIGINAL],
        ]);

        $this->post('/store', ['id' => self::ENGLISH])
            ->assertSessionHas('related_editions_warning', [
                ['code' => self::CHINESE, 'title' => 'Chinese title'],
            ]);
    }

    public function test_import_does_not_warn_about_unrelated_works(): void
    {
        Product::factory()->create([
            'id' => 'RJ01072461',
            'related_editions' => [
                ['code' => 'RJ01076473', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Mac版'],
            ],
        ]);
        Product::factory()->create(['id' => 'RJ01000001']);
        $this->putScrapedWork(self::ENGLISH, [
            'translation_info' => ['original_workno' => self::ORIGINAL],
            'language_editions' => [['workno' => self::CHINESE, 'label' => '簡体中文']],
        ]);

        $this->post('/store', ['id' => self::ENGLISH])
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('related_editions_warning');

        $this->get('/')->assertDontSee('related-editions-popup', false);
    }

    public function test_index_shows_the_warning_with_links_to_the_existing_works(): void
    {
        Product::factory()->create(['id' => self::ORIGINAL, 'work_name' => 'Original title']);

        $this->withSession(['related_editions_warning' => [['code' => self::ORIGINAL, 'title' => 'Original title']]])
            ->get('/')
            ->assertOk()
            ->assertSee('related-editions-popup', false)
            ->assertSee('Other editions of this work are already in your library:')
            ->assertSee('href="' . route('products.edit', ['product' => self::ORIGINAL]) . '"', false)
            ->assertSee('Original title');
    }

    public function test_modal_completion_lists_the_works_and_stays_open(): void
    {
        Product::factory()->create(['id' => self::ORIGINAL, 'work_name' => 'Original title']);
        $this->putScrapedWork(self::ENGLISH, ['translation_info' => ['original_workno' => self::ORIGINAL]]);

        $this->post('/store', ['id' => self::ENGLISH, 'modal' => '1'])
            ->assertViewIs('WorkFormCompleted')
            ->assertSee('related-editions-warning', false)
            ->assertSee(self::ORIGINAL)
            ->assertSee('Original title')
            ->assertDontSee('window.parent.postMessage', false);
    }

    public function test_manual_add_warns_when_a_library_work_lists_the_code(): void
    {
        Product::factory()->create([
            'id' => self::ORIGINAL,
            'work_name' => 'Original title',
            'related_editions' => [
                ['code' => self::ENGLISH, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
            ],
        ]);

        $this->post('/store/custom', [
            'id' => strtolower(self::ENGLISH),
            'work_name' => 'Manual English title',
            'age_category' => 'ALL_AGES',
        ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('related_editions_warning', [
                ['code' => self::ORIGINAL, 'title' => 'Original title'],
            ]);

        $this->assertTrue(Product::query()->whereKey(self::ENGLISH)->exists());
    }

    public function test_create_page_checks_the_typed_code_before_saving(): void
    {
        Product::factory()->create([
            'id' => self::ORIGINAL,
            'work_name' => 'Original title',
            'related_editions' => [
                ['code' => self::ENGLISH, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
            ],
        ]);

        foreach (['/create', '/create/custom'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('data-related-editions-check', false)
                ->assertSee('scripts/related-editions-check.js', false);
        }

        $this->get('/related-editions?' . http_build_query(['id' => 'https://www.dlsite.com/maniax/work/=/product_id/' . strtolower(self::ENGLISH) . '.html']))
            ->assertOk()
            ->assertSee(self::ORIGINAL)
            ->assertSee('Original title')
            ->assertSee('target="_blank"', false);

        $unrelated = $this->get('/related-editions?id=RJ01000001')->assertOk();
        $this->assertSame('', trim($unrelated->getContent()));
        $this->assertSame('', trim($this->get('/related-editions?id=not-a-code')->assertOk()->getContent()));
    }

    public function test_refetch_updates_editions_without_touching_the_update_time(): void
    {
        $updatedAt = Carbon::parse('2026-01-02 03:04:05');
        $product = Product::factory()->create([
            'id' => self::ORIGINAL,
            'related_editions' => [
                ['code' => self::CHINESE, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '簡体中文'],
            ],
        ]);
        Product::query()->whereKey(self::ORIGINAL)->update(['updated_at' => $updatedAt]);

        $this->refetch($product, [
            'language_editions' => [
                ['workno' => self::CHINESE, 'label' => '簡体中文'],
                ['workno' => self::ENGLISH, 'label' => '英語'],
            ],
        ]);

        $product->refresh();
        $this->assertSame([self::CHINESE, self::ENGLISH], RelatedEditions::codes($product->related_editions));
        $this->assertTrue($product->updated_at->equalTo($updatedAt));

        // A failed edition lookup leaves the stored links alone.
        $this->refetch($product, null);
        $this->assertSame([self::CHINESE, self::ENGLISH], RelatedEditions::codes($product->refresh()->related_editions));
    }

    public function test_edit_page_lists_editions_with_local_and_dlsite_links(): void
    {
        $product = Product::factory()->create([
            'id' => self::ORIGINAL,
            'site_id' => 'maniax',
            'related_editions' => [
                ['code' => self::ENGLISH, 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
                ['code' => 'RJ01076473', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Mac版'],
            ],
        ]);
        Product::factory()->create(['id' => self::ENGLISH, 'work_name' => 'English title']);
        Product::factory()->create([
            'id' => self::CHINESE,
            'work_name' => 'Chinese title',
            'related_editions' => [['code' => self::ORIGINAL, 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null]],
        ]);

        $this->get("/edit/{$product->id}")
            ->assertOk()
            ->assertSee('related-editions-row', false)
            ->assertSeeInOrder([self::ENGLISH, 'Language edition', '英語', 'English title'])
            ->assertSee('href="/edit/' . self::ENGLISH . '"', false)
            ->assertSeeInOrder(['RJ01076473', 'Other version', 'Mac版', 'Not in your library'])
            ->assertSee('href="https://www.dlsite.com/maniax/work/=/product_id/RJ01076473.html"', false)
            ->assertSee('href="/edit/' . self::CHINESE . '"', false)
            ->assertSee('Chinese title');

        $this->get('/edit/RJ01000001')->assertNotFound();
        $plain = Product::factory()->create();
        $this->get("/edit/{$plain->id}")->assertOk()->assertDontSee('related-editions-row', false);
    }

    /** @param  array<string, mixed>|null  $editions  Raw scraper edition data; null leaves the key out. */
    private function putScrapedWork(string $workId, ?array $editions): void
    {
        Storage::disk('local')->put("Works/{$workId}.json", json_encode(
            $this->scrapedWork($workId, $editions),
            JSON_THROW_ON_ERROR,
        ));
    }

    private function scrapedWork(string $workId, ?array $editions): array
    {
        $japanese = [
            'product_id' => $workId,
            'maker_id' => 'RG12345',
            'work_name' => "Title {$workId}",
            'age_category' => ['_name_' => 'R18'],
            'circle' => 'Circle',
            'sample_images' => [],
            'genre' => [],
        ];

        if ($editions !== null) {
            $japanese['dlsite_editions'] = $editions;
        }

        return ['japanese' => $japanese, 'english' => ['work_name' => "English {$workId}", 'genre' => []]];
    }

    private function refetch(Product $product, ?array $editions): void
    {
        $service = app(RefetchService::class);
        $run = $service->createRun([$product->id], false);
        Storage::disk('local')->put(
            "Refetch/{$run->id}/Works/{$product->id}.json",
            json_encode($this->scrapedWork($product->id, $editions), JSON_THROW_ON_ERROR),
        );

        $service->fetchAndRecordResult($run->results()->firstOrFail());
    }
}
