<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('google_api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('api_key');
            $table->date('date_used');
            $table->unsignedInteger('n_request')->default(0);
            $table->timestamps();

            $table->unique(['api_key', 'date_used']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_api_keys');
    }
};
