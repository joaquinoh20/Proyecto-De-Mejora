<?php
declare(strict_types=1);

namespace App\Servicios;

use ZipArchive;

/**
 * Genera archivos Excel (.xlsx) y PDF sin librerías externas.
 * - xlsx(): libro con varias hojas (texto y números), encabezado en negrita y columnas ajustadas.
 * - pdf():  documento A4 horizontal con tablas de texto en columnas fijas (fuente Courier), varias páginas.
 */
final class Exportador
{
    // ------------------------------------------------------------------ XLSX
    /**
     * @param array<string, array{cabecera: string[], filas: array<int, array<int, mixed>>}> $hojas nombre de hoja => datos
     */
    public static function xlsx(array $hojas): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $tipos = '';
        $rels = '';
        $libro = '';
        $n = 0;
        foreach ($hojas as $nombre => $h) {
            $n++;
            $tipos .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
            $libro .= '<sheet name="' . self::x(self::nombreHoja((string) $nombre)) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', self::hoja($h['cabecera'], $h['filas']));
        }
        $estilos = $n + 1;
        $rels .= '<Relationship Id="rId' . $estilos . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $tipos . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $libro . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFC8102E"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bytes;
    }

    private static function hoja(array $cab, array $filas): string
    {
        $anchos = array_map(static fn ($c) => mb_strlen((string) $c), $cab);
        foreach (array_slice($filas, 0, 200) as $f) {
            foreach ($f as $i => $v) {
                $anchos[$i] = max($anchos[$i] ?? 0, min(60, mb_strlen((string) $v)));
            }
        }
        $cols = '';
        foreach ($anchos as $i => $a) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(10, $a + 3)) . '" customWidth="1"/>';
        }
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>' . $cols . '</cols><sheetData>';
        $xml .= self::fila(1, $cab, 1);
        foreach ($filas as $k => $f) {
            $xml .= self::fila($k + 2, $f, 0);
        }
        return $xml . '</sheetData></worksheet>';
    }

    private static function fila(int $r, array $celdas, int $estilo): string
    {
        $x = '<row r="' . $r . '">';
        foreach (array_values($celdas) as $i => $v) {
            $ref = self::columna($i) . $r;
            $s = $estilo ? ' s="1"' : '';
            if ($v === null || $v === '') {
                $x .= '<c r="' . $ref . '"' . $s . '/>';
            } elseif ((is_int($v) || is_float($v)) && !$estilo) {
                $x .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
            }
        }
        return $x . '</row>';
    }

    private static function columna(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function nombreHoja(string $n): string
    {
        return mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', $n) ?? 'Hoja', 0, 31);
    }

    /** Escapa XML y quita caracteres de control no permitidos. */
    private static function x(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ------------------------------------------------------------------- PDF
    private const W = 842.0;   // A4 horizontal, en puntos
    private const H = 595.0;
    private const MARGEN = 32.0;
    private const TAM = 7.5;   // cuerpo
    private const INTERLINEA = 10.5;

    /**
     * @param array<int, array{titulo: string, cabecera?: string[], filas?: array<int, array<int, mixed>>, texto?: string[]}> $secciones
     */
    public static function pdf(string $titulo, string $subtitulo, array $secciones, string $pie): string
    {
        $ancho = (self::W - 2 * self::MARGEN);
        $cw = self::TAM * 0.6;                       // Courier: 0,6 em por carácter
        $maxCar = (int) floor($ancho / $cw);

        // 1) Convertir secciones en líneas con estilo: [tipo, texto]
        $lineas = [];
        foreach ($secciones as $s) {
            $lineas[] = ['h', $s['titulo']];
            if (!empty($s['texto'])) {
                foreach ($s['texto'] as $t) {
                    foreach (self::envolver($t, $maxCar) as $l) {
                        $lineas[] = ['t', $l];
                    }
                }
            }
            if (isset($s['cabecera'])) {
                $filas = $s['filas'] ?? [];
                if (!$filas) {
                    $lineas[] = ['t', '(sin registros)'];
                } else {
                    $w = self::anchosColumnas($s['cabecera'], $filas, $maxCar);
                    $lineas[] = ['c', self::filaTexto($s['cabecera'], $w)];
                    foreach ($filas as $f) {
                        $lineas[] = ['t', self::filaTexto($f, $w)];
                    }
                }
            }
            $lineas[] = ['e', ''];
        }

        // 2) Repartir en páginas
        $altoUtil = self::H - 2 * self::MARGEN - 40;   // 40 pt para la banda del título
        $porPagina = (int) floor($altoUtil / self::INTERLINEA);
        $paginas = [];
        $actual = [];
        foreach ($lineas as $l) {
            if (count($actual) >= $porPagina) {
                $paginas[] = $actual;
                $actual = [];
            }
            if ($l[0] === 'e' && !$actual) {
                continue;
            }
            $actual[] = $l;
        }
        if ($actual) {
            $paginas[] = $actual;
        }
        if (!$paginas) {
            $paginas[] = [['t', '(sin datos)']];
        }
        $total = count($paginas);

        // 3) Objetos PDF: 1 catálogo, 2 páginas, 3 fuente, 4 fuente negrita, luego pares (página, contenido)
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $id = 5;
        foreach ($paginas as $n => $pag) {
            $c = '';
            // Banda superior roja con título
            $c .= "0.78 0.06 0.18 rg\n0 " . (self::H - 34) . ' ' . self::W . " 34 re f\n";
            $c .= "BT /F2 13 Tf 1 1 1 rg " . self::MARGEN . ' ' . (self::H - 22) . ' Td (' . self::t($titulo) . ") Tj ET\n";
            $c .= "BT /F1 8 Tf 1 1 1 rg " . (self::W - self::MARGEN - 8 * 0.6 * mb_strlen($subtitulo)) . ' ' . (self::H - 21) . ' Td (' . self::t($subtitulo) . ") Tj ET\n";
            $y = self::H - self::MARGEN - 22;
            foreach ($pag as [$tipo, $txt]) {
                if ($tipo === 'h') {
                    $c .= "0.78 0.06 0.18 rg\n" . self::MARGEN . ' ' . ($y - 3) . ' ' . $ancho . " 14 re f\n";
                    $c .= 'BT /F2 9 Tf 1 1 1 rg ' . (self::MARGEN + 4) . ' ' . $y . ' Td (' . self::t($txt) . ") Tj ET\n";
                } elseif ($tipo === 'c') {
                    $c .= "0.93 0.93 0.93 rg\n" . self::MARGEN . ' ' . ($y - 2.5) . ' ' . $ancho . ' ' . (self::INTERLINEA) . " re f\n";
                    $c .= 'BT /F2 ' . self::TAM . ' Tf 0 0 0 rg ' . self::MARGEN . ' ' . $y . ' Td (' . self::t($txt) . ") Tj ET\n";
                } elseif ($tipo === 't') {
                    $c .= 'BT /F1 ' . self::TAM . ' Tf 0.1 0.1 0.1 rg ' . self::MARGEN . ' ' . $y . ' Td (' . self::t($txt) . ") Tj ET\n";
                }
                $y -= self::INTERLINEA;
            }
            $c .= 'BT /F1 7 Tf 0.4 0.4 0.4 rg ' . self::MARGEN . ' 16 Td (' . self::t($pie) . ") Tj ET\n";
            $num = 'Página ' . ($n + 1) . ' de ' . $total;
            $c .= 'BT /F1 7 Tf 0.4 0.4 0.4 rg ' . (self::W - self::MARGEN - 7 * 0.6 * mb_strlen($num)) . ' 16 Td (' . self::t($num) . ") Tj ET\n";
            $objs[$id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($id + 1) . ' 0 R >>';
            $objs[$id + 1] = "<< /Length " . strlen($c) . " >>\nstream\n" . $c . "endstream";
            $kids[] = $id . ' 0 R';
            $id += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';

        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $off = [];
        foreach ($objs as $k => $o) {
            $off[$k] = strlen($out);
            $out .= $k . " 0 obj\n" . $o . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $off[$i]);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Texto UTF-8 → cadena PDF (Windows-1252) con escapes. */
    private static function t(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $s = str_replace(['→', '—', '–', '“', '”', '’', '‘', '·'], ['->', '-', '-', '"', '"', "'", "'", '-'], $s);
        $c = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        $c = is_string($c) ? $c : $s;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $c);
    }

    /** @return string[] */
    private static function envolver(string $t, int $max): array
    {
        $l = [];
        foreach (preg_split('/\R/u', $t) ?: [''] as $parr) {
            $parr = trim($parr);
            if ($parr === '') {
                $l[] = '';
                continue;
            }
            while (mb_strlen($parr) > $max) {
                $corte = mb_strrpos(mb_substr($parr, 0, $max), ' ');
                $corte = ($corte === false || $corte < $max / 2) ? $max : $corte;
                $l[] = rtrim(mb_substr($parr, 0, $corte));
                $parr = ltrim(mb_substr($parr, $corte));
            }
            $l[] = $parr;
        }
        return $l;
    }

    /** Reparte los caracteres disponibles entre las columnas según su contenido. @return int[] */
    private static function anchosColumnas(array $cab, array $filas, int $max): array
    {
        $n = count($cab);
        $ideal = [];
        foreach ($cab as $i => $c) {
            $m = mb_strlen((string) $c);
            foreach (array_slice($filas, 0, 300) as $f) {
                $m = max($m, mb_strlen((string) ($f[$i] ?? '')));
            }
            $ideal[$i] = min($m, 70);
        }
        $disp = $max - 2 * ($n - 1);
        $suma = array_sum($ideal);
        if ($suma <= $disp) {
            return $ideal;
        }
        // Reduce las columnas más anchas hasta que quepan
        while (array_sum($ideal) > $disp) {
            $i = array_search(max($ideal), $ideal, true);
            $ideal[$i]--;
        }
        return $ideal;
    }

    private static function filaTexto(array $celdas, array $anchos): string
    {
        $p = [];
        foreach ($anchos as $i => $w) {
            $v = str_replace(["\r", "\n"], ' ', (string) ($celdas[$i] ?? ''));
            $v = mb_strlen($v) > $w ? mb_substr($v, 0, max(1, $w - 1)) . '.' : $v;
            $p[] = $v . str_repeat(' ', max(0, $w - mb_strlen($v)));
        }
        return rtrim(implode('  ', $p));
    }
}
