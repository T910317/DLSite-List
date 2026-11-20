<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_transfer_parts', function (Blueprint $table): void {
            $table->unsignedBigInteger('expanded_bytes')->default(0)->after('bytes');
        });
    }

    public function down(): void
    {
        Schema::table('library_transfer_parts', function (Blueprint $table): void {
            $table->dropColumn('expanded_bytes');
        });
    }
};
