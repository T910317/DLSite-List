<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkDatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_imports_saved_dlsite_dates_and_the_migration_can_be_reversed(): void
    {
        Storage::fake('local');

        foreach (['RJ000000801', 'RJ000000802', 'RJ000000803', 'RJ000000804'] as $id) {
            Product::factory()->create(['id' => $id]);
        }
        Storage::disk('local')->put('Works/RJ000000801.json', json_encode([
            'japanese' => [
                'product_id' => 'RJ000000801',
                'regist_date' => '2026-03-01 09:00:00',
                'modified_date' => '2026-04-02 10:30:00',
            ],
            'english' => [],
        ], JSON_THROW_ON_ERROR));
        // 802 only has English dates; 803 has no saved scraper JSON; 804 has an unreadable one.
        Storage::disk('local')->put('Works/RJ000000802.json', json_encode([
            'japanese' => ['product_id' => 'RJ000000802'],
            'english' => ['regist_date' => '2025-12-24 00:00:00'],
        ], JSON_THROW_ON_ERROR));
        Storage::disk('local')->put('Works/RJ000000804.json', '{invalid json');

        $this->removeWorkDateColumns();
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumns('products', ['dlsite_release_date', 'dlsite_updated_date']));
        $this->assertSame(
            '2026-03-01 09:00:00',
            Product::findOrFail('RJ000000801')->dlsite_release_date?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-04-02 10:30:00',
            Product::findOrFail('RJ000000801')->dlsite_updated_date?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2025-12-24 00:00:00',
            Product::findOrFail('RJ000000802')->dlsite_release_date?->format('Y-m-d H:i:s'),
        );
        $this->assertNull(Product::findOrFail('RJ000000802')->dlsite_updated_date);
        $this->assertNull(Product::findOrFail('RJ000000803')->dlsite_release_date);
        $this->assertNull(Product::findOrFail('RJ000000804')->dlsite_release_date);

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('products', 'dlsite_release_date'));
        $this->assertFalse(Schema::hasColumn('products', 'dlsite_updated_date'));

        $this->migration()->up();

        $this->assertSame(
            '2026-03-01 09:00:00',
            Product::findOrFail('RJ000000801')->dlsite_release_date?->format('Y-m-d H:i:s'),
        );
    }

    private function removeWorkDateColumns(): void
    {
        // RefreshDatabase already migrated the current schema. Drop the new columns so the real migration's up() can add them back.
        Schema::table('products', static function (Blueprint $table): void {
            $table->dropColumn(['dlsite_release_date', 'dlsite_updated_date']);
        });
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_25_000100_add_dlsite_work_dates_to_products_table.php');
    }
}
