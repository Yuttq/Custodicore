<?php

namespace App\Support;

/**
 * A tiny, dependency-free PDF writer for simple report tables (title band,
 * a few summary lines, a table that repeats its header on every page, page
 * numbers). It exists so the admin's "Export PDF" works with nothing extra
 * to install; it writes standard PDF 1.4 with the built-in Helvetica fonts,
 * so there is nothing to embed and any PDF reader opens it.
 *
 * Text is Latin-1 (Windows-1252) — names with other scripts show as "?".
 * Usage:
 *   $pdf = SimplePdf::table('Check-In / Check-Out Log', ['Date range: ...'],
 *       [['label' => 'Date', 'width' => 90], ...], $rows, 'CustodiCore');
 *   return response($pdf, 200, ['Content-Type' => 'application/pdf', ...]);
 */
class SimplePdf
{
    private const PAGE_W = 841.89; // A4 landscape, in points
    private const PAGE_H = 595.28;
    private const MARGIN = 36;
    private const ROW_H = 20;

    /** Helvetica glyph widths (1/1000 em) for ASCII 32..126. */
    private const WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    /**
     * @param  list<string>  $meta     lines under the title (filters, totals)
     * @param  list<array{label:string,width:int|float,align?:string}>  $columns
     * @param  list<list<string>>  $rows
     */
    public static function table(string $title, array $meta, array $columns, array $rows, string $footer = 'CustodiCore'): string
    {
        $pages = [];
        $ops = '';
        $y = 0.0;

        $newPage = function (bool $first) use (&$pages, &$ops, &$y, $title, $meta, $columns) {
            if ($ops !== '') {
                $pages[] = $ops;
            }
            $ops = '';
            $top = self::PAGE_H - self::MARGIN;

            if ($first) {
                // Title band.
                $ops .= self::rect(self::MARGIN, $top - 46, self::PAGE_W - 2 * self::MARGIN, 46, [0.059, 0.239, 0.478]);
                $ops .= self::text(self::MARGIN + 14, $top - 22, $title, 16, true, [1, 1, 1]);
                $ops .= self::text(self::MARGIN + 14, $top - 38, 'CustodiCore · Generated ' . date('Y-m-d H:i'), 9, false, [0.85, 0.9, 1]);
                $y = $top - 46 - 18;
                foreach ($meta as $line) {
                    $ops .= self::text(self::MARGIN, $y, $line, 9.5, false, [0.25, 0.28, 0.34]);
                    $y -= 14;
                }
                $y -= 6;
            } else {
                $y = $top - 4;
            }

            // Table header.
            $ops .= self::rect(self::MARGIN, $y - self::ROW_H + 5, self::PAGE_W - 2 * self::MARGIN, self::ROW_H, [0.92, 0.94, 0.97]);
            $x = self::MARGIN + 6;
            foreach ($columns as $col) {
                $ops .= self::cell($x, $y - 9, $col, $col['label'], true);
                $x += $col['width'];
            }
            $y -= self::ROW_H;
        };

        $newPage(true);

        $zebra = false;
        foreach ($rows as $row) {
            if ($y - self::ROW_H < self::MARGIN + 28) {
                $newPage(false);
                $zebra = false;
            }
            if ($zebra) {
                $ops .= self::rect(self::MARGIN, $y - self::ROW_H + 5, self::PAGE_W - 2 * self::MARGIN, self::ROW_H, [0.965, 0.975, 0.99]);
            }
            $zebra = ! $zebra;
            $x = self::MARGIN + 6;
            foreach ($columns as $i => $col) {
                $ops .= self::cell($x, $y - 9, $col, (string) ($row[$i] ?? ''), false);
                $x += $col['width'];
            }
            $ops .= self::line(self::MARGIN, $y - self::ROW_H + 5, self::PAGE_W - self::MARGIN, $y - self::ROW_H + 5);
            $y -= self::ROW_H;
        }

        if ($rows === []) {
            $ops .= self::text(self::MARGIN + 6, $y - 9, 'No records for this selection.', 10, false, [0.4, 0.4, 0.45]);
        }
        $pages[] = $ops;

        // Footer + page numbers need the final page count.
        $total = count($pages);
        foreach ($pages as $i => &$content) {
            $content .= self::text(self::MARGIN, self::MARGIN - 6, $footer, 8, false, [0.45, 0.47, 0.52]);
            $label = 'Page ' . ($i + 1) . ' of ' . $total;
            $content .= self::text(self::PAGE_W - self::MARGIN - self::width($label, 8), self::MARGIN - 6, $label, 8, false, [0.45, 0.47, 0.52]);
        }
        unset($content);

        return self::assemble($pages);
    }

    // -- drawing helpers ----------------------------------------------------

    private static function cell(float $x, float $y, array $col, string $text, bool $bold): string
    {
        $size = $bold ? 8.5 : 9;
        $max = $col['width'] - 12;
        $text = self::fit($text, $max, $size);
        if (($col['align'] ?? 'left') === 'right') {
            $x += $max - self::width($text, $size);
        }

        return self::text($x, $y, $text, $size, $bold, $bold ? [0.059, 0.239, 0.478] : [0.1, 0.12, 0.16]);
    }

    private static function text(float $x, float $y, string $text, float $size, bool $bold, array $rgb): string
    {
        return sprintf(
            "BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $y, self::escape($text)
        );
    }

    private static function rect(float $x, float $y, float $w, float $h, array $rgb): string
    {
        return sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $y, $w, $h);
    }

    private static function line(float $x1, float $y1, float $x2, float $y2): string
    {
        return sprintf("0.88 0.9 0.93 RG 0.5 w %.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    // -- text helpers -------------------------------------------------------

    /** UTF-8 → Windows-1252 bytes ("?" for anything it can't show). */
    private static function latin(string $text): string
    {
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $text) : $out;
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::latin($text));
    }

    private static function width(string $text, float $size): float
    {
        $w = 0;
        foreach (str_split(self::latin($text)) as $ch) {
            $o = ord($ch);
            $w += ($o >= 32 && $o <= 126) ? self::WIDTHS[$o - 32] : 556;
        }

        return $w * $size / 1000;
    }

    /** Shortens (UTF-8) with "..." so the text fits the column. */
    private static function fit(string $text, float $max, float $size): string
    {
        $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
        if (self::width($text, $size) <= $max) {
            return $text;
        }
        while ($text !== '' && self::width($text . '...', $size) > $max) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text) . '...';
    }

    // -- file assembly ------------------------------------------------------

    /** @param list<string> $pages content stream of each page */
    private static function assemble(array $pages): string
    {
        $objects = [];
        $kids = [];
        // 1 catalog, 2 pages, 3 Helvetica, 4 Helvetica-Bold, then page/content pairs.
        foreach ($pages as $i => $content) {
            $pageNo = 5 + $i * 2;
            $kids[] = $pageNo . ' 0 R';
            $objects[$pageNo] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_W, self::PAGE_H, $pageNo + 1
            );
            $objects[$pageNo + 1] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($pages) . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= $n . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }
}
