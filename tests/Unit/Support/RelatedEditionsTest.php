<?php

namespace Tests\Unit\Support;

use App\Support\DLSite\DLSiteWorkData;
use App\Support\RelatedEditions;
use PHPUnit\Framework\TestCase;

class RelatedEditionsTest extends TestCase
{
    public function test_it_maps_dlsite_language_platform_and_translation_links(): void
    {
        // Shapes follow DLsite's product.json and product/info/ajax responses.
        $editions = RelatedEditions::fromDlsite([
            'translation_info' => [
                'is_original' => true,
                'original_workno' => null,
                'parent_workno' => null,
                'child_worknos' => ['RJ01625050', 'RJ01616206'],
            ],
            'language_editions' => [
                ['workno' => 'RJ01625050', 'edition_id' => 1, 'edition_type' => 'language', 'label' => '英語', 'lang' => 'ENG'],
                ['workno' => 'RJ01616206', 'label' => '簡体中文', 'lang' => 'CHI_HANS'],
                ['workno' => 'RJ01587488', 'label' => '日本語', 'lang' => 'JPN'],
            ],
            'editions' => [
                ['workno' => 'RJ01076473', 'edition_type' => 'normal', 'label' => 'Mac版'],
            ],
        ], 'RJ01587488');

        $this->assertSame([
            ['code' => 'RJ01625050', 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '英語'],
            ['code' => 'RJ01616206', 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => '簡体中文'],
            ['code' => 'RJ01076473', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Mac版'],
        ], $editions);
    }

    public function test_a_translation_points_back_to_its_original(): void
    {
        $this->assertSame([
            ['code' => 'RJ01587488', 'type' => RelatedEditions::TYPE_ORIGINAL, 'label' => null],
        ], RelatedEditions::fromDlsite([
            'translation_info' => ['original_workno' => 'RJ01587488', 'parent_workno' => 'RJ01587488', 'child_worknos' => []],
            'editions' => [],
            'language_editions' => [],
        ], 'RJ01625050'));
    }

    public function test_normalize_drops_invalid_entries_and_fills_missing_labels(): void
    {
        $this->assertSame([
            ['code' => 'RJ01', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'Android版'],
            ['code' => 'BJ02', 'type' => RelatedEditions::TYPE_TRANSLATION, 'label' => null],
        ], RelatedEditions::normalize([
            ['code' => ' rj01 ', 'type' => 'unknown'],
            ['code' => 'RJ01', 'type' => RelatedEditions::TYPE_LANGUAGE, 'label' => 'Android版'],
            ['code' => 'BJ02', 'type' => RelatedEditions::TYPE_TRANSLATION, 'label' => '  '],
            ['code' => 'RJ99', 'type' => RelatedEditions::TYPE_ORIGINAL],
            ['code' => 'not-a-code'],
            ['code' => 123],
            'RJ03',
        ], 'rj99'));
        $this->assertSame([], RelatedEditions::normalize('RJ01'));
    }

    public function test_work_data_keeps_unknown_editions_apart_from_none(): void
    {
        $data = fn(array $japanese): DLSiteWorkData => DLSiteWorkData::fromArray([
            'japanese' => ['product_id' => 'RJ01', 'work_name' => 'Title', ...$japanese],
            'english' => [],
        ]);

        $this->assertNull($data([])->relatedEditions);
        $this->assertNull($data(['related_editions' => null])->relatedEditions);
        $this->assertSame([], $data(['dlsite_editions' => ['editions' => []]])->relatedEditions);
        $this->assertSame([], $data(['related_editions' => []])->relatedEditions);
        $this->assertSame(
            [['code' => 'RJ02', 'type' => RelatedEditions::TYPE_EDITION, 'label' => 'PC版']],
            $data(['related_editions' => [['code' => 'RJ02', 'type' => 'edition', 'label' => 'PC版']]])->relatedEditions,
        );
    }

    public function test_describe_gives_a_one_line_summary(): void
    {
        $this->assertSame('RJ02', RelatedEditions::describe(['code' => 'RJ02']));
        $this->assertSame('RJ02', RelatedEditions::describe('RJ02'));
    }
}
