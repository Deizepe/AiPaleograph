<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_settings')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE user_settings MODIFY openrouter_model VARCHAR(255) NOT NULL DEFAULT 'openai/gpt-5.4'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_settings')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE user_settings MODIFY openrouter_model VARCHAR(255) NOT NULL DEFAULT 'openai/gpt-4o'");
        }
    }
};
