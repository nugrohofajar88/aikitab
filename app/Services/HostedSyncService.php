<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Facades\Http;
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

    public function downloadRequestPdf(int $remoteRequestId): string
    {
        $response = $this->client()->get("{$this->baseUrl}/api/sync/requests/{$remoteRequestId}/download");

        if (! $response->successful()) {
            throw new RuntimeException('Gagal mengunduh PDF permintaan: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Sends the complete finished content of a book to the hosted instance
     * (full replace on their end — see BookSyncController::store). Only
     * successfully-processed ("done") paragraphs are sent.
     */
    public function publishBook(Book $book): int
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

        $response = $this->client()->post("{$this->baseUrl}/api/sync/books", $payload);

        if (! $response->successful()) {
            throw new RuntimeException('Gagal publikasikan kitab: '.$response->body());
        }

        return (int) $response->json('book_id');
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
