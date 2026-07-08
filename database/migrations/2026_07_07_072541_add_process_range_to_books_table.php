<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedInteger('process_from_page')->nullable()->after('total_pages');
            $table->unsignedInteger('process_to_page')->nullable()->after('process_from_page');
        });

        DB::statement("ALTER TABLE books MODIFY status ENUM('uploaded', 'extracting', 'ready', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'uploaded'");
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['process_from_page', 'process_to_page']);
        });

        DB::statement("ALTER TABLE books MODIFY status ENUM('uploaded', 'extracting', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'uploaded'");
    }
};
