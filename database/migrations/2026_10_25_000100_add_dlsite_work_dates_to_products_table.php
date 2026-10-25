<?php

use App\Support\DLSite\DLSiteWorkData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dateTime('dlsite_release_date')->nullable()->after('announce_date');
            $table->dateTime('dlsite_updated_date')->nullable()->after('dlsite_release_date');
        });

        // Scraper JSON saved by earlier fetches may already carry the DLsite dates.
        DB::table('products')->select('id')->orderBy('id')->chunk(200, function ($products): void {
            foreach ($products as $product) {
                $path = "Works/{$product->id}.json";
                if (! Storage::disk('local')->exists($path)) {
                    continue;
                }

                try {
                    $payload = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
                    $work = DLSiteWorkData::fromArray(is_array($payload) ? $payload : [], $product->id);
                } catch (Throwable $exception) {
                    Log::warning('Unable to backfill DLsite work dates from scraper JSON.', [
                        'product_id' => $product->id,
                        'path' => $path,
                        'error' => $exception->getMessage(),
                    ]);

                    continue;
                }

                $dates = array_filter([
                    'dlsite_release_date' => $work->releaseDate,
                    'dlsite_updated_date' => $work->updatedDate,
                ], fn(?string $date): bool => $date !== null);

                // Database update failures should abort the migration rather than be treated like an unreadable/corrupt historical scraper JSON file.
                if ($dates !== []) {
                    DB::table('products')->where('id', $product->id)->update($dates);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['dlsite_release_date', 'dlsite_updated_date']);
        });
    }
};
