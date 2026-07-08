<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paragraphs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('paragraph_number');
            $table->longText('raw_text');
            $table->longText('harakat_text')->nullable();
            $table->json('content_json')->nullable();
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'paragraph_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paragraphs');
    }
};
