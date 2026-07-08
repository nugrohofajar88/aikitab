<?php

namespace App\Services;

use App\Services\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * OpenRouter (openrouter.ai) — a gateway to many underlying models via a single
 * OpenAI-compatible chat completions API. Used as an alternative/fallback to
 * calling Gemini directly, in case Gemini is overloaded (503) or down.
 */
class OpenRouterService implements AiProvider
{
    protected string $apiKey;

    protected string $model;

    public function __construct()
    {
        $this->apiKey = (string) config('services.openrouter.key');
        $this->model = (string) config('services.openrouter.model', 'google/gemini-2.5-flash');
    }

    public function annotateParagraph(string $rawArabicText): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'harakat_text' => ['type' => 'string'],
                'sentences' => $this->sentencesSchema(),
            ],
            'required' => ['harakat_text', 'sentences'],
            'additionalProperties' => false,
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

        return $this->generate([
            ['type' => 'text', 'text' => $prompt],
        ], $schema, 'kitab_paragraph');
    }

    public function annotatePdfPage(string $pdfBinary): array
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'paragraphs' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'harakat_text' => ['type' => 'string'],
                            'sentences' => $this->sentencesSchema(),
                        ],
                        'required' => ['harakat_text', 'sentences'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['paragraphs'],
            'additionalProperties' => false,
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
            ['type' => 'text', 'text' => $prompt],
            [
                'type' => 'file',
                'file' => [
                    'filename' => 'page.pdf',
                    'file_data' => 'data:application/pdf;base64,'.base64_encode($pdfBinary),
                ],
            ],
        ], $schema, 'kitab_page', timeoutSeconds: 300, retries: 1);
    }

    protected function sentencesSchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'arabic' => ['type' => 'string'],
                    'translation' => ['type' => 'string'],
                    'words' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'arabic' => ['type' => 'string'],
                                'translation' => ['type' => 'string'],
                                'grammar' => ['type' => 'string'],
                            ],
                            'required' => ['arabic', 'translation', 'grammar'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['arabic', 'translation', 'words'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     */
    protected function generate(array $content, array $schema, string $schemaName, int $timeoutSeconds = 120, int $retries = 2): array
    {
        if (blank($this->apiKey)) {
            throw new RuntimeException('OPENROUTER_API_KEY belum diset di .env');
        }

        $response = Http::withToken($this->apiKey)
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout($timeoutSeconds)
            ->retry($retries, 2000)
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'user', 'content' => $content],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $schemaName,
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenRouter API error: '.$response->body());
        }

        $text = data_get($response->json(), 'choices.0.message.content');

        if (blank($text)) {
            throw new RuntimeException('OpenRouter tidak mengembalikan konten yang valid.');
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new RuntimeException('Gagal parse JSON dari OpenRouter: '.json_last_error_msg());
        }

        return $decoded;
    }
}
