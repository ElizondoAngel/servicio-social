<?php
declare(strict_types=1);

namespace ServicioSocial\Nucleo;

/**
 * Escribe un libro de Excel (.xlsx) sin dependencias: solo zlib, que ya
 * es requisito. Cada hoja tiene una fila de encabezados en negritas,
 * fija y con filtro. Los textos van como cadenas en línea, así que nada
 * se interpreta como fórmula.
 */
final class LibroExcel
{
    private const MAX_CELDA = 32767;

    /** @var list<array{nombre: string, encabezados: list<string>, filas: list<list<mixed>>, numericas: list<int>}> */
    private array $hojas = [];

    /**
     * @param list<string> $encabezados
     * @param list<list<mixed>> $filas
     * @param list<int> $numericas índices de columna que se guardan como número
     */
    public function agregarHoja(string $nombre, array $encabezados, array $filas, array $numericas = []): void
    {
        $nombre = mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', $nombre), 0, 31);
        $this->hojas[] = ['nombre' => $nombre, 'encabezados' => $encabezados, 'filas' => $filas, 'numericas' => $numericas];
    }

    /** @return string bytes del archivo .xlsx */
    public function generar(): string
    {
        if ($this->hojas === []) {
            $this->agregarHoja('Hoja1', [], []);
        }
        $n = count($this->hojas);
        $archivos = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . implode('', array_map(fn($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, $n)))
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
                . implode('', array_map(fn($i) => '<sheet name="' . self::x($this->hojas[$i - 1]['nombre']) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>', range(1, $n)))
                . '</sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . implode('', array_map(fn($i) => '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>', range(1, $n)))
                . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            // Estilo 0: normal. Estilo 1: encabezado en negritas con fondo y texto blanco.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                . '<fill><patternFill patternType="solid"><fgColor rgb="FF1B4F72"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                . '</styleSheet>',
        ];
        foreach ($this->hojas as $i => $hoja) {
            $archivos['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->hojaXml($hoja);
        }
        return self::zip($archivos);
    }

    private function hojaXml(array $hoja): string
    {
        $cols = count($hoja['encabezados']);
        $anchos = array_map(fn($e) => mb_strlen((string)$e), $hoja['encabezados']);
        $filasXml = '';
        $r = 1;
        if ($cols > 0) {
            $filasXml .= '<row r="1">';
            foreach ($hoja['encabezados'] as $c => $e) {
                $filasXml .= '<c r="' . self::col($c) . '1" t="inlineStr" s="1"><is><t>' . self::x((string)$e) . '</t></is></c>';
            }
            $filasXml .= '</row>';
        }
        foreach ($hoja['filas'] as $fila) {
            $r++;
            $filasXml .= '<row r="' . $r . '">';
            foreach (array_values($fila) as $c => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = self::col($c) . $r;
                if (in_array($c, $hoja['numericas'], true) && is_numeric($v)) {
                    $filasXml .= '<c r="' . $ref . '"><v>' . (0 + $v) . '</v></c>';
                    $largo = strlen((string)$v);
                } else {
                    $texto = mb_substr((string)$v, 0, self::MAX_CELDA);
                    $filasXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . self::x($texto) . '</t></is></c>';
                    $largo = mb_strlen($texto);
                }
                $anchos[$c] = max($anchos[$c] ?? 0, $largo);
            }
            $filasXml .= '</row>';
        }
        $colsXml = '';
        foreach ($anchos as $c => $a) {
            $colsXml .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min(60, max(8, $a + 2)) . '" customWidth="1"/>';
        }
        $ultima = $cols > 0 ? self::col($cols - 1) . max(1, $r) : 'A1';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . ($cols > 0 ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '')
            . '</sheetView></sheetViews>'
            . ($colsXml !== '' ? '<cols>' . $colsXml . '</cols>' : '')
            . '<sheetData>' . $filasXml . '</sheetData>'
            . ($cols > 0 && $r > 1 ? '<autoFilter ref="A1:' . $ultima . '"/>' : '')
            . '</worksheet>';
    }

    /** Columna 0 → A, 25 → Z, 26 → AA. */
    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    /** Escapa para XML y quita los caracteres de control que XML no permite. */
    private static function x(string $s): string
    {
        $s = (string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', mb_scrub($s, 'UTF-8'));
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** ZIP mínimo con compresión deflate. @param array<string,string> $archivos */
    private static function zip(array $archivos): string
    {
        $t = getdate();
        $hora = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $fecha = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $datos = '';
        $central = '';
        foreach ($archivos as $nombre => $contenido) {
            $comprimido = (string)gzdeflate($contenido, 6);
            $crc = crc32($contenido);
            $campos = pack('vvvvvVVVv', 20, 0, 8, $hora, $fecha, $crc, strlen($comprimido), strlen($contenido), strlen($nombre));
            $central .= pack('V', 0x02014b50) . pack('v', 20) . $campos . pack('vvvvVV', 0, 0, 0, 0, 0, strlen($datos)) . $nombre;
            $datos .= pack('V', 0x04034b50) . $campos . pack('v', 0) . $nombre . $comprimido;
        }
        return $datos . $central
            . pack('VvvvvVVv', 0x06054b50, 0, 0, count($archivos), count($archivos), strlen($central), strlen($datos), 0);
    }
}
