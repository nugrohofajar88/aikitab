<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page_number');
            $table->longText('raw_text')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
