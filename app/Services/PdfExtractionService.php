<?php

namespace App\Services;

use Smalot\PdfParser\Parser;

class PdfExtractionService
{
    /**
     * Extract raw text from a PDF, one entry per page.
     *
     * @return array<int, string> 1-indexed by page number
     */
    public function extractPages(string $absoluteFilePath): array
    {
        $parser = new Parser();
        $pdf = $parser->parseFile($absoluteFilePath);

        $pages = [];

        foreach ($pdf->getPages() as $index => $page) {
            $pages[$index + 1] = $this->normalizeWhitespace($page->getText());
        }

        return $pages;
    }

    /**
     * Split a page's raw text into paragraphs.
     *
     * @return array<int, string>
     */
    public function splitIntoParagraphs(string $pageText): array
    {
        $pageText = trim($pageText);

        if ($pageText === '') {
            return [];
        }

        // Paragraphs are usually separated by a blank line in extracted text.
        $chunks = preg_split('/\n\s*\n/u', $pageText) ?: [$pageText];
        $chunks = array_values(array_filter(array_map('trim', $chunks), fn ($c) => $c !== ''));

        if (count($chunks) > 1) {
            return $chunks;
        }

        // Fallback: no blank-line separated paragraphs found, split by single line breaks.
        $lines = array_values(array_filter(array_map('trim', explode("\n", $pageText)), fn ($l) => $l !== ''));

        return $lines !== [] ? $lines : [$pageText];
    }

    protected function normalizeWhitespace(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Detect pages whose text layer can't be trusted: either unreadable garbage
     * (common with kitab PDFs built on custom fonts lacking a proper ToUnicode
     * CMap) or effectively empty (scanned/image-only page with no text layer).
     * Such pages need the Gemini PDF-vision fallback instead of the raw text.
     */
    public function needsVisionFallback(string $pageText): bool
    {
        $stripped = preg_replace('/\s+/u', '', $pageText) ?? '';

        if (mb_strlen($stripped) < 20) {
            return true;
        }

        preg_match_all('/\p{Arabic}/u', $stripped, $matches);
        $arabicCount = count($matches[0]);

        return ($arabicCount / mb_strlen($stripped)) < 0.3;
    }
}
