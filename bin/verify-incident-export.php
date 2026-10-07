<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/IncidentExport.php';

function verifyIncidentExport(): void
{
    $directory = sys_get_temp_dir() . '/feedTarifas-export-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $store = null;
    $zip = null;
    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };
    try {
        $store = new StateStore($directory . '/copy.sqlite');
        $id = $store->queueInitialLoad();
        $store->addError($id, 7, '001234', 'missing_sku', 'No existe: á & < > "');
        $store->addError($id, 7, '001234', 'missing_sku', 'No existe: á & < > "');
        $store->addError($id, null, '=1+1', 'variant_lookup_inconclusive', '=HYPERLINK("https://example.com")');
        $store->addError($id, null, null, 'variant_lookup_failed', 'Incidencia global');
        for ($row = 1; $row <= 60; $row++) {
            $store->addError($id, $row, 'SKU-' . $row, 'price_precision', 'Demasiados decimales');
        }
        $otherId = $store->queueSync();
        $store->addError($otherId, 1, 'OTHER-JOB', 'missing_sku', 'Otra acción');
        $job = $store->jobStatus($id);
        $before = $job;
        $check(count($job['errors']) === 50, 'Export fixture did not exceed the panel limit');
        IncidentExport::write($directory . '/incidents.zip', $job, $store->incidentsForExport($id));
        $archive = file_get_contents($directory . '/incidents.zip');
        $header = unpack('Vsignature/vversion/vflags/vmethod', substr($archive, 0, 10));
        $check($header['signature'] === 0x04034b50 && $header['version'] === 20 && $header['flags'] === 0, 'XLSX has incompatible ZIP version or flags');
        $end = unpack('Vsignature/vdisk/vdirectory_disk/vdisk_entries/ventries/Vsize/Voffset/vcomment', substr($archive, -22));
        $check($end['signature'] === 0x06054b50 && $end['entries'] === 6 && $end['offset'] + $end['size'] + 22 === strlen($archive), 'XLSX ZIP directory is incomplete');
        $central = unpack('Vsignature/vcreator/vversion', substr($archive, $end['offset'], 8));
        $check($central['signature'] === 0x02014b50 && $central['creator'] === 20 && $central['version'] === 20, 'XLSX ZIP directory has invalid version fields');
        $zip = new PharData($directory . '/incidents.zip');
        $sheet = simplexml_load_string($zip['xl/worksheets/sheet1.xml']->getContent());
        $sheet->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = $sheet->xpath('//s:sheetData/s:row[@r > 6]');
        $check(count($rows) === 63, 'Excel omitted incidents beyond the panel limit or included another job');
        $references = [];
        foreach ($rows as $row) {
            $cells = $row->c;
            $reference = (string) $cells[0]->is->t;
            $references[$reference] = $cells;
        }
        $check(isset($references['001234']) && (string) $references['001234'][0]['t'] === 'inlineStr' && (string) $references['001234'][4]->v === '2', 'Excel lost leading zeros or repeated-incident count');
        $check((string) $references['001234'][3]->is->t === 'No existe: á & < > "', 'Excel damaged accents or XML characters');
        $check((string) $references['=1+1'][0]['t'] === 'inlineStr' && $sheet->xpath('//s:f') === [], 'Reference or message was exported as a formula');
        $check(isset($references['Operación general']) && (string) $references['Operación general'][1]->is->t === '', 'Global incident or null SQL row was lost');
        $check((string) $sheet->autoFilter['ref'] === 'A6:E69' && (string) $sheet->sheetViews->sheetView->pane['state'] === 'frozen', 'Excel filters or frozen header missing');
        $check($store->jobStatus($id) === $before && $store->errorCount($id) === 64, 'Export changed stored incidents or counters');
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml'] as $part) {
            $check(isset($zip[$part]) && simplexml_load_string($zip[$part]->getContent()) !== false, 'Invalid XLSX package part: ' . $part);
        }
        unset($zip);
        $zip = null;
        IncidentExport::write($directory . '/empty.zip', $store->jobStatus($otherId), []);
        $zip = new PharData($directory . '/empty.zip');
        $check(str_contains($zip['xl/worksheets/sheet1.xml']->getContent(), 'autoFilter ref="A6:E6"'), 'Empty export lost headers');
    } finally {
        unset($zip, $store);
        foreach (glob($directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}
