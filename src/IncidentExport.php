<?php

declare(strict_types=1);

final class IncidentExport
{
    public static function write(string $path, array $job, iterable $incidents): void
    {
        $sheetPath = $path . '.xml';
        $sheet = fopen($sheetPath, 'xb');
        if ($sheet === false) {
            throw new RuntimeException('No se pudo preparar el Excel de incidencias.');
        }
        try {
            self::put($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="6" topLeftCell="A7" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="26" customWidth="1"/><col min="2" max="2" width="12" customWidth="1"/><col min="3" max="3" width="30" customWidth="1"/><col min="4" max="4" width="85" customWidth="1"/><col min="5" max="5" width="16" customWidth="1"/></cols><sheetData>');
            self::row($sheet, 1, ['Incidencias de la acción #' . $job['id']], true);
            self::row($sheet, 2, ['Acción', $job['action'], 'Estado', $job['status']]);
            self::row($sheet, 3, ['Creada', $job['created_at']]);
            self::row($sheet, 4, ['Todas las incidencias guardadas, agrupadas por referencia, fila, código y mensaje.']);
            self::row($sheet, 6, ['Referencia', 'Fila SQL', 'Código', 'Mensaje', 'Repeticiones'], true);
            $number = 6;
            foreach ($incidents as $incident) {
                self::row($sheet, ++$number, [
                    $incident['article_id'] ?? 'Operación general',
                    $incident['row_number'] === null ? '' : (int) $incident['row_number'],
                    $incident['code'], $incident['message'], (int) $incident['occurrences'],
                ]);
            }
            self::put($sheet, '</sheetData><autoFilter ref="A6:E' . $number . '"/></worksheet>');
            fclose($sheet);
            $sheet = null;

            // Build an ordinary ZIP package. PharData writes version 0 headers,
            // which permissive readers accept but Excel can reject.
            $zip = [];
            $zip['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
            $zip['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
            $zip['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Incidencias" sheetId="1" r:id="rId1"/></sheets></workbook>';
            $zip['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $zip['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF24476B"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
            $contents = file_get_contents($sheetPath);
            if ($contents === false) {
                throw new RuntimeException('No se pudo leer la hoja de incidencias.');
            }
            $zip['xl/worksheets/sheet1.xml'] = $contents;
            self::archive($path, $zip);
        } catch (Throwable $error) {
            if (is_file($path)) {
                unlink($path);
            }
            throw $error;
        } finally {
            if (is_resource($sheet)) {
                fclose($sheet);
            }
            unlink($sheetPath);
        }
    }

    private static function archive(string $path, array $parts): void
    {
        $file = fopen($path, 'xb');
        if ($file === false) {
            throw new RuntimeException('No se pudo crear el archivo Excel.');
        }
        try {
            $directory = '';
            foreach ($parts as $name => $contents) {
                $offset = ftell($file);
                $size = strlen($contents);
                $crc = crc32($contents);
                $compressed = function_exists('gzdeflate') ? gzdeflate($contents) : false;
                $method = $compressed === false ? 0 : 8;
                $body = $compressed === false ? $contents : $compressed;
                $length = strlen($body);
                // All package filenames are ASCII. ZIP 2.0, no extra fields,
                // no data descriptors; CRC and sizes are known in advance.
                $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 33, $crc, $length, $size, strlen($name), 0);
                self::put($file, $header . $name . $body);
                $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, $method, 0, 33, $crc, $length, $size, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
            }
            $offset = ftell($file);
            self::put($file, $directory);
            self::put($file, pack('VvvvvVVv', 0x06054b50, 0, 0, count($parts), count($parts), strlen($directory), $offset, 0));
        } finally {
            fclose($file);
        }
    }

    private static function row($stream, int $number, array $values, bool $heading = false): void
    {
        $xml = '<row r="' . $number . '">';
        foreach ($values as $index => $value) {
            $cell = chr(65 + $index) . $number;
            if (is_int($value)) {
                $xml .= '<c r="' . $cell . '"><v>' . $value . '</v></c>';
            } else {
                // Explicit strings retain leading zeros and never execute SKU
                // or message values beginning with =, +, - or @ as formulas.
                $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
                $xml .= '<c r="' . $cell . '" s="' . ($heading ? 1 : 0) . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        self::put($stream, $xml . '</row>');
    }

    private static function put($stream, string $value): void
    {
        if (fwrite($stream, $value) !== strlen($value)) {
            throw new RuntimeException('No se pudo escribir el Excel de incidencias.');
        }
    }
}
