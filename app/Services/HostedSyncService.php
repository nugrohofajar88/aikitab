<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Talks to the hosted (public, read-only) KitabAI instance: publishes
 * finished books there, and pulls "minta kitab" requests visitors submitted
 * on the hosted site so they can be processed locally. See AGENTS.md for
 * the two-app architecture this is part of.
 */
class HostedSyncService
{
    protected string $baseUrl;

    protected string $token;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.hosted.url'), '/');
        $this->token = (string) config('services.hosted.token');
    }

    /**
     * @return array<int, array{id: int, uuid: string, title: string, author: ?string, requester_name: ?string, requester_note: ?string, original_filename: string, created_at: string}>
     */
    public function fetchPendingRequests(): array
    {
        $response = $this->client()->get("{$this->baseUrl}/api/sync/requests/pending");

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengambil daftar permintaan: '.$response->body());
        }

        return $response->json('requests', []);
    }

    public function claimRequest(int $remoteRequestId): void
    {
        $response = $this->client()->post("{$this->baseUrl}/api/sync/requests/{$remoteRequestId}/claim");

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengklaim permintaan: '.$response->body());
        }
    }

    /**
     * Tells the hosted app to give up on a request it thinks is still
     * "claimed"/in-progress — call this when the locally-imported book for
     * it gets deleted (e.g. processing failed) instead of leaving the
     * visitor's status page stuck on "sedang diproses" forever.
     */
    public function rejectRequest(string $remoteRequestUuid): void
    {
        $response = $this->client()->post("{$this->baseUrl}/api/sync/requests/by-uuid/{$remoteRequestUuid}/reject");

        if (! $response->successful()) {
            throw new RuntimeException('Gagal menandai permintaan sebagai ditolak: '.$response->body());
        }
    }

    public function downloadRequestPdf(int $remoteRequestId): string
    {
        $response = $this->client()->get("{$this->baseUrl}/api/sync/requests/{$remoteRequestId}/download");

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengunduh PDF permintaan: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Publishes a book's complete finished content to the hosted instance.
     *
     * Rather than POSTing the whole book (which can be several MB of JSON for
     * a long kitab — every paragraph's harakat text, translation, and
     * per-word grammar) as one HTTP body, the export is written to a file and
     * pushed to the hosted server directly over FTPS (`hosted_ftp` disk),
     * then a small API call just signals "a file is ready to import". This
     * exists because a large single JSON POST was intermittently failing
     * with "fwrite(): Unable to create temporary file" — Guzzle/PHP spill
     * large request bodies to a temp file once they exceed an in-memory
     * threshold, and that was failing under some (never fully pinned down)
     * conditions. The hosted app's `books:process-imports` command (cron)
     * picks up the file asynchronously — this method does not wait for the
     * import to actually finish, only for the signal to be accepted, so the
     * hosted `Book` may not exist yet the instant this returns.
     */
    public function publishBook(Book $book): void
    {
        $book->loadMissing('pages.paragraphs');

        $pages = $book->pages
            ->map(function ($page) {
                $paragraphs = $page->paragraphs
                    ->where('status', 'done')
                    ->values()
                    ->map(fn ($paragraph, $index) => [
                        'paragraph_number' => $index + 1,
                        'harakat_text' => $paragraph->harakat_text,
                        'content_json' => $paragraph->content_json,
                    ])
                    ->values()
                    ->all();

                return [
                    'page_number' => $page->page_number,
                    'paragraphs' => $paragraphs,
                ];
            })
            ->filter(fn ($page) => $page['paragraphs'] !== [])
            ->values()
            ->all();

        $payload = [
            'source_local_id' => $book->id,
            'title' => $book->title,
            'author' => $book->author,
            'total_pages' => $book->total_pages,
            'request_uuid' => $book->remote_request_uuid,
            'pages' => $pages,
        ];

        $filename = "book-{$book->id}-".now()->timestamp.'.json';

        Storage::disk('hosted_ftp')->put($filename, json_encode($payload));

        $response = $this->client()->post("{$this->baseUrl}/api/sync/books/import-signal", [
            'source_local_id' => $book->id,
            'filename' => $filename,
            'request_uuid' => $book->remote_request_uuid,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengirim sinyal impor: '.$response->body());
        }
    }

    protected function client()
    {
        if (blank($this->baseUrl) || blank($this->token)) {
            throw new RuntimeException('HOSTED_API_URL / HOSTED_SYNC_TOKEN belum diset di .env');
        }

        // acceptJson() matters: without it, a validation failure on the hosted
        // side renders as an HTML redirect (Laravel's default non-JSON error
        // response) instead of a JSON error body. Redirects disabled outright
        // too, and successful() (not failed()) is checked at each call site —
        // a 3xx isn't caught by failed(), which only looks at 4xx/5xx.
        return Http::withToken($this->token)->acceptJson()->timeout(60)
            ->withOptions(['allow_redirects' => false]);
    }
}
