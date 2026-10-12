<?php

/**
 * Generator PDF tabel sederhana tanpa library eksternal.
 * Koordinat API menggunakan titik dari kiri-atas.
 */
class KpPdfDocument
{
    private float $width;
    private float $height;
    private array $pages = [];
    private int $current = -1;
    private string $title = 'KasirPro UMKM';
    private array $embeddedComments = [];

    public function __construct(string $orientation = 'L')
    {
        if (strtoupper($orientation) === 'P') {
            $this->width = 595.28;
            $this->height = 841.89;
        } else {
            $this->width = 841.89;
            $this->height = 595.28;
        }
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function addEmbeddedComment(string $key, string $value): void
    {
        $key = preg_replace('/[^A-Z0-9_-]/i', '', strtoupper($key));
        if ($key !== '') {
            $this->embeddedComments[$key] = $value;
        }
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->current = count($this->pages) - 1;
    }

    public function getWidth(): float { return $this->width; }
    public function getHeight(): float { return $this->height; }

    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, string $align = 'left', ?float $boxWidth = null): void
    {
        $this->ensurePage();
        $encoded = $this->encode($text);
        if ($boxWidth !== null && $align !== 'left') {
            $estimated = strlen($encoded) * $size * 0.48;
            if ($align === 'right') $x += max(0, $boxWidth - $estimated);
            if ($align === 'center') $x += max(0, ($boxWidth - $estimated) / 2);
        }
        $font = $bold ? 'F2' : 'F1';
        $pdfY = $this->height - $y;
        $this->pages[$this->current] .= "BT /{$font} {$size} Tf 0 0 0 rg 1 0 0 1 " . $this->n($x) . ' ' . $this->n($pdfY) . ' Tm (' . $this->escape($encoded) . ") Tj ET\n";
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $gray = 0.85): void
    {
        $this->ensurePage();
        $this->pages[$this->current] .= $this->n($gray) . " G 0.5 w " . $this->n($x1) . ' ' . $this->n($this->height - $y1) . ' m ' . $this->n($x2) . ' ' . $this->n($this->height - $y2) . " l S\n";
    }

    public function fillRect(float $x, float $y, float $w, float $h, float $r, float $g, float $b): void
    {
        $this->ensurePage();
        $this->pages[$this->current] .= $this->n($r) . ' ' . $this->n($g) . ' ' . $this->n($b) . ' rg ' . $this->n($x) . ' ' . $this->n($this->height - $y - $h) . ' ' . $this->n($w) . ' ' . $this->n($h) . " re f\n";
    }

    public function strokeRect(float $x, float $y, float $w, float $h, float $gray = 0.85): void
    {
        $this->ensurePage();
        $this->pages[$this->current] .= $this->n($gray) . ' G 0.5 w ' . $this->n($x) . ' ' . $this->n($this->height - $y - $h) . ' ' . $this->n($w) . ' ' . $this->n($h) . " re S\n";
    }

    public function table(array $headers, array $rows, array $widths, array $options = []): void
    {
        $x = (float)($options['x'] ?? 28);
        $y = (float)($options['y'] ?? 105);
        $headerHeight = (float)($options['header_height'] ?? 24);
        $rowHeight = (float)($options['row_height'] ?? 22);
        $fontSize = (float)($options['font_size'] ?? 7.5);
        $bottom = (float)($options['bottom_margin'] ?? 42);
        $pageTitle = (string)($options['page_title'] ?? $this->title);
        $columns = count($headers);
        if ($columns !== count($widths)) {
            throw new InvalidArgumentException('Jumlah lebar kolom PDF tidak sesuai header.');
        }

        $drawHeader = function () use (&$x, &$y, $headers, $widths, $headerHeight, $fontSize): void {
            $cx = $x;
            foreach ($headers as $i => $header) {
                $w = (float)$widths[$i];
                $this->fillRect($cx, $y, $w, $headerHeight, 0.72, 0.08, 0.08);
                $this->text($cx + 4, $y + 15.5, $this->fit((string)$header, $w - 8, $fontSize, true), $fontSize, true);
                $cx += $w;
            }
            $y += $headerHeight;
        };

        $drawHeader();
        foreach ($rows as $rIndex => $row) {
            if ($y + $rowHeight > $this->height - $bottom) {
                $this->addPage();
                $this->text(28, 30, $pageTitle . ' (lanjutan)', 13, true);
                $this->text($this->width - 190, 30, 'Dicetak: ' . date('d/m/Y H:i'), 8, false, 'right', 160);
                $y = 50;
                $drawHeader();
            }
            if ($rIndex % 2 === 1) {
                $this->fillRect($x, $y, array_sum($widths), $rowHeight, 1, 0.97, 0.97);
            }
            $cx = $x;
            foreach ($headers as $i => $_) {
                $w = (float)$widths[$i];
                $cell = $row[$i] ?? '';
                $align = 'left';
                $bold = false;
                if (is_array($cell)) {
                    $align = $cell['align'] ?? 'left';
                    $bold = (bool)($cell['bold'] ?? false);
                    $cell = $cell['value'] ?? '';
                }
                $value = $this->fit((string)$cell, $w - 8, $fontSize, $bold);
                $this->text($cx + 4, $y + 14.5, $value, $fontSize, $bold, $align, $w - 8);
                $this->strokeRect($cx, $y, $w, $rowHeight, 0.88);
                $cx += $w;
            }
            $y += $rowHeight;
        }
    }

    public function render(): string
    {
        if (!$this->pages) $this->addPage();
        $total = count($this->pages);
        foreach ($this->pages as $i => &$commands) {
            $pageText = 'Halaman ' . ($i + 1) . ' / ' . $total;
            $encoded = $this->encode($pageText);
            $commands .= 'BT /F1 7 Tf 0.35 0.35 0.35 rg 1 0 0 1 ' . $this->n($this->width - 90) . ' 18 Tm (' . $this->escape($encoded) . ") Tj ET\n";
        }
        unset($commands);

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $kids = [];
        $nextId = 5;
        foreach ($this->pages as $commands) {
            $contentId = $nextId++;
            $pageId = $nextId++;
            $compressed = gzcompress($commands, 9);
            if ($compressed === false) $compressed = $commands;
            $filter = $compressed === $commands ? '' : ' /Filter /FlateDecode';
            $objects[$contentId] = '<< /Length ' . strlen($compressed) . $filter . ">>\nstream\n" . $compressed . "\nendstream";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->n($this->width) . ' ' . $this->n($this->height) . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
            $kids[] = $pageId . ' 0 R';
        }
        $infoId = $nextId++;
        $objects[$infoId] = '<< /Title (' . $this->escape($this->encode($this->title)) . ') /Author (KasirPro UMKM) /Creator (KasirPro UMKM) /CreationDate (D:' . date('YmdHis') . ") >>";
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        foreach ($this->embeddedComments as $key => $value) {
            $encoded = base64_encode(gzdeflate($value, 9));
            $chunks = str_split($encoded, 180);
            $pdf .= '%KASIRPRO-' . $key . '-BEGIN' . "\n";
            foreach ($chunks as $chunk) {
                $pdf .= '%KASIRPRO-' . $key . ':' . $chunk . "\n";
            }
            $pdf .= '%KASIRPRO-' . $key . '-END' . "\n";
        }

        $xref = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id] ?? 0) . "\n";
        }
        $pdf .= 'trailer << /Size ' . ($maxId + 1) . ' /Root 1 0 R /Info ' . $infoId . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        return $pdf;
    }

    public function download(string $filename): void
    {
        $data = $this->render();
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
        $filename = trim((string)$filename, '.-') ?: 'export.pdf';
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') $filename .= '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $data;
        exit;
    }

    public static function readEmbeddedComment(string $path, string $key): ?string
    {
        $data = file_get_contents($path);
        if ($data === false) return null;
        $key = preg_replace('/[^A-Z0-9_-]/i', '', strtoupper($key));
        if (!preg_match_all('/^%KASIRPRO-' . preg_quote($key, '/') . ':([A-Za-z0-9+\/=]+)\s*$/m', $data, $matches)) {
            return null;
        }
        $binary = base64_decode(implode('', $matches[1]), true);
        if ($binary === false) return null;
        $decoded = gzinflate($binary, 8 * 1024 * 1024);
        return $decoded === false ? null : $decoded;
    }

    private function fit(string $text, float $width, float $size, bool $bold = false): string
    {
        $factor = $bold ? 0.53 : 0.49;
        $max = max(1, (int)floor($width / ($size * $factor)));
        $chars = $this->utf8Chars(trim(preg_replace('/\s+/', ' ', $text)));
        if (count($chars) <= $max) return implode('', $chars);
        return implode('', array_slice($chars, 0, max(1, $max - 3))) . '...';
    }

    private function utf8Chars(string $text): array
    {
        if ($text === '') return [];
        preg_match_all('/./us', $text, $m);
        return $m[0] ?? str_split($text);
    }

    private function ensurePage(): void
    {
        if ($this->current < 0) $this->addPage();
    }

    private function encode(string $text): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $converted === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $converted;
    }

    private function escape(string $text): string
    {
        return str_replace(["\\", '(', ')', "\r", "\n"], ["\\\\", '\\(', '\\)', '', ' '], $text);
    }

    private function n(float $number): string
    {
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
