<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // ID of this book on the hosted (public) instance, once published.
            $table->unsignedBigInteger('remote_book_id')->nullable()->after('status');
            $table->timestamp('published_at')->nullable()->after('remote_book_id');
            // Set when this book was pulled in from a hosted "minta kitab" request —
            // reported back to hosted on publish so that request gets linked/closed.
            $table->uuid('remote_request_uuid')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['remote_book_id', 'published_at', 'remote_request_uuid']);
        });
    }
};
