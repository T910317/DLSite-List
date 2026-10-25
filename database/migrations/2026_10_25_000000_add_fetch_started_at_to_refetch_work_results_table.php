<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refetch_work_results', function (Blueprint $table): void {
            $table->timestamp('fetch_started_at')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('refetch_work_results', function (Blueprint $table): void {
            $table->dropColumn('fetch_started_at');
        });
    }
};
