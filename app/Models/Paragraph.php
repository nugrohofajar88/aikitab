<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paragraph extends Model
{
    protected $fillable = [
        'book_id',
        'page_id',
        'paragraph_number',
        'raw_text',
        'harakat_text',
        'content_json',
        'status',
        'error_message',
    ];

    protected $casts = [
        'content_json' => 'array',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
