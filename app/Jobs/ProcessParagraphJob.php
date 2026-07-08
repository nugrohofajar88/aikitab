<?php

namespace App\Jobs;

use App\Models\Paragraph;
use App\Services\AiOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessParagraphJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Covers a primary-provider attempt plus a full fallback-provider attempt
    // if AiOrchestrator has to switch providers (see AI_FALLBACK_PROVIDER).
    public int $timeout = 550;

    public function __construct(public int $paragraphId)
    {
    }

    public function handle(AiOrchestrator $ai): void
    {
        $paragraph = Paragraph::find($this->paragraphId);

        if (! $paragraph) {
            return;
        }

        $paragraph->update(['status' => 'processing']);

        try {
            $result = $ai->annotateParagraph($paragraph->raw_text);

            $paragraph->update([
                'harakat_text' => $result['harakat_text'] ?? null,
                'content_json' => $result,
                'status' => 'done',
                'error_message' => null,
            ]);
        } catch (Throwable $e) {
            $paragraph->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            // Always account for this unit of work, even if something above
            // threw unexpectedly — otherwise total/processed counters drift
            // and the progress bar gets stuck short of 100%.
            $book = $paragraph->book;
            $book->increment('processed_paragraphs');
            $book->refresh();

            if ($book->processed_paragraphs >= $book->total_paragraphs) {
                $book->update(['status' => 'completed']);
            }
        }
    }
}
