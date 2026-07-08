<?php

namespace App\Services;

use App\Services\Contracts\AiProvider;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Picks the configured primary AI provider and, if it throws, automatically
 * retries the same call against a fallback provider (e.g. Gemini direct ->
 * OpenRouter) so a transient outage/overload on one provider doesn't stall
 * processing. Jobs depend on this instead of a concrete provider class.
 */
class AiOrchestrator implements AiProvider
{
    protected AiProvider $primary;

    protected ?AiProvider $fallback;

    public function __construct()
    {
        $this->primary = $this->resolveProvider((string) config('services.ai.provider', 'gemini'));

        $fallbackName = config('services.ai.fallback_provider');
        $this->fallback = $fallbackName ? $this->resolveProvider((string) $fallbackName) : null;
    }

    public function annotateParagraph(string $rawArabicText): array
    {
        return $this->attempt(fn (AiProvider $provider) => $provider->annotateParagraph($rawArabicText));
    }

    public function annotatePdfPage(string $pdfBinary): array
    {
        return $this->attempt(fn (AiProvider $provider) => $provider->annotatePdfPage($pdfBinary));
    }

    protected function attempt(Closure $call): array
    {
        try {
            return $call($this->primary);
        } catch (Throwable $primaryError) {
            if (! $this->fallback) {
                throw $primaryError;
            }

            Log::warning('AI provider utama gagal, mencoba fallback', [
                'primary' => get_class($this->primary),
                'fallback' => get_class($this->fallback),
                'error' => $primaryError->getMessage(),
            ]);

            try {
                return $call($this->fallback);
            } catch (Throwable $fallbackError) {
                throw new RuntimeException(
                    'Provider utama gagal: '.$primaryError->getMessage()
                    .' | Provider fallback juga gagal: '.$fallbackError->getMessage()
                );
            }
        }
    }

    protected function resolveProvider(string $name): AiProvider
    {
        return match ($name) {
            'gemini' => app(GeminiService::class),
            'openrouter' => app(OpenRouterService::class),
            default => throw new InvalidArgumentException("AI provider tidak dikenal: {$name}"),
        };
    }
}
