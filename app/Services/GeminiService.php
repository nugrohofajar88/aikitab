<?php

namespace App\Services;

use App\Models\GoogleApiKey;
use App\Services\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GeminiService implements AiProvider
{
    /**
     * Google's stated free-tier daily limit per (key, model) pair, as seen in
     * the 429 RESOURCE_EXHAUSTED quota error body (quotaValue). Differs per
     * model — Flash Lite variants have been observed with much higher daily
     * caps than the base Flash model. Only used for the "X/Y today" badge and
     * has no bearing on request behavior; Google's side is what actually
     * rejects requests once a real limit is hit.
     */
    public const MODEL_DAILY_LIMITS = [
        'gemini-2.5-flash' => 20,
        'gemini-2.5-flash-lite' => 20,
        'gemini-3.1-flash-lite' => 500,
    ];

    public const DEFAULT_DAILY_LIMIT = 20;

    public static function dailyLimitFor(string $model): int
    {
        return self::MODEL_DAILY_LIMITS[$model] ?? self::DEFAULT_DAILY_LIMIT;
    }

    /**
     * All configured Gemini API keys: the primary one (GEMINI_API_KEY) plus
     * any extras from GEMINI_API_KEYS_EXTRA (comma-separated) — e.g. a second
     * Google account's key added once the first one hits its daily quota.
     *
     * @return array<int, string>
     */
    public static function keyPool(): array
    {
        $primary = (string) config('services.gemini.key');
        $extra = (string) config('services.gemini.extra_keys');

        return collect([$primary])
            ->merge(explode(',', $extra))
            ->map(fn ($key) => trim($key))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Models to try, in priority order (GEMINI_MODEL_PRIORITY, comma-separated).
     * Falls back to the single GEMINI_MODEL for backward compatibility if the
     * priority list isn't configured.
     *
     * @return array<int, string>
     */
    public static function modelPriority(): array
    {
        $configured = (string) config('services.gemini.model_priority');

        $list = collect(explode(',', $configured))
            ->map(fn ($model) => trim($model))
            ->filter()
            ->values()
            ->all();

        if ($list !== []) {
            return $list;
        }

        return [(string) config('services.gemini.model', 'gemini-2.5-flash')];
    }

    /**
     * Total used / total capacity today across every (model, key) combo this
     * app is configured to try — for the "Gemini hari ini: X/Y" nav badge.
     *
     * @return array{0: int, 1: int} [used, capacity]
     */
    public static function todayUsageSummary(): array
    {
        $pool = self::keyPool();
        $models = self::modelPriority();
        $keyCount = count($pool);

        $used = 0;
        $capacity = 0;

        foreach ($models as $model) {
            $used += GoogleApiKey::poolRequestsToday($pool, $model);
            $capacity += self::dailyLimitFor($model) * $keyCount;
        }

        return [$used, $capacity];
    }

    /**
     * Full (model, key) cascade in priority order: outer loop by model
     * priority, inner loop by whichever key has the least usage logged today
     * for that specific model. `generate()` walks this list in order and
     * only gives up (throwing, which sends the caller to the OpenRouter
     * fallback) once every combination has failed.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    protected static function buildMatrix(): array
    {
        $pool = self::keyPool();
        $matrix = [];

        foreach (self::modelPriority() as $model) {
            foreach (GoogleApiKey::sortKeysByUsage($pool, $model) as $apiKey) {
                $matrix[] = [$model, $apiKey];
            }
        }

        return $matrix;
    }

    /**
     * Send a raw Arabic paragraph to Gemini and get back harakat + word/sentence translations.
     *
     * @return array{harakat_text: string, sentences: array<int, array{arabic: string, translation: string, words: array<int, array{arabic: string, translation: string, grammar: string}>}>}
     */
    public function annotateParagraph(string $rawArabicText): array
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'harakat_text' => ['type' => 'STRING'],
                'sentences' => $this->sentencesSchema(),
            ],
            'required' => ['harakat_text', 'sentences'],
        ];

        $prompt = <<<PROMPT
        Kamu adalah ahli bahasa Arab klasik dan penerjemah kitab kuning (turats) ke Bahasa Indonesia.

        Tugas: proses TEKS ARAB berikut (hasil ekstraksi PDF, tanpa harakat, kadang berantakan spasinya):

        1. Berikan harakat (tasykil) yang benar sesuai kaidah nahwu/shorof pada seluruh teks, tanpa mengubah, menambah, atau menghapus kata apa pun.
        2. Pecah teks menjadi kalimat-kalimat (jumlah) yang bermakna.
        3. Untuk tiap kalimat, berikan teks Arab berharakat, terjemahan Bahasa Indonesia yang natural dan sesuai konteks kitab, serta rincian per kata (setiap kata Arab berharakat beserta arti kontekstualnya dalam Bahasa Indonesia).
        4. Untuk tiap kata, berikan juga analisis nahwu/shorof singkat (i'rab) dalam Bahasa Indonesia: jabatan kata dalam kalimat (mis. mubtada', khobar, fa'il, maf'ul bih, na'at, jar-majrur, dst.) dan kedudukan i'rabnya (marfu'/manshub/majrur/majzum) beserta alasan tanda i'rabnya bila relevan.

        Balas HANYA dalam format JSON sesuai skema yang diberikan.

        TEKS ARAB:
        {$rawArabicText}
        PROMPT;

        return $this->generate([['text' => $prompt]], $schema);
    }

    /**
     * Read a single-page PDF slice directly (vision), for pages whose extracted
     * text layer is unusable (broken font encoding or scanned/no text layer).
     * Gemini reads the page image itself and determines paragraph breaks.
     *
     * @return array{paragraphs: array<int, array{harakat_text: string, sentences: array<int, array{arabic: string, translation: string, words: array<int, array{arabic: string, translation: string, grammar: string}>}>}>}
     */
    public function annotatePdfPage(string $pdfBinary): array
    {
        $schema = [
            'type' => 'OBJECT',
            'properties' => [
                'paragraphs' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'harakat_text' => ['type' => 'STRING'],
                            'sentences' => $this->sentencesSchema(),
                        ],
                        'required' => ['harakat_text', 'sentences'],
                    ],
                ],
            ],
            'required' => ['paragraphs'],
        ];

        $prompt = <<<PROMPT
        Kamu adalah ahli bahasa Arab klasik dan penerjemah kitab kuning (turats) ke Bahasa Indonesia.

        Berikut adalah satu halaman PDF kitab Arab (kemungkinan teks PDF-nya tidak bisa diekstrak langsung karena font tidak standar, jadi baca teks Arab langsung dari tampilan visual halaman ini).

        Tugas:
        1. Baca seluruh teks Arab yang tertera pada halaman ini persis sesuai tulisannya.
        2. Pisahkan menjadi paragraf-paragraf sesuai tata letak/struktur pada halaman.
        3. Untuk tiap paragraf, berikan teks Arab lengkap berharakat (tasykil) yang benar, dipecah menjadi kalimat-kalimat.
        4. Untuk tiap kalimat, berikan teks Arab berharakat, terjemahan Bahasa Indonesia yang natural, serta rincian per kata (tiap kata Arab berharakat beserta arti kontekstualnya).
        5. Untuk tiap kata, berikan juga analisis nahwu/shorof singkat (i'rab) dalam Bahasa Indonesia: jabatan kata dalam kalimat (mis. mubtada', khobar, fa'il, maf'ul bih, na'at, jar-majrur, dst.) dan kedudukan i'rabnya (marfu'/manshub/majrur/majzum) beserta alasan tanda i'rabnya bila relevan.

        Abaikan nomor halaman, header, atau footer yang tidak relevan. Balas HANYA dalam format JSON sesuai skema yang diberikan.
        PROMPT;

        return $this->generate([
            ['text' => $prompt],
            [
                'inline_data' => [
                    'mime_type' => 'application/pdf',
                    'data' => base64_encode($pdfBinary),
                ],
            ],
        ], $schema, timeoutSeconds: 300, retries: 0);
    }

    protected function sentencesSchema(): array
    {
        return [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'arabic' => ['type' => 'STRING'],
                    'translation' => ['type' => 'STRING'],
                    'words' => [
                        'type' => 'ARRAY',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'arabic' => ['type' => 'STRING'],
                                'translation' => ['type' => 'STRING'],
                                'grammar' => ['type' => 'STRING'],
                            ],
                            'required' => ['arabic', 'translation', 'grammar'],
                        ],
                    ],
                ],
                'required' => ['arabic', 'translation', 'words'],
            ],
        ];
    }

    /**
     * Walks the (model, key) cascade in priority order (see buildMatrix()),
     * trying each combination until one succeeds. Only throws — sending the
     * caller (AiOrchestrator) on to the OpenRouter fallback — once every
     * combination in the matrix has failed. Per-attempt retries default to 0
     * since the matrix itself is the redundancy mechanism here: retrying the
     * same failing (model, key) pair before moving to the next candidate
     * would just multiply worst-case latency for no benefit.
     *
     * @param  array<int, array<string, mixed>>  $parts
     */
    protected function generate(array $parts, array $schema, int $timeoutSeconds = 120, int $retries = 0): array
    {
        $matrix = self::buildMatrix();

        if ($matrix === []) {
            throw new RuntimeException('Tidak ada GEMINI_API_KEY yang dikonfigurasi.');
        }

        $lastError = null;

        foreach ($matrix as [$model, $apiKey]) {
            try {
                return $this->attempt($parts, $schema, $model, $apiKey, $timeoutSeconds, $retries);
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('Kombinasi model/key Gemini gagal, coba kandidat berikutnya', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException('Semua kombinasi model/key Gemini gagal. Error terakhir: '.$lastError?->getMessage());
    }

    /**
     * @param  array<int, array<string, mixed>>  $parts
     */
    protected function attempt(array $parts, array $schema, string $model, string $apiKey, int $timeoutSeconds, int $retries): array
    {
        GoogleApiKey::recordUsage($apiKey, $model);

        $response = Http::timeout($timeoutSeconds)
            ->retry($retries, 2000)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [
                    ['parts' => $parts],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'temperature' => 0.2,
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini API error ({$model}): ".$response->body());
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (blank($text)) {
            throw new RuntimeException("Gemini ({$model}) tidak mengembalikan konten yang valid.");
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException("Gagal parse JSON dari Gemini ({$model}): ".json_last_error_msg());
        }

        return $decoded;
    }
}
