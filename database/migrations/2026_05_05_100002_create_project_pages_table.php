<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->string('original_filename');
            $table->string('object_key');
            $table->string('mime_type', 128);
            $table->longText('original_text')->nullable();
            $table->longText('transcribed_text')->nullable();
            $table->string('transcription_status', 32)->default('pending');
            $table->text('transcription_error')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_pages');
    }
};
