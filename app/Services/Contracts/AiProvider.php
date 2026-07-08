<?php

namespace App\Services\Contracts;

/**
 * Common contract every AI provider (Gemini, OpenRouter, ...) must implement
 * so jobs can swap between them (or fall back from one to another) without
 * caring which provider is actually doing the work.
 */
interface AiProvider
{
    /**
     * Send a raw Arabic paragraph and get back harakat + word/sentence translations.
     *
     * @return array{harakat_text: string, sentences: array<int, array{arabic: string, translation: string, words: array<int, array{arabic: string, translation: string, grammar: string}>}>}
     */
    public function annotateParagraph(string $rawArabicText): array;

    /**
     * Read a single-page PDF slice directly (vision), for pages whose extracted
     * text layer is unusable (broken font encoding or scanned/no text layer).
     *
     * @return array{paragraphs: array<int, array{harakat_text: string, sentences: array<int, array{arabic: string, translation: string, words: array<int, array{arabic: string, translation: string, grammar: string}>}>}>}
     */
    public function annotatePdfPage(string $pdfBinary): array;
}
