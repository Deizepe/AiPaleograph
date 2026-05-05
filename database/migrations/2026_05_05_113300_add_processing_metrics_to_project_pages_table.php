<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->unsignedInteger('processing_time_ms')->nullable()->after('transcription_error');
            $table->decimal('processing_cost_usd', 12, 6)->nullable()->after('processing_time_ms');
        });
    }

    public function down(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->dropColumn(['processing_time_ms', 'processing_cost_usd']);
        });
    }
};
