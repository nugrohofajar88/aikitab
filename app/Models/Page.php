<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    protected $fillable = [
        'book_id',
        'page_number',
        'raw_text',
        'extraction_method',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function paragraphs(): HasMany
    {
        return $this->hasMany(Paragraph::class)->orderBy('paragraph_number');
    }

    /**
     * Summarize this page's processing state for the page-index UI.
     * Assumes `paragraphs` is already eager loaded.
     */
    public function statusSummary(): string
    {
        if ($this->paragraphs->isEmpty()) {
            return 'empty';
        }

        if ($this->paragraphs->whereIn('status', ['pending', 'processing'])->isNotEmpty()) {
            return 'processing';
        }

        if ($this->paragraphs->where('status', 'failed')->isNotEmpty()) {
            return 'failed';
        }

        return 'done';
    }
}
