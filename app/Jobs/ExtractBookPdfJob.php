<?php

namespace App\Jobs;

use App\Models\Book;
use App\Services\PdfExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExtractBookPdfJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Book $book)
    {
    }

    /**
     * Parse the PDF and store one raw-text Page per page. This does not create
     * paragraphs or call the AI — the user picks a page range to process next.
     */
    public function handle(PdfExtractionService $extractor): void
    {
        $this->book->update(['status' => 'extracting', 'error_message' => null]);

        try {
            $absolutePath = Storage::disk('local')->path($this->book->file_path);
            $pages = $extractor->extractPages($absolutePath);
        } catch (Throwable $e) {
            $this->book->update([
                'status' => 'failed',
                'error_message' => 'Gagal mengekstrak PDF: '.$e->getMessage(),
            ]);

            return;
        }

        if (count($pages) === 0) {
            $this->book->update([
                'status' => 'failed',
                'error_message' => 'Tidak ada teks yang berhasil diekstrak. Kemungkinan PDF berupa hasil scan/gambar (OCR belum didukung pada MVP ini).',
            ]);

            return;
        }

        foreach ($pages as $pageNumber => $pageText) {
            $this->book->pages()->create([
                'page_number' => $pageNumber,
                'raw_text' => $pageText,
                // Default to vision for every page: this app's kitab content is
                // Arabic, and text-mode extraction has proven unreliable enough
                // on Arabic PDFs (broken/missing ToUnicode CMaps, reversed bidi
                // runs) that it's no longer worth the heuristic's false negatives.
                // Text-mode (splitIntoParagraphs / needsVisionFallback) is kept
                // intact for a future "kitab teks biasa" (non-Arabic) mode.
                'extraction_method' => 'vision',
            ]);
        }

        $this->book->update([
            'total_pages' => count($pages),
            'status' => 'ready',
        ]);
    }
}
