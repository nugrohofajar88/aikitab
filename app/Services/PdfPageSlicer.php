<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use Throwable;

class PdfPageSlicer
{
    /**
     * Extract a single page from a source PDF into a standalone one-page PDF,
     * returned as raw binary. Pure PHP (FPDI), no system binaries required —
     * safe to run on shared hosting.
     *
     * Some PDFs (e.g. re-saved by online "unlock"/"compress" tools) use a
     * compression technique FPDI's free parser can't read. When that happens
     * we fall back to a Ghostscript-repaired copy of the source file, cached
     * next to the original so repeated pages don't re-run Ghostscript.
     */
    public function slicePage(string $absoluteSourcePdfPath, int $pageNumber): string
    {
        try {
            return $this->sliceUsing($absoluteSourcePdfPath, $pageNumber);
        } catch (Throwable $e) {
            $repairedPath = $this->repairedPathFor($absoluteSourcePdfPath);

            if (! is_file($repairedPath)) {
                $this->repairWithGhostscript($absoluteSourcePdfPath, $repairedPath);
            }

            return $this->sliceUsing($repairedPath, $pageNumber);
        }
    }

    protected function sliceUsing(string $absoluteSourcePdfPath, int $pageNumber): string
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

    protected function repairedPathFor(string $sourcePath): string
    {
        return dirname($sourcePath).'/'.pathinfo($sourcePath, PATHINFO_FILENAME).'.gsrepaired.pdf';
    }

    protected function repairWithGhostscript(string $sourcePath, string $outputPath): void
    {
        $binary = config('services.ghostscript.binary', 'gs');

        $result = Process::timeout(300)->run([
            $binary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dNOPAUSE',
            '-dBATCH',
            '-dQUIET',
            '-o', $outputPath,
            $sourcePath,
        ]);

        if (! $result->successful() || ! is_file($outputPath)) {
            throw new RuntimeException('Ghostscript gagal memperbaiki PDF: '.$result->errorOutput());
        }
    }
}
