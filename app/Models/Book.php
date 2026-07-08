<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $fillable = [
        'title',
        'author',
        'original_filename',
        'file_path',
        'total_pages',
        'process_from_page',
        'process_to_page',
        'total_paragraphs',
        'processed_paragraphs',
        'status',
        'error_message',
        'remote_book_id',
        'published_at',
        'remote_request_uuid',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class)->orderBy('page_number');
    }

    public function paragraphs(): HasMany
    {
        return $this->hasMany(Paragraph::class);
    }

    public function progressPercentage(): int
    {
        if ($this->total_paragraphs === 0) {
            return 0;
        }

        return (int) round(($this->processed_paragraphs / $this->total_paragraphs) * 100);
    }
}
