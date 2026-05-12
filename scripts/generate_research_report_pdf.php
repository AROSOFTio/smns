<?php

require_once __DIR__ . '/../core/SimplePdfDocument.php';

$root = dirname(__DIR__);
$reportDir = $root . '/docs/research-study-report';
$outputPath = $reportDir . '/smns-research-report.pdf';
$chapters = [
    $reportDir . '/chapter-01-introduction.md',
    $reportDir . '/chapter-02-literature-review.md',
    $reportDir . '/chapter-03-methodology-system-design.md',
    $reportDir . '/chapter-04-implementation-testing.md',
    $reportDir . '/chapter-05-summary-conclusion.md',
];

$pdf = new SimplePdfDocument();

$pageWidth = 595.28;
$pageHeight = 841.89;
$marginLeft = 58.0;
$marginRight = 58.0;
$marginTop = 54.0;
$marginBottom = 58.0;
$lineHeight = 14.0;
$y = $marginTop;
$pageNumber = 0;

$addPage = function () use (&$pdf, &$y, &$pageNumber, $pageWidth, $pageHeight, $marginTop, $marginLeft) {
    $pdf->addPage($pageWidth, $pageHeight);
    $pageNumber++;
    $y = $marginTop;
    $pdf->text($marginLeft, 30, 'Seminary Management and Results System (SMNS)', 9);
    $pdf->line($marginLeft, 42, $pageWidth - $marginLeft, 42, 0.5);
};

$ensureSpace = function (float $needed) use (&$addPage, &$y, $pageHeight, $marginBottom) {
    if (($y + $needed) > ($pageHeight - $marginBottom)) {
        $addPage();
    }
};

$textWidth = function (string $text, float $fontSize): float {
    return strlen($text) * $fontSize * 0.48;
};

$wrapText = function (string $text, float $fontSize, float $maxWidth) use ($textWidth): array {
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
        return [''];
    }

    $words = explode(' ', $text);
    $lines = [];
    $line = '';

    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        if ($textWidth($candidate, $fontSize) <= $maxWidth || $line === '') {
            $line = $candidate;
            continue;
        }

        $lines[] = $line;
        $line = $word;
    }

    if ($line !== '') {
        $lines[] = $line;
    }

    return $lines;
};

$writeBlock = function (string $text, float $fontSize = 10.5, string $style = '', float $spaceAfter = 7.0, float $indent = 0.0) use (&$pdf, &$y, $lineHeight, $marginLeft, $marginRight, $pageWidth, $wrapText, $ensureSpace) {
    $maxWidth = $pageWidth - $marginLeft - $marginRight - $indent;
    $lines = $wrapText($text, $fontSize, $maxWidth);
    $ensureSpace((count($lines) * $lineHeight) + $spaceAfter);

    foreach ($lines as $line) {
        $pdf->text($marginLeft + $indent, $y, $line, $fontSize, $style);
        $y += $lineHeight;
    }

    $y += $spaceAfter;
};

$addPage();
$writeBlock('SEMINARY MANAGEMENT AND RESULTS SYSTEM (SMNS)', 17, 'B', 12);
$writeBlock('Research Study Report', 13, 'B', 18);
$writeBlock('Prepared from the current SMNS project structure.', 10.5, '', 10);
$writeBlock('Date: May 12, 2026', 10.5, '', 28);

foreach ($chapters as $chapterPath) {
    if (!is_file($chapterPath)) {
        continue;
    }

    $lines = file($chapterPath, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            $y += 4;
            continue;
        }

        if (str_starts_with($line, '# ')) {
            $ensureSpace(42);
            $writeBlock(substr($line, 2), 15.5, 'B', 10);
            continue;
        }

        if (str_starts_with($line, '## ')) {
            $ensureSpace(32);
            $writeBlock(substr($line, 3), 12.5, 'B', 7);
            continue;
        }

        if (str_starts_with($line, '### ')) {
            $ensureSpace(28);
            $writeBlock(substr($line, 4), 11.2, 'B', 5);
            continue;
        }

        if (str_starts_with($line, '- ')) {
            $writeBlock('- ' . substr($line, 2), 10.3, '', 3, 14);
            continue;
        }

        $writeBlock($line, 10.5, '', 7);
    }
}

file_put_contents($outputPath, $pdf->outputString());
echo $outputPath . PHP_EOL;

