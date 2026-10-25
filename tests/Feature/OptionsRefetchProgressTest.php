<?php

namespace Tests\Feature;

use App\Enums\UiLanguage;
use App\Jobs\FetchProductWorkJob;
use App\Livewire\OptionsRefetchProgress;
use App\Models\Product;
use App\Models\RefetchRun;
use App\Models\RefetchWorkResult;
use App\Support\Refetch\RefetchService;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OptionsRefetchProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_component_polls_only_while_run_is_active(): void
    {
        $product = Product::factory()->create();
        $run = app(RefetchService::class)->createRun([$product->id], false);

        $running = Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('0 / 1 work processed')
            ->assertSeeInOrder(['Fetched', 'Failed', 'Total'])
            ->assertSee('Cancel Refetch');
        $this->assertSame(['refreshProgress'], $this->pollingHandlers($running->html()));

        $run->forceFill([
            'status' => RefetchRun::STATUS_CANCELLING,
            'cancelled_at' => now(),
        ])->save();

        $cancelling = Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('Cancelling')
            ->assertDontSee('Cancel Refetch');
        $this->assertSame(['refreshProgress'], $this->pollingHandlers($cancelling->html()));

        $run->forceFill([
            'status' => RefetchRun::STATUS_REVIEW,
            'processed_count' => 1,
            'fetched_count' => 1,
            'completed_at' => now(),
        ])->save();

        $review = Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('1 / 1 work processed');
        $this->assertSame([], $this->pollingHandlers($review->html()));
    }

    public function test_progress_component_redirects_when_run_completes_during_poll(): void
    {
        $product = Product::factory()->create();
        $run = app(RefetchService::class)->createRun([$product->id], false);

        $component = Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->call('refreshProgress')
            ->assertNoRedirect();

        $run->forceFill([
            'status' => RefetchRun::STATUS_REVIEW,
            'processed_count' => 1,
            'fetched_count' => 1,
            'completed_at' => now(),
        ])->save();

        $component
            ->call('refreshProgress')
            ->assertRedirectToRoute('options.refetch.show', $run);
    }

    public function test_progress_component_redirects_when_cancelled_run_reaches_review(): void
    {
        $product = Product::factory()->create();
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $run->forceFill([
            'status' => RefetchRun::STATUS_CANCELLING,
            'cancelled_at' => now(),
        ])->save();

        $component = Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->call('refreshProgress')
            ->assertNoRedirect();

        $run->forceFill([
            'status' => RefetchRun::STATUS_REVIEW,
            'processed_count' => 1,
            'failed_count' => 1,
            'completed_at' => now(),
        ])->save();

        $component
            ->call('refreshProgress')
            ->assertRedirectToRoute('options.refetch.show', $run);
    }

    public function test_progress_lists_the_works_being_fetched_while_run_is_running(): void
    {
        $first = Product::factory()->create(['id' => 'RJ01000001', 'work_name' => 'First Fetching Title']);
        $second = Product::factory()->create(['id' => 'RJ01000002', 'work_name' => 'Second Fetching Title']);
        $done = Product::factory()->create(['id' => 'RJ01000003', 'work_name' => 'Already Fetched Title']);
        $waiting = Product::factory()->create(['id' => 'RJ01000004', 'work_name' => 'Waiting Title']);
        $run = app(RefetchService::class)->createRun([$first->id, $second->id, $done->id, $waiting->id], false);
        $this->startFetch($run, $second->id, now()->subSeconds(30));
        $this->startFetch($run, $first->id, now());
        $this->startFetch($run, $done->id, now()->subSeconds(60));
        $run->results()->where('product_id', $done->id)->update(['status' => RefetchWorkResult::STATUS_FETCHED]);

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSeeInOrder([
                'Now fetching:',
                'RJ01000002',
                'Second Fetching Title',
                'RJ01000001',
                'First Fetching Title',
                'Fetched',
            ])
            ->assertDontSee('RJ01000003')
            ->assertDontSee('Already Fetched Title')
            ->assertDontSee('RJ01000004')
            ->assertDontSee('Waiting Title');
    }

    public function test_progress_shows_nothing_being_fetched_before_any_fetch_starts(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01000011', 'work_name' => 'Not Started Title']);
        $run = app(RefetchService::class)->createRun([$product->id], false);

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('0 / 1 work processed')
            ->assertDontSee('Now fetching:')
            ->assertDontSee('Not Started Title');
    }

    public function test_progress_hides_the_works_being_fetched_after_run_finishes(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01000021', 'work_name' => 'Finished Title']);
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $this->startFetch($run, $product->id, now());

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('Now fetching:')
            ->assertSee('Finished Title');

        $run->results()->update(['status' => RefetchWorkResult::STATUS_FETCHED]);
        app(RefetchService::class)->refreshRunProgress($run->refresh());
        $this->assertFalse($run->refresh()->isActive());
        $this->assertTrue($run->hasReviewResults());

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('1 / 1 work processed')
            ->assertDontSee('Now fetching:')
            ->assertDontSee('Finished Title');
    }

    public function test_progress_hides_the_works_being_fetched_after_cancel(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01000031', 'work_name' => 'Cancelled Title']);
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $this->startFetch($run, $product->id, now());

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('Now fetching:')
            ->assertSee('Cancelled Title');

        $this->assertTrue(app(RefetchService::class)->cancelRun($run));
        $this->assertTrue($run->refresh()->isCancelling());

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSee('Cancelling')
            ->assertDontSee('Now fetching:')
            ->assertDontSee('Cancelled Title');

        app(RefetchService::class)->recordFailedResult(
            $run->results()->firstOrFail(),
            RefetchService::CANCELLED_BEFORE_FETCH_MESSAGE,
        );
        $this->assertSame(RefetchRun::STATUS_REVIEW, $run->refresh()->status);

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertDontSee('Now fetching:')
            ->assertDontSee('Cancelled Title');
    }

    public function test_progress_ignores_a_fetch_that_started_longer_ago_than_the_job_timeout(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01000041', 'work_name' => 'Stale Title']);
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $this->startFetch($run, $product->id, now()->subSeconds(FetchProductWorkJob::TIMEOUT_SECONDS + 1));

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertDontSee('Now fetching:')
            ->assertDontSee('Stale Title');
    }

    public function test_progress_labels_the_works_being_fetched_in_traditional_chinese(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01000051', 'work_name' => '擷取中的作品']);
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $this->startFetch($run, $product->id, now());
        app()->setLocale(UiLanguage::TraditionalChinese->value);

        Livewire::test(OptionsRefetchProgress::class, ['run' => $run])
            ->assertSeeInOrder(['正在擷取：', 'RJ01000051', '擷取中的作品']);
    }

    public function test_fetch_marks_the_work_as_being_fetched_until_its_result_is_recorded(): void
    {
        Storage::fake('local');
        $product = Product::factory()->create(['id' => 'RJ01000061', 'work_name' => 'Old Title']);
        $run = app(RefetchService::class)->createRun([$product->id], false);
        $result = $run->results()->firstOrFail();
        Storage::disk('local')->put("Refetch/{$run->id}/Works/{$product->id}.json", json_encode([
            'japanese' => [
                'product_id' => $product->id,
                'work_name' => 'New Title',
            ],
            'english' => [],
        ], JSON_THROW_ON_ERROR));
        $fetchingDuringFetch = null;
        Process::fake(function () use ($run, $product, &$fetchingDuringFetch) {
            $fetchingDuringFetch = app(RefetchService::class)
                ->fetchingResults($run->refresh())
                ->pluck('product_id')
                ->all();

            return Process::result(output: json_encode([
                'product_id' => $product->id,
                'downloaded_images' => [],
                'failed_images' => [],
            ], JSON_THROW_ON_ERROR));
        })->preventStrayProcesses();

        $this->assertCount(0, app(RefetchService::class)->fetchingResults($run));

        app(RefetchService::class)->fetchAndRecordResult($result);

        $this->assertSame([$product->id], $fetchingDuringFetch);
        $this->assertTrue($result->refresh()->isFetched());
        $this->assertNotNull($result->fetch_started_at);
        $this->assertCount(0, app(RefetchService::class)->fetchingResults($run->refresh()));
    }

    private function startFetch(RefetchRun $run, string $productId, CarbonInterface $startedAt): void
    {
        $run->results()
            ->where('product_id', $productId)
            ->update(['fetch_started_at' => $startedAt]);
    }

    /** @return list<string> */
    private function pollingHandlers(string $html): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new DOMXPath($document);
        $handlers = [];

        foreach ($xpath->query('//*') as $element) {
            foreach ($element->attributes ?? [] as $attribute) {
                if (str_starts_with($attribute->nodeName, 'wire:poll')) {
                    $handlers[] = trim($attribute->nodeValue);
                }
            }
        }

        return $handlers;
    }
}
