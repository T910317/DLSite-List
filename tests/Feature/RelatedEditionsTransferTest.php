<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Support\RelatedEditions;
use Tests\Feature\Concerns\InteractsWithLibraryTransfers;
use Tests\TestCase;

class RelatedEditionsTransferTest extends TestCase
{
    use InteractsWithLibraryTransfers;

    private const EDITIONS = [
        ['code' => 'RJ01625050', 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
        ['code' => 'RJ01076473', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Mac版'],
    ];

    public function test_edition_links_round_trip_through_export_and_import(): void
    {
        Product::factory()->create(['id' => 'RJ01587488', 'related_editions' => self::EDITIONS]);
        Product::factory()->create(['id' => 'RJ01000001', 'related_editions' => null]);
        $export = $this->exported();
        Product::query()->delete();

        $this->apply($this->imported($export));

        $this->assertSame(self::EDITIONS, Product::findOrFail('RJ01587488')->related_editions);
        // Unknown stays unknown instead of turning into "no editions".
        $this->assertNull(Product::findOrFail('RJ01000001')->related_editions);
    }

    public function test_import_reviews_changed_edition_links_for_an_existing_work(): void
    {
        $product = Product::factory()->create(['id' => 'RJ01587488', 'related_editions' => self::EDITIONS]);
        $export = $this->exported();
        $product->update(['related_editions' => [self::EDITIONS[0]]]);

        $run = $this->imported($export);
        $item = $run->items()->where('category', 'related_editions')->firstOrFail();
        $this->assertSame('pending', $item->status->value);
        $this->assertSame(
            ['RJ01625050 (Language edition: 英語)', 'RJ01076473 (Other version: Mac版)'],
            array_column($item->incoming_preview['value'], 'value'),
        );

        $this->apply($run);
        $this->assertSame(self::EDITIONS, $product->fresh()->related_editions);
    }

    public function test_archive_from_before_edition_links_keeps_the_stored_links(): void
    {
        Product::factory()->create(['id' => 'RJ01587488', 'related_editions' => self::EDITIONS]);
        $export = $this->exported();
        $this->rewriteWorkData($export->parts()->firstOrFail(), function (array $work): array {
            unset($work['japanese']['related_editions'], $work['english']['related_editions']);
            $work['japanese']['work_name'] = 'Imported legacy title';

            return $work;
        });

        $run = $this->imported($export);
        $this->assertFalse($run->items()->where('category', 'related_editions')->exists());
        $this->apply($run);

        $product = Product::findOrFail('RJ01587488');
        $this->assertSame('Imported legacy title', $product->work_name);
        $this->assertSame(self::EDITIONS, $product->related_editions);
    }

    public function test_invalid_edition_links_are_quarantined(): void
    {
        Product::factory()->create(['id' => 'RJ01587488']);
        $export = $this->exported();
        $this->rewriteWorkData($export->parts()->firstOrFail(), function (array $work): array {
            $work['japanese']['related_editions'] = [['code' => 'RJ01625050', 'type' => 'sequel']];

            return $work;
        });

        $run = $this->imported($export);
        $this->assertSame(1, $run->items()->where('status', 'unavailable')->count());
    }
}
