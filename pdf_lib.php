<?php

/**
 * Minimal PDF writer for printable registers: a title, key / value lines and
 * a table that wraps its cells, repeats its header on every page and numbers
 * the pages. Uses the standard Helvetica fonts (no embedding, no dependency);
 * text is converted to WinAnsi (Windows-1252), which covers French.
 */
class SimplePdf
{
    private const HELVETICA = [
        32 => 278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private float $width;
    private float $height;
    private float $margin = 30;
    private float $y = 0;
    private array $pages = [];
    private int $page = -1;
    private string $title;
    private string $footer;

    public function __construct(string $title, string $footer = '', bool $landscape = true)
    {
        [$this->width, $this->height] = $landscape ? [841.89, 595.28] : [595.28, 841.89];
        $this->title = $title;
        $this->footer = $footer;
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->page = count($this->pages) - 1;
        $this->y = $this->margin;
    }

    // Large title followed by smaller grey lines.
    public function heading(string $title, array $lines = []): void
    {
        $this->text($this->margin, $this->y + 14, $title, 14, true);
        $this->y += 22;
        foreach ($lines as $line) {
            $this->text($this->margin, $this->y + 9, $line, 9, false, '0.35');
            $this->y += 12;
        }
        $this->y += 6;
    }

    // "Label : value" pairs laid out on one or more lines.
    public function keyValues(array $pairs): void
    {
        $x = $this->margin;
        foreach ($pairs as [$label, $value]) {
            $chunk = $label . ' : ' . $value;
            $w = $this->textWidth($chunk, 9, true) + 18;
            if ($x + $w > $this->width - $this->margin) {
                $x = $this->margin;
                $this->y += 13;
            }
            $this->text($x, $this->y + 9, $label . ' :', 9, true);
            $this->text($x + $this->textWidth($label . ' : ', 9, true), $this->y + 9, (string) $value, 9);
            $x += $w;
        }
        $this->y += 20;
    }

    /**
     * Table. $columns: list of ['label' => header, 'width' => relative width,
     * 'align' => 'L'|'R'|'C']; $rows: list of lists of strings. A row may
     * carry a 'fill' key (grey level, e.g. '0.95') to shade it.
     */
    public function table(array $columns, array $rows, float $fontSize = 7.5): void
    {
        $usable = $this->width - 2 * $this->margin;
        $total = array_sum(array_column($columns, 'width'));
        $widths = array_map(fn($c) => $usable * $c['width'] / $total, $columns);
        $pad = 2.5;
        $lineHeight = $fontSize * 1.22;
        $bottom = $this->height - $this->margin - 14;

        $drawHeader = function () use ($columns, $widths, $pad, $fontSize, $lineHeight) {
            $cells = [];
            $lines = 1;
            foreach ($columns as $i => $c) {
                $cells[$i] = $this->wrap($c['label'], $widths[$i] - 2 * $pad, $fontSize, true);
                $lines = max($lines, count($cells[$i]));
            }
            $h = $lines * $lineHeight + 2 * $pad;
            $this->rect($this->margin, $this->y, array_sum($widths), $h, '0.88');
            $x = $this->margin;
            foreach ($columns as $i => $c) {
                $this->cell($x, $this->y, $widths[$i], $h, $cells[$i], $fontSize, $lineHeight, $pad, true, $c['align'] ?? 'L');
                $x += $widths[$i];
            }
            $this->y += $h;
        };

        $drawHeader();
        foreach ($rows as $row) {
            $fill = $row['fill'] ?? null;
            unset($row['fill']);
            $row = array_values($row);
            $cells = [];
            $lines = 1;
            foreach ($columns as $i => $c) {
                $cells[$i] = $this->wrap((string) ($row[$i] ?? ''), $widths[$i] - 2 * $pad, $fontSize, false);
                $lines = max($lines, count($cells[$i]));
            }
            $h = $lines * $lineHeight + 2 * $pad;
            if ($this->y + $h > $bottom) {
                $this->addPage();
                $drawHeader();
            }
            if ($fill !== null) {
                $this->rect($this->margin, $this->y, array_sum($widths), $h, $fill);
            }
            $x = $this->margin;
            foreach ($columns as $i => $c) {
                $this->cell($x, $this->y, $widths[$i], $h, $cells[$i], $fontSize, $lineHeight, $pad, false, $c['align'] ?? 'L');
                $x += $widths[$i];
            }
            $this->y += $h;
        }
        if (!$rows) {
            $this->text($this->margin, $this->y + 12, '—', $fontSize);
            $this->y += 16;
        }
        $this->y += 8;
    }

    // The document as a string.
    public function render(): string
    {
        $count = count($this->pages);
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $next = 6;
        $pageObjects = [];
        foreach ($this->pages as $i => $content) {
            $footerOps = '';
            if ($this->footer !== '') {
                $footerOps .= $this->textOps($this->margin, $this->height - $this->margin + 6, $this->footer, 7, false, '0.4');
            }
            $label = 'Page ' . ($i + 1) . ' / ' . $count;
            $footerOps .= $this->textOps($this->width - $this->margin - $this->textWidth($label, 7, false), $this->height - $this->margin + 6, $label, 7, false, '0.4');
            $stream = $content . $footerOps;
            $filter = '';
            if (function_exists('gzcompress')) {
                $stream = gzcompress($stream);
                $filter = ' /Filter /FlateDecode';
            }
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = "$pageId 0 R";
            $pageObjects[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', $this->width, $this->height, $contentId);
            $pageObjects[$contentId] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Title ' . $this->pdfString($this->title) . ' /Producer (MyAnimLab) /CreationDate (D:' . date('YmdHis') . ') >>';
        $objects += $pageObjects;
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        $out .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    // Send the document as a download.
    public function output(string $filename): void
    {
        $pdf = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
    }

    // ---- drawing helpers (y measured from the top of the page) ----

    private function cell(float $x, float $y, float $w, float $h, array $lines, float $size, float $lineHeight, float $pad, bool $bold, string $align): void
    {
        $this->pages[$this->page] .= sprintf("0.6 G 0.4 w %.2F %.2F %.2F %.2F re S 0 G\n", $x, $this->height - $y - $h, $w, $h);
        foreach ($lines as $n => $line) {
            $tw = $this->textWidth($line, $size, $bold);
            $tx = $align === 'R' ? $x + $w - $pad - $tw : ($align === 'C' ? $x + ($w - $tw) / 2 : $x + $pad);
            $this->text($tx, $y + $pad + ($n + 1) * $lineHeight - $lineHeight * 0.22, $line, $size, $bold);
        }
    }

    private function rect(float $x, float $y, float $w, float $h, string $grey): void
    {
        $this->pages[$this->page] .= sprintf("%s g %.2F %.2F %.2F %.2F re f 0 g\n", $grey, $x, $this->height - $y - $h, $w, $h);
    }

    private function text(float $x, float $baselineY, string $text, float $size, bool $bold = false, ?string $grey = null): void
    {
        $this->pages[$this->page] .= $this->textOps($x, $baselineY, $text, $size, $bold, $grey);
    }

    private function textOps(float $x, float $baselineY, string $text, float $size, bool $bold = false, ?string $grey = null): string
    {
        return ($grey !== null ? "$grey g " : '') . sprintf('BT /%s %.2F Tf %.2F %.2F Td %s Tj ET', $bold ? 'F2' : 'F1', $size, $x, $this->height - $baselineY, $this->pdfString($text))
            . ($grey !== null ? ' 0 g' : '') . "\n";
    }

    // Lines of $text fitting $maxWidth (words wrapped, long words cut).
    private function wrap(string $text, float $maxWidth, float $size, bool $bold): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/ +/u', $paragraph) as $word) {
                $candidate = $line === '' ? $word : "$line $word";
                if ($this->textWidth($candidate, $size, $bold) <= $maxWidth) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $out[] = $line;
                }
                $line = '';
                foreach (mb_str_split($word) as $char) {
                    if ($line !== '' && $this->textWidth($line . $char, $size, $bold) > $maxWidth) {
                        $out[] = $line;
                        $line = '';
                    }
                    $line .= $char;
                }
            }
            $out[] = $line;
        }
        return $out;
    }

    private function textWidth(string $text, float $size, bool $bold): float
    {
        $units = 0;
        foreach (str_split($this->encode($text)) as $byte) {
            $units += $this->charWidth(ord($byte));
        }
        return $units * $size / 1000 * ($bold ? 1.07 : 1);
    }

    private function charWidth(int $code): int
    {
        if ($code >= 32 && $code <= 126) {
            return self::HELVETICA[$code];
        }
        return match (true) {
            in_array($code, [0x85, 0x97, 0xC6], true) => 1000,
            in_array($code, [0x91, 0x92], true) => 222,
            in_array($code, [0x93, 0x94], true) => 333,
            in_array($code, [0xA0, 0xB7], true) => 278,
            $code === 0xB0 => 400,
            $code === 0xC7 || $code === 0xD1 || ($code >= 0xD9 && $code <= 0xDC) => 722,
            ($code >= 0xC0 && $code <= 0xC5) || ($code >= 0xC8 && $code <= 0xCB) || $code === 0xDD => 667,
            ($code >= 0xCC && $code <= 0xCF) || ($code >= 0xEC && $code <= 0xEF) => 278,
            $code >= 0xD2 && $code <= 0xD6 => 778,
            $code === 0xE6 => 889,
            in_array($code, [0xE7, 0xFD, 0xFF], true) => 500,
            default => 556,
        };
    }

    // UTF-8 → Windows-1252, with replacements for common signs it lacks.
    private function encode(string $text): string
    {
        $text = strtr($text, ['→' => '->', '←' => '<-', '≥' => '>=', '≤' => '<=', '−' => '-', '♂' => 'M', '♀' => 'F', "\u{202F}" => ' ']);
        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $encoded === false ? mb_convert_encoding($text, 'Windows-1252', 'UTF-8') : $encoded;
    }

    private function pdfString(string $text): string
    {
        $out = '(';
        foreach (str_split($this->encode($text)) as $byte) {
            $code = ord($byte);
            $out .= match (true) {
                $byte === '(' || $byte === ')' || $byte === '\\' => '\\' . $byte,
                $code < 32 || $code > 126 => sprintf('\\%03o', $code),
                default => $byte,
            };
        }
        return $out . ')';
    }
}
