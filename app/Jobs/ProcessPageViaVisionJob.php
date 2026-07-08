<?php

namespace App\Jobs;

use App\Models\Page;
use App\Services\AiOrchestrator;
use App\Services\PdfPageSlicer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessPageViaVisionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Covers a primary-provider attempt plus a full fallback-provider attempt
    // if AiOrchestrator has to switch providers (see AI_FALLBACK_PROVIDER).
    public int $timeout = 1300;

    public function __construct(public int $pageId)
    {
    }

    /**
     * Whole page counts as a single unit of work (mirrors ProcessParagraphJob),
     * since the number of paragraphs on the page is only known after Gemini reads it.
     */
    public function handle(AiOrchestrator $ai, PdfPageSlicer $slicer): void
    {
        $page = Page::with('book')->find($this->pageId);

        if (! $page) {
            return;
        }

        $book = $page->book;

        try {
            $absolutePath = Storage::disk('local')->path($book->file_path);
            $pdfBinary = $slicer->slicePage($absolutePath, $page->page_number);
            $result = $ai->annotatePdfPage($pdfBinary);

            // A provider can return HTTP 200 with junk content (e.g. a lone
            // page-number digit) instead of actually reading the page — don't
            // let that get silently stored as a "done" result.
            $paragraphs = array_values(array_filter(
                $result['paragraphs'] ?? [],
                fn ($p) => $this->looksLikeRealContent($p)
            ));

            foreach ($paragraphs as $i => $paragraphData) {
                $page->paragraphs()->updateOrCreate(
                    ['page_id' => $page->id, 'paragraph_number' => $i + 1],
                    [
                        'book_id' => $book->id,
                        'raw_text' => $paragraphData['harakat_text'] ?? '',
                        'harakat_text' => $paragraphData['harakat_text'] ?? null,
                        'content_json' => $paragraphData,
                        'status' => 'done',
                        'error_message' => null,
                    ]
                );
            }

            if ($paragraphs === []) {
                $page->paragraphs()->updateOrCreate(
                    ['page_id' => $page->id, 'paragraph_number' => 1],
                    [
                        'book_id' => $book->id,
                        'raw_text' => $page->raw_text,
                        'status' => 'failed',
                        'error_message' => 'AI tidak menemukan teks yang bisa dibaca pada halaman ini (atau hasilnya terlalu pendek untuk dipercaya).',
                    ]
                );
            } else {
                // A retry may find fewer paragraphs than a previous attempt did —
                // drop the leftover rows so stale content doesn't linger.
                $page->paragraphs()->where('paragraph_number', '>', count($paragraphs))->delete();
            }
        } catch (Throwable $e) {
            $page->paragraphs()->updateOrCreate(
                ['page_id' => $page->id, 'paragraph_number' => 1],
                [
                    'book_id' => $book->id,
                    'raw_text' => $page->raw_text,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]
            );
        } finally {
            // Always account for this unit of work, even if something above
            // threw unexpectedly — otherwise total/processed counters drift
            // and the progress bar gets stuck short of 100%.
            $book->increment('processed_paragraphs');
            $book->refresh();

            if ($book->processed_paragraphs >= $book->total_paragraphs) {
                $book->update(['status' => 'completed']);
            }
        }
    }

    /**
     * Guards against a provider returning HTTP 200 with junk instead of
     * actually reading the page (observed: a lone page-number digit as the
     * entire "harakat_text", with an empty sentences array).
     */
    protected function looksLikeRealContent(array $paragraphData): bool
    {
        $harakatText = trim((string) ($paragraphData['harakat_text'] ?? ''));

        return mb_strlen($harakatText) >= 15 && ! empty($paragraphData['sentences']);
    }
}
