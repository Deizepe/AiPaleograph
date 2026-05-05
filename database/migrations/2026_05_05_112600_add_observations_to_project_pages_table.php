<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->longText('observations')->nullable()->after('transcribed_text');
        });
    }

    public function down(): void
    {
        Schema::table('project_pages', function (Blueprint $table) {
            $table->dropColumn('observations');
        });
    }
};
