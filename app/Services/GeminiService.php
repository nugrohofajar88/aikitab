<?php

namespace App\Services;

use App\Services\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiService implements AiProvider
{
    protected string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.gemini.key');
        $this->model = (string) config('services.gemini.model', 'gemini-2.5-flash');
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
        ], $schema, timeoutSeconds: 300, retries: 1);
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
     * @param  array<int, array<string, mixed>>  $parts
     */
    protected function generate(array $parts, array $schema, int $timeoutSeconds = 120, int $retries = 1): array
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY belum diset di .env');
        }

        $response = Http::timeout($timeoutSeconds)
            ->retry($retries, 2000)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}", [
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
            throw new RuntimeException('Gemini API error: '.$response->body());
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

        if (blank($text)) {
            throw new RuntimeException('Gemini tidak mengembalikan konten yang valid.');
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Gagal parse JSON dari Gemini: '.json_last_error_msg());
        }

        return $decoded;
    }
}
