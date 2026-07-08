# AGENTS.md — KitabAI

Context file for AI coding agents (Claude Code, Codex, Cursor, etc.) working on this repo. Generic, tool-agnostic — update it whenever architecture or known gotchas change.

## What this is

KitabAI (MVP v1.0): a personal Laravel app that turns Arabic PDF kitab (classical Islamic texts) into an interactive digital reader — auto harakat (tashkil), word-by-word translation, sentence translation, and (added beyond original MVP scope) per-word nahwu/shorof (i'rab) grammar analysis on hover.

Original PRD scope explicitly excluded OCR, RAG, chatbot, Docker, Supabase/Postgres. Those calls still hold except where noted below.

## Stack

- Laravel 13, PHP 8.4 (via Laravel Herd on this dev machine — `php`/`composer` resolve through Herd's shims)
- MySQL (not SQLite as first drafted, not Herd-managed — runs via XAMPP)
- Blade + Tailwind v4 (`@tailwindcss/vite`) + Alpine.js
- `smalot/pdfparser` — PDF text extraction (pure PHP)
- `setasign/fpdi` + `setasign/fpdf` — slices single pages out of a source PDF (pure PHP, **no Ghostscript/Imagick/poppler needed** — this was a deliberate choice so the app stays deployable on shared hosting)
- Google Gemini 2.5 Flash (primary AI provider, `services.gemini.*` in `config/services.php`) + OpenRouter (`services.openrouter.*`, fallback provider) — both called via `Illuminate\Support\Facades\Http`, no SDK package. See "Multi-provider AI" below.

## Architecture / pipeline

1. **Upload** (`BookController::store`) — saves PDF to `storage/app/private/books`, creates `Book` (status `uploaded`), dispatches `ExtractBookPdfJob`.
2. **Extraction** (`ExtractBookPdfJob`) — parses every page's raw text via `PdfExtractionService`, creates one `Page` row per page (**no paragraphs yet, no AI call yet** — extraction is deliberately separated from AI processing so the user can preview the PDF and pick a page range before anything gets sent to Gemini). Sets `Page.extraction_method` to `text` or `vision` based on `PdfExtractionService::needsVisionFallback()` — a heuristic that checks the ratio of real Arabic Unicode chars in the extracted text; low ratio (or near-empty) means the PDF's font has a broken/missing ToUnicode CMap (very common with older kitab PDFs made in Arabic DTP tools) or the page is a scanned image. Book status → `ready`.
3. **User picks a page range** in the viewer (`books/show.blade.php`) — PDF preview via `<iframe>` pointed at `BookController::file`, plus a "Daftar Halaman" grid showing per-page status, plus a from/to form (`BookController::process`) with a "paksa proses ulang" (force) checkbox to redo already-processed pages.
4. **Per-page AI processing**, routed by `extraction_method`:
   - `text` pages → `PdfExtractionService::splitIntoParagraphs()` splits raw text into paragraph rows (status `pending`), one `ProcessParagraphJob` dispatched per paragraph → `AiOrchestrator::annotateParagraph()` (plain text prompt).
   - `vision` pages → one `ProcessPageViaVisionJob` dispatched per page (paragraph count isn't known until the AI responds, so **1 page = 1 unit of progress**, not 1 paragraph) → `PdfPageSlicer::slicePage()` cuts that single page out of the source PDF (pure PHP, FPDI) → `AiOrchestrator::annotatePdfPage()` sends the PDF page as a file attachment directly to the AI, which reads it as an image internally and returns paragraphs itself.
5. Both paths ask for the same JSON shape per sentence: `arabic` (harakat text), `translation`, and `words[]` (each with `arabic`, `translation`, `grammar` — the nahwu/shorof i'rab explanation, added after the original MVP). Schema enforced via structured-output mode (Gemini's `responseSchema`, OpenRouter's `response_format.json_schema`).
6. **Progress tracking**: `Book.total_paragraphs` / `Book.processed_paragraphs` count *units* (paragraphs for text pages, whole pages for vision pages), not literal paragraph rows. Both job classes increment `processed_paragraphs` and check completion **inside a `finally` block** — this is load-bearing, see gotchas below.
7. **Viewer**: `books/show.blade.php` — Alpine.js `bookViewer()` component. `mode` controls display (Arab / +per-word / +sentence / Lengkap), `currentPage` shows one page at a time (not an infinite-scroll list) with prev/next nav, polls `BookController::status` every 3s while status is `uploaded`/`extracting`/`processing` and reloads on terminal state.

## Multi-provider AI (Gemini primary, OpenRouter fallback)

Jobs never talk to `GeminiService`/`OpenRouterService` directly — they depend on `App\Services\AiOrchestrator`, which implements the same `App\Services\Contracts\AiProvider` interface (`annotateParagraph()`, `annotatePdfPage()`) and internally: tries the provider named by `AI_PROVIDER` (default `gemini`), and if that throws, tries the provider named by `AI_FALLBACK_PROVIDER` (default `openrouter`) before giving up. Logs a `warning` on every fallback (`storage/logs/laravel.log`, message "AI provider utama gagal, mencoba fallback") — grep that to see how often it's actually kicking in.

This exists because Gemini's **free tier caps at 20 requests/day** (`generate_content_free_tier_requests`, confirmed via a real `429 RESOURCE_EXHAUSTED` response during dev) — trivial to blow through while iterating, and it also periodically returns transient `503` "model overloaded". OpenRouter (`openrouter.ai`, OpenAI-compatible chat completions API, model configured via `OPENROUTER_MODEL`) is used as the second opinion.

**Known quality gap, by design decision not a bug to "fix" blindly:** the OpenRouter fallback is excellent for `annotateParagraph` (text-only) — full harakat, accurate translation, same grammar quality as Gemini direct, verified side by side. It is **unreliable for `annotatePdfPage` (vision/PDF)** — sometimes reads the page correctly but omits harakat, and has been observed returning a single stray digit (e.g. a page number) as the entire response with HTTP 200, i.e. garbage that looks like success. `ProcessPageViaVisionJob::looksLikeRealContent()` guards against the latter (rejects any returned paragraph whose `harakat_text` is under ~15 chars or has an empty `sentences` array, marking the paragraph `failed` instead of silently accepting junk) — but it can't detect "read correctly but no harakat," so vision-fallback results should be treated as lower-confidence than the primary-provider path. If you need to change which model OpenRouter uses, re-verify harakat quality on an actual vision call before trusting it, not just the text path — they behave differently on the same model.

Don't use `google/gemini-2.5-flash-image` as the OpenRouter model — that's Gemini's image-*generation* variant and rejects `response_format: json_schema` outright ("JSON mode is not enabled for this model"). Use the plain `google/gemini-2.5-flash` (or another OpenRouter-listed reasoning model) instead.

## Two-app architecture: this app (producer) + `ai-kitab-public` (publisher)

This app does all the heavy lifting (upload, extraction, AI processing) and is meant to stay local/private — it's not built for public hosting (needs a persistent `queue:work`, burns AI API calls, exposes upload/reprocess controls). A **separate sibling Laravel project, `ai-kitab-public`** (own repo at `c:\xampp\htdocs\ai-kitab-public`, own DB `kitabai_public`), is the public-facing half: a read-only viewer plus a "minta kitab" (request a book) form for visitors, with **no AI calls and no queue worker of its own** — everything it shows was pushed to it already-finished. See that project's own `AGENTS.md` for its internals.

The two talk over a small HTTP API on the hosted app (`routes/api.php` there, prefixed `/api/sync/*`), authenticated by a shared-secret bearer token (`HOSTED_SYNC_TOKEN` here must equal `SYNC_TOKEN` in the hosted app's `.env` — not the same name on each side, don't let that trip you up). This app's `App\Services\HostedSyncService` is the only thing that calls it:

- **Publish (this app → hosted)**: `BookController::publish` (route `POST /books/{book}/publish`, only shown once a book's status is `completed`) → `HostedSyncService::publishBook()` → `POST {hosted}/api/sync/books`. Sends only `status = 'done'` paragraphs (full replace on the hosted side — it deletes and recreates that book's pages/paragraphs every time, no diffing). Sets `Book.remote_book_id` + `Book.published_at` here on success.
- **Request pull (hosted → this app)**: a visitor uploads a PDF on the hosted site, which just stores it as a pending `BookRequest` there — nothing happens automatically. Someone has to open `/permintaan` here (`RequestSyncController::index`, manual button on the books index page — deliberately not a background poller) and click "Impor & Proses" per request (`RequestSyncController::import`) → claims it on hosted (marks it `claimed` so it won't show as pending again), downloads the PDF, creates a normal local `Book` with `remote_request_uuid` set to the hosted request's uuid, and runs it through the exact same `ExtractBookPdfJob` pipeline as a manual upload. When that book is later published, the `remote_request_uuid` rides along in the publish payload so the hosted app can mark its `BookRequest` `completed` and link it to the new hosted book — closing the loop back to the visitor's own status page.

Gotchas specific to this cross-app sync (hit during initial build, verified fixed):

- **Laravel's HTTP client needs `->acceptJson()` explicitly, or you get silent wrong behavior instead of an error.** Without it, a validation failure on the hosted API renders as an HTML redirect (Laravel's default behavior for requests that don't declare they want JSON) instead of a JSON error body. Worse: `Illuminate\Http\Client\Response::failed()` only checks for 4xx/5xx — a 3xx redirect passes right through it as "not failed," so the bug doesn't throw, it just silently produces garbage (we saw `publishBook()` return `remote_book_id = 0` this way, no exception, `published_at` set like everything worked). `HostedSyncService::client()` now sets `acceptJson()` **and** `allow_redirects: false` so any future redirect surfaces as a loud error, and every call site checks `! $response->successful()` (not `failed()`) to actually catch it.
- **Laravel's `required` validation rule rejects an empty array.** `BookSyncController::store()` on the hosted side originally had `'pages' => ['required', 'array']` — but a book where every single paragraph failed to process legitimately has zero pages worth publishing, and `[]` fails `required` ("The pages field is required.") even though the key is present. Fixed to `['present', 'array']`. If you add more array fields to that endpoint's validation, remember `required` ≠ "key exists," it also means "non-empty" for arrays.

## Key files

- `app/Services/Contracts/AiProvider.php` — the interface both providers and the orchestrator implement.
- `app/Services/AiOrchestrator.php` — provider selection + automatic fallback. Jobs depend on this, not a concrete provider.
- `app/Services/GeminiService.php` / `app/Services/OpenRouterService.php` — one HTTP call each per method, same prompts/schema shape (adapted per provider's structured-output format), shared `sentencesSchema()`, shared `generate()` (HTTP call + JSON parsing, configurable timeout/retries per call site).
- `app/Services/PdfExtractionService.php` — text extraction + paragraph splitting + the vision-fallback heuristic.
- `app/Services/PdfPageSlicer.php` — FPDI single-page slicing.
- `app/Jobs/ExtractBookPdfJob.php`, `ProcessParagraphJob.php`, `ProcessPageViaVisionJob.php`.
- `app/Http/Controllers/BookController.php` — `index`, `store`, `show`, `file` (streams PDF for the iframe), `process` (page-range submission incl. force-reprocess), `status` (polling JSON), `publish` (push to hosted), `destroy`.
- `app/Http/Controllers/RequestSyncController.php` — `index` (list pending hosted requests), `import` (claim + download + create local Book).
- `app/Services/HostedSyncService.php` — the only thing that talks to the hosted app's API; see "Two-app architecture" above.
- `resources/views/books/show.blade.php` — the whole viewer UI + Alpine logic lives in this one file (including the `<script>` at the bottom via `@push('scripts')`).
- `resources/views/requests/index.blade.php` — the local "permintaan" list + import form.

## Known gotchas (learned the hard way this session — don't re-break these)

- **Restart `queue:work` after every code change.** PHP queue workers load code once into memory and keep running; editing a Job/Service class does nothing until the worker process is killed and restarted. This bit us repeatedly (stale `Fpdi` autoload error, old timeout values, etc.). Also watch for **duplicate workers** — check `Get-CimInstance Win32_Process | Where-Object Name -eq 'php.exe'` before assuming a restart actually took effect; an old worker left running will race the new one for jobs in the same `jobs` table.
- **Windows has no `pcntl` extension.** Laravel's per-job `$timeout` property (meant to force-kill a hung job) relies on `pcntl_alarm` and **silently does nothing on Windows**. The only real timeout enforcement here is the underlying HTTP client's own `Http::timeout()` in each provider's `generate()` method. Don't trust the job's `$timeout` property alone when reasoning about this app on Windows; it's set for correctness/documentation and for when this eventually runs on a Linux host, but the HTTP timeout is what actually fires.
- **Gemini vision calls are slow, and adding the `grammar` field made them slower.** Timeout tuning history: started at 120s default → bumped to 240s → over-corrected down to 90s (caused a wave of timeouts) → 180s (still timed out on a dense page) → currently **300s, 1 retry** per provider attempt (`annotatePdfPage`). If you add more per-word/per-sentence fields to the schema, expect to need more headroom again. A page with many short paragraphs (i.e. a lot of individual `words[]` entries needing `grammar` text) is the slow case, not page count.
- **Job timeouts got bigger after adding the OpenRouter fallback, on purpose.** `ProcessParagraphJob.$timeout = 550`, `ProcessPageViaVisionJob.$timeout = 1300` — both sized to cover a full primary-provider attempt *plus* a full fallback-provider attempt back to back (worst case: both providers slow/retrying before one finally answers). Per-provider `generate()` retry counts were deliberately turned down (from 2 to 1) when the orchestrator was added, since cross-provider fallback is now the main resilience mechanism — don't crank per-provider retries back up without also revisiting these job timeouts, the two are coupled.
- **`ProcessPageViaVisionJob` and `ProcessParagraphJob` MUST use `try/catch/finally`, not `try/catch`.** The progress-counter increment (`$book->increment('processed_paragraphs')`) and completion check live in `finally` specifically so they always run even if something throws outside the expected Gemini-call try block. We hit a real bug from this: the vision job's failure-path fallback used to do `->create()` for a placeholder failed paragraph — on a retry of an already-failed page this hit the `paragraphs_page_id_paragraph_number_unique` constraint (duplicate `page_id`+`paragraph_number`), threw an uncaught `PDOException`, skipped the counter increment entirely, and left `Book.processed_paragraphs` permanently short of `total_paragraphs` (progress bar stuck under 100% forever). Fixed by switching those fallback writes to `updateOrCreate()` and moving the counter logic into `finally`. If you touch these jobs again, preserve both of those properties.
- **MySQL (XAMPP) is not a Windows service here** — it doesn't auto-start. Start it with `C:\xampp\mysql_start.bat`, or directly: `mysql\bin\mysqld.exe --defaults-file=mysql\bin\my.ini --standalone --console` from `C:\xampp`. Check port 3306 before assuming it's up.
- **Tailwind classes require `npm run build`.** There's no dev-server watching in normal use here; new utility classes added to Blade won't appear until you rebuild (`npm run build`), because the compiled CSS in `public/build` is static. Bit us on the iframe height and several UI additions — always rebuild after touching class names in Blade files.
- **`whereDoesntHave('paragraphs')` treats "has a failed paragraph" the same as "fully done."** `BookController::process()` only reconsiders a page for (re)processing if it has zero paragraph rows, or if the `force` checkbox is passed (which deletes existing paragraphs for the selected range first and backs the unit counts out of `total_paragraphs`/`processed_paragraphs` before recreating them). There's no per-page "retry just this one" affordance yet — force-reprocess re-does the whole submitted range.
- **The Gemini API key format in `.env` for this project starts with `AQ.` rather than the more familiar `AIzaSy...` prefix** — that's not a mistake, it's been verified working directly against `generativelanguage.googleapis.com`.

## Local dev loop

```
php artisan serve          # or let Herd/XAMPP serve it
php artisan queue:work     # required — QUEUE_CONNECTION=database, nothing processes without this running
npm run build              # after any Blade class-name changes; `npm run dev` also works if you want live rebuild
```

`GEMINI_API_KEY`/`GEMINI_MODEL`, `OPENROUTER_API_KEY`/`OPENROUTER_MODEL`, `AI_PROVIDER`/`AI_FALLBACK_PROVIDER`, and `HOSTED_API_URL`/`HOSTED_SYNC_TOKEN` live in `.env` (see `config/services.php`). DB is `kitabai` on `127.0.0.1:3306`, user `root`, no password (local dev only).

To exercise publish/import against the hosted app locally, also run `ai-kitab-public` side by side (`cd ../ai-kitab-public && php artisan serve --port=8001`, no queue worker needed there) with `HOSTED_API_URL=http://127.0.0.1:8001` here and matching tokens on both sides.

## Deployment note

This app itself is meant to **stay local, not get hosted publicly** — that's the whole reason `ai-kitab-public` exists (see "Two-app architecture" above). Ghostscript/Tesseract/Imagick were still deliberately avoided here anyway (everything PDF-related is pure PHP: `smalot/pdfparser`, `setasign/fpdi`) since it costs nothing and keeps the option open. If this app ever does need to run somewhere reachable, the remaining blocker is the persistent `queue:work` process; the fix would be a cron-triggered `queue:work --stop-when-empty` instead of a long-running worker — zero code changes, just hosting-side cron config.

The hosted app (`ai-kitab-public`) has no such blocker — it never dispatches jobs at all, so it's plain request/response and deployable anywhere Laravel runs, shared hosting included.

## Roadmap (from original PRD, v0.1 = this MVP)

- v0.2 Bookmark/Search — not started
- v0.3 Nahwu & Shorof — **partially done**: per-word `grammar` (i'rab) field already implemented as a hover tooltip, ahead of schedule
- v0.4 Chat AI — not started
- v0.5 Export PDF — not started
