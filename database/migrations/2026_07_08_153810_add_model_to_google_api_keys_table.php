<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('google_api_keys', function (Blueprint $table) {
            $table->dropUnique(['api_key', 'date_used']);
            $table->string('model')->nullable()->after('api_key');
        });

        // Backfill existing rows (logged before this column existed) with the
        // model that was actually in use at the time.
        DB::table('google_api_keys')->whereNull('model')->update([
            'model' => config('services.gemini.model', 'gemini-2.5-flash'),
        ]);

        Schema::table('google_api_keys', function (Blueprint $table) {
            $table->string('model')->nullable(false)->change();
            $table->unique(['api_key', 'model', 'date_used']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('google_api_keys', function (Blueprint $table) {
            $table->dropUnique(['api_key', 'model', 'date_used']);
            $table->dropColumn('model');
            $table->unique(['api_key', 'date_used']);
        });
    }
};
