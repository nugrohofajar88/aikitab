<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('original_filename');
            $table->string('file_path');
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('total_paragraphs')->default(0);
            $table->unsignedInteger('processed_paragraphs')->default(0);
            $table->enum('status', ['uploaded', 'extracting', 'processing', 'completed', 'failed'])
                ->default('uploaded');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
