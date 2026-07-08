<?php

namespace App\Services;

use setasign\Fpdi\Fpdi;

class PdfPageSlicer
{
    /**
     * Extract a single page from a source PDF into a standalone one-page PDF,
     * returned as raw binary. Pure PHP (FPDI), no system binaries required —
     * safe to run on shared hosting.
     */
    public function slicePage(string $absoluteSourcePdfPath, int $pageNumber): string
    {
        $pdf = new Fpdi();
        $pdf->setSourceFile($absoluteSourcePdfPath);

        $templateId = $pdf->importPage($pageNumber);
        $size = $pdf->getTemplateSize($templateId);

        $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
        $pdf->AddPage($orientation, [$size['width'], $size['height']]);
        $pdf->useTemplate($templateId);

        return $pdf->Output('S');
    }
}
