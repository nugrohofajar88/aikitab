<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractBookPdfJob;
use App\Models\Book;
use App\Services\HostedSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

class RequestSyncController extends Controller
{
    public function index(HostedSyncService $sync): View
    {
        try {
            $requests = $sync->fetchPendingRequests();
            $error = null;
        } catch (Throwable $e) {
            $requests = [];
            $error = $e->getMessage();
        }

        return view('requests.index', compact('requests', 'error'));
    }

    public function import(Request $request, HostedSyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'remote_id' => ['required', 'integer'],
            'uuid' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'author' => ['nullable', 'string', 'max:255'],
            'original_filename' => ['required', 'string'],
        ]);

        try {
            $sync->claimRequest((int) $validated['remote_id']);
            $pdfBinary = $sync->downloadRequestPdf((int) $validated['remote_id']);
        } catch (Throwable $e) {
            return back()->withErrors(['import' => 'Gagal mengambil permintaan: '.$e->getMessage()]);
        }

        $path = 'books/'.uniqid('req_', true).'.pdf';
        Storage::disk('local')->put($path, $pdfBinary);

        $book = Book::create([
            'title' => $validated['title'],
            'author' => $validated['author'] ?? null,
            'original_filename' => $validated['original_filename'],
            'file_path' => $path,
            'status' => 'uploaded',
            'remote_request_uuid' => $validated['uuid'],
        ]);

        ExtractBookPdfJob::dispatch($book);

        return redirect()->route('books.show', $book)
            ->with('status', 'Permintaan diimpor. Menyiapkan preview...');
    }
}
