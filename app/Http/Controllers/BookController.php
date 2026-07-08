<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractBookPdfJob;
use App\Jobs\ProcessPageViaVisionJob;
use App\Jobs\ProcessParagraphJob;
use App\Models\Book;
use App\Services\HostedSyncService;
use App\Services\PdfExtractionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BookController extends Controller
{
    public function index(): View
    {
        $books = Book::query()->latest()->get();

        return view('books.index', compact('books'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ]);

        $path = $request->file('pdf')->store('books');

        $book = Book::create([
            'title' => $validated['title'],
            'author' => $validated['author'] ?? null,
            'original_filename' => $request->file('pdf')->getClientOriginalName(),
            'file_path' => $path,
            'status' => 'uploaded',
        ]);

        ExtractBookPdfJob::dispatch($book);

        return redirect()->route('books.show', $book)
            ->with('status', 'PDF berhasil diupload. Menyiapkan preview...');
    }

    public function show(Book $book): View
    {
        $book->load('pages.paragraphs');

        return view('books.show', compact('book'));
    }

    public function file(Book $book): StreamedResponse
    {
        return Storage::disk('local')->response(
            $book->file_path,
            $book->original_filename,
            ['Content-Type' => 'application/pdf']
        );
    }

    public function process(Request $request, Book $book): RedirectResponse
    {
        $validated = $request->validate([
            'from_page' => ['required', 'integer', 'min:1', 'lte:to_page'],
            'to_page' => ['required', 'integer', 'min:1', 'max:'.$book->total_pages],
            'force' => ['nullable', 'boolean'],
            'force_vision' => ['nullable', 'boolean'],
        ]);

        $fromPage = (int) $validated['from_page'];
        $toPage = (int) $validated['to_page'];
        $force = (bool) ($validated['force'] ?? false);
        $forceVision = (bool) ($validated['force_vision'] ?? false);

        $pagesQuery = $book->pages()
            ->whereBetween('page_number', [$fromPage, $toPage])
            ->orderBy('page_number');

        if ($force) {
            // Re-processing replaces old results, so the units they used to
            // count for need to come back out of the progress tally first.
            $oldParagraphCount = $book->paragraphs()
                ->whereIn('page_id', (clone $pagesQuery)->pluck('id'))
                ->count();

            $book->paragraphs()->whereIn('page_id', (clone $pagesQuery)->pluck('id'))->delete();

            if ($oldParagraphCount > 0) {
                $book->update([
                    'total_paragraphs' => max(0, $book->total_paragraphs - $oldParagraphCount),
                    'processed_paragraphs' => max(0, $book->processed_paragraphs - $oldParagraphCount),
                ]);
            }
        } else {
            $pagesQuery->whereDoesntHave('paragraphs');
        }

        $pages = $pagesQuery->get();

        if ($pages->isEmpty()) {
            return back()->withErrors([
                'from_page' => 'Semua halaman pada rentang ini sudah pernah diproses sebelumnya.',
            ]);
        }

        if ($forceVision) {
            // Lets the user override the text/vision heuristic for pages where
            // it guessed wrong — e.g. a heading whose extracted text has real
            // Arabic letters but scrambled word order (heuristic can't catch
            // that, only garbled/near-empty text). Persisted on the page so
            // future reprocesses of it default to vision too.
            $book->pages()->whereIn('id', $pages->pluck('id'))->update(['extraction_method' => 'vision']);
            $pages->each(fn ($page) => $page->extraction_method = 'vision');
        }

        $extractor = app(PdfExtractionService::class);
        $newParagraphIds = [];
        $visionPageIds = [];
        $newUnits = 0;

        foreach ($pages as $page) {
            if ($page->extraction_method === 'vision') {
                // Paragraph count is only known once Gemini reads the page image,
                // so the whole page counts as a single unit of work for now.
                $visionPageIds[] = $page->id;
                $newUnits++;

                continue;
            }

            $paragraphs = $extractor->splitIntoParagraphs($page->raw_text);

            foreach ($paragraphs as $i => $paragraphText) {
                $paragraph = $page->paragraphs()->create([
                    'book_id' => $book->id,
                    'paragraph_number' => $i + 1,
                    'raw_text' => $paragraphText,
                    'status' => 'pending',
                ]);

                $newParagraphIds[] = $paragraph->id;
                $newUnits++;
            }
        }

        $book->update([
            'process_from_page' => $fromPage,
            'process_to_page' => $toPage,
            'total_paragraphs' => $book->total_paragraphs + $newUnits,
            'status' => 'processing',
        ]);

        foreach ($newParagraphIds as $id) {
            ProcessParagraphJob::dispatch($id);
        }

        foreach ($visionPageIds as $id) {
            ProcessPageViaVisionJob::dispatch($id);
        }

        return redirect()->route('books.show', $book)
            ->with('status', "Memproses halaman {$fromPage} sampai {$toPage}...");
    }

    public function status(Book $book)
    {
        $processedPages = $book->pages()
            ->whereHas('paragraphs')
            ->pluck('page_number');

        return response()->json([
            'status' => $book->status,
            'total_pages' => $book->total_pages,
            'total_paragraphs' => $book->total_paragraphs,
            'processed_paragraphs' => $book->processed_paragraphs,
            'progress' => $book->progressPercentage(),
            'error_message' => $book->error_message,
            'processed_pages' => $processedPages,
        ]);
    }

    public function publish(Book $book, HostedSyncService $sync): RedirectResponse
    {
        if ($book->status !== 'completed') {
            return back()->withErrors(['publish' => 'Kitab baru bisa dipublikasikan setelah statusnya selesai.']);
        }

        try {
            $sync->publishBook($book);

            $book->update(['published_at' => now()]);
        } catch (Throwable $e) {
            return back()->withErrors(['publish' => 'Gagal publikasikan: '.$e->getMessage()]);
        }

        return back()->with('status', 'Kitab dikirim untuk dipublikasikan — akan tampil di situs publik dalam beberapa menit.');
    }

    public function destroy(Book $book, HostedSyncService $sync): RedirectResponse
    {
        // If this book came from a hosted "minta kitab" request and never got
        // published, tell hosted to stop showing it as "sedang diproses" —
        // best-effort: a sync hiccup shouldn't block deleting the local book.
        if ($book->remote_request_uuid && ! $book->published_at) {
            try {
                $sync->rejectRequest($book->remote_request_uuid);
            } catch (Throwable $e) {
                // Swallow it — the user can still delete locally either way.
            }
        }

        Storage::disk('local')->delete($book->file_path);
        $book->delete();

        return redirect()->route('books.index')->with('status', 'Kitab berhasil dihapus.');
    }
}
