<?php

class SimplePdfDocument
{
    private array $pages = [];
    private int $currentPageIndex = -1;

    public function addPage(float $width, float $height): void
    {
        $this->pages[] = [
            'width' => $width,
            'height' => $height,
            'content' => '',
        ];
        $this->currentPageIndex = count($this->pages) - 1;
    }

    public function text(float $x, float $yFromTop, string $text, float $fontSize = 10.0, string $style = ''): void
    {
        $page = $this->getCurrentPage();
        $fontName = strtoupper($style) === 'B' ? 'F2' : 'F1';
        $safeText = $this->escapeText($text);
        $y = $page['height'] - $yFromTop;

        $this->append(sprintf(
            "BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
            $fontName,
            $fontSize,
            $x,
            $y,
            $safeText
        ));
    }

    public function line(float $x1, float $y1FromTop, float $x2, float $y2FromTop, float $lineWidth = 1.0): void
    {
        $page = $this->getCurrentPage();
        $y1 = $page['height'] - $y1FromTop;
        $y2 = $page['height'] - $y2FromTop;

        $this->append(sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $lineWidth, $x1, $y1, $x2, $y2));
    }

    public function rect(float $x, float $yFromTop, float $width, float $height, float $lineWidth = 1.0): void
    {
        $page = $this->getCurrentPage();
        $y = $page['height'] - $yFromTop - $height;

        $this->append(sprintf("%.2F w %.2F %.2F %.2F %.2F re S\n", $lineWidth, $x, $y, $width, $height));
    }

    public function fillRect(float $x, float $yFromTop, float $width, float $height, float $gray = 0.95): void
    {
        $page = $this->getCurrentPage();
        $y = $page['height'] - $yFromTop - $height;
        $shade = max(0.0, min(1.0, $gray));

        $this->append(sprintf("q %.3F g %.2F %.2F %.2F %.2F re f Q\n", $shade, $x, $y, $width, $height));
    }

    public function outputString(): string
    {
        if (empty($this->pages)) {
            $this->addPage(595.28, 841.89);
        }

        $objects = [];
        $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";

        $pageCount = count($this->pages);
        $firstPageObjectId = 5;
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = ($firstPageObjectId + ($i * 2)) . " 0 R";
        }
        $objects[] = "<< /Type /Pages /Count {$pageCount} /Kids [ " . implode(' ', $kids) . " ] >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

        foreach ($this->pages as $pageIndex => $page) {
            $pageObjectId = $firstPageObjectId + ($pageIndex * 2);
            $contentObjectId = $pageObjectId + 1;
            $stream = $page['content'];
            $objects[] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>",
                $page['width'],
                $page['height'],
                $contentObjectId
            );
            $objects[] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $index => $objectBody) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n" . $objectBody . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }

    private function &getCurrentPage(): array
    {
        if ($this->currentPageIndex < 0 || !isset($this->pages[$this->currentPageIndex])) {
            $this->addPage(595.28, 841.89);
        }

        return $this->pages[$this->currentPageIndex];
    }

    private function append(string $command): void
    {
        $page = &$this->getCurrentPage();
        $page['content'] .= $command;
    }

    private function escapeText(string $text): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if ($text === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }

        $text = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
        return $text;
    }
}
