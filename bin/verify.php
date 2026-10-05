#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failures = 0;
foreach ($phpFiles as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    $process = proc_open([PHP_BINARY, '-l', $file->getPathname()], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "No se pudo iniciar php -l para {$file->getPathname()}\n");
        exit(1);
    }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $result = proc_close($process);
    echo $output;
    if ($result !== 0) {
        $failures++;
    }
}

require_once $root . '/src/PreviewValidator.php';
require_once $root . '/src/Config.php';
require_once $root . '/src/StateStore.php';
require_once $root . '/src/SourceReader.php';
require_once $root . '/src/ShopifyClient.php';
require_once $root . '/src/VariantResolver.php';
require_once $root . '/src/InitialLoad.php';
require_once $root . '/src/SyncPrices.php';
require_once $root . '/src/ProgressPresenter.php';
$tariffs = ['12060', '11961', '112463', '161', '187', '188', '192', '193', '194', '195'];
$validator = new PreviewValidator($tariffs);
$errors = [];
$recordError = static function (int $row, ?string $article, string $code, string $message) use (&$errors): void {
    $errors[] = compact('row', 'article', 'code', 'message');
};
$validRow = ['IdArtículo' => 'SKU-1'];
foreach ($tariffs as $tariff) {
    $validRow[$tariff] = '12.345';
}
if (!$validator->validate($validRow, 1, $recordError)) {
    fwrite(STDERR, "La fila con artículo único y diez precios válidos se rechazó.\n");
    $failures++;
}
if ($validator->validate($validRow, 2, $recordError) || !in_array('duplicate_article_id', array_column($errors, 'code'), true)) {
    fwrite(STDERR, "No se detectó un IdArtículo duplicado.\n");
    $failures++;
}

$missingPriceRow = $validRow;
$missingPriceRow['IdArtículo'] = 'SKU-2';
$missingPriceRow['195'] = '';
if ($validator->validate($missingPriceRow, 3, $recordError) || !in_array('missing_price', array_column($errors, 'code'), true)) {
    fwrite(STDERR, "No se detectó el precio requerido vacío.\n");
    $failures++;
}

if (InitialLoad::normalizeAmount('12.300', 2) !== '12.30' ||
    InitialLoad::normalizeAmount('12.345', 2) !== null ||
    InitialLoad::normalizeAmount('.890', 3) !== '0.890' ||
    InitialLoad::normalizeAmount('.8901', 3) !== null ||
    InitialLoad::normalizeAmount('12.000', 0) !== '12') {
    fwrite(STDERR, "La precisión configurada no bloqueó o normalizó correctamente los importes.\n");
    $failures++;
}

$progressJob = [
    'action' => 'initial_load', 'status' => 'waiting_shopify', 'phase' => 'prices',
    'object_count' => 0, 'expected_count' => 160, 'article_count' => 6316,
    'created_at' => gmdate(DATE_ATOM), 'started_at' => gmdate(DATE_ATOM),
    'completed_at' => null, 'progress_at' => gmdate(DATE_ATOM),
    'summary' => ['phase_label' => 'Aplicando precios en Shopify'],
];
$waitingDisplay = ProgressPresenter::describe($progressJob);
$progressJob['status'] = 'partial';
$progressJob['summary']['per_tariff'] = ['12060' => ['prepared' => 10, 'confirmed' => 8]];
$finishedDisplay = ProgressPresenter::describe($progressJob);
if ($waitingDisplay['progress'] !== 'Shopify está aplicando los precios' ||
    $finishedDisplay['progress'] !== '8 / 10 precios confirmados por Shopify' ||
    $finishedDisplay['stage'] !== 'Finalizada con incidencias') {
    fwrite(STDERR, "El panel mostró un avance por bloques incorrecto durante o después de la carga.\n");
    $failures++;
}
$syncDisplay = ProgressPresenter::describe([
    'action' => 'sync', 'status' => 'completed', 'phase' => 'sync', 'object_count' => 1,
    'expected_count' => 1, 'article_count' => 2, 'created_at' => gmdate(DATE_ATOM),
    'started_at' => gmdate(DATE_ATOM), 'completed_at' => gmdate(DATE_ATOM), 'progress_at' => gmdate(DATE_ATOM),
    'summary' => ['changed_count' => 2, 'sent_count' => 2, 'confirmed_count' => 1, 'failed_count' => 1],
]);
if ($syncDisplay['progress'] !== '1 de 2 precios a actualizar confirmados' || $syncDisplay['detail'] !== '2 precios enviados · 1 precios con error de envío o confirmación') {
    fwrite(STDERR, "El panel no presentó los conteos reales del sync.\n");
    $failures++;
}

$envRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'feedTarifas-verify-' . bin2hex(random_bytes(5));
if (!mkdir($envRoot, 0700)) {
    fwrite(STDERR, "No se pudo crear el directorio temporal para verificar .env.\n");
    exit(1);
}
try {
    $envPath = $envRoot . DIRECTORY_SEPARATOR . '.env';
    file_put_contents($envPath, implode("\n", [
        'SQLSRV_DSN="sqlsrv:Server=localhost;Database=prices"',
        'SQLSRV_USERNAME=reader',
        'SQLSRV_PASSWORD="test=value"',
        'PRICE_TARIFF_COLUMNS=' . implode(',', $tariffs),
    ]));
    $config = Config::load($envRoot);
    if ($config->sqlsrvPassword !== 'test=value' || count($config->tariffColumns) !== 10 || $config->sqlitePath !== $envRoot . '/var/jobs.sqlite' || $config->nightlySyncTime !== '02:00') {
        fwrite(STDERR, "El .env de la raíz no se leyó correctamente.\n");
        $failures++;
    }
    $store = new StateStore($envRoot . '/var/test.sqlite');
    $first = $store->queueInitialLoad();
    try {
        $store->queueInitialLoad();
        fwrite(STDERR, "Se permitió una segunda carga inicial activa.\n");
        $failures++;
    } catch (RuntimeException $expected) {
    }
    $claimed = $store->claimNext();
    if ($claimed === null || (int) $claimed['id'] !== $first) {
        fwrite(STDERR, "No se reclamó la carga inicial pendiente.\n");
        $failures++;
    }
    $store->setProgress($first, 'running', 'source', null, 100, 0, ['article_count' => 100, 'tariff_count' => 10]);
    $savedProgress = $store->jobStatus($first);
    if ($savedProgress === null || (int) $savedProgress['object_count'] !== 100 || $savedProgress['progress_at'] === null) {
        fwrite(STDERR, "El avance del worker no quedó disponible para el panel.\n");
        $failures++;
    }
    $store->addJobPrice($first, 1, 'SKU-1', '12060', '12.30', 'EUR');
    $store->saveVariant('SKU-1', 'gid://shopify/ProductVariant/1');
    $store->attachVariants($first);
    if ((int) $store->confirmedCounts($first)[0]['confirmed'] !== 0) {
        fwrite(STDERR, "Se marcó un precio sin confirmación Shopify.\n");
        $failures++;
    }
    $store->confirmPrice($first, 'SKU-1', '12060');
    if ((int) $store->confirmedCounts($first)[0]['confirmed'] !== 1) {
        fwrite(STDERR, "No se guardó un precio confirmado.\n");
        $failures++;
    }
    $store->finish($first, 'completed', 1, 1, []);
    $syncStore = new StateStore($envRoot . '/var/sync.sqlite');
    $nightlyId = $syncStore->queueNightlySync('2026-10-01', '02:00');
    if ($nightlyId === null || $syncStore->queueNightlySync('2026-10-01', '02:00') !== null || $syncStore->lastNightlyRun() !== '2026-10-01') {
        fwrite(STDERR, "La sincronización nocturna no fue idempotente por fecha.\n");
        $failures++;
    }
    $manualId = $syncStore->queueSync();
    $manualJob = $syncStore->claimNext();
    if ($manualJob === null || $manualJob['action'] !== 'sync' || (int) $manualJob['id'] !== $nightlyId) {
        fwrite(STDERR, "La cola no conservó la acción de sincronización nocturna.\n");
        $failures++;
    }
    $syncStore->finish($nightlyId, 'completed', 0, 0, []);
    $syncStore->addJobPrice($manualId, 1, 'SKU-SAME', '12060', '12.30', 'EUR');
    $syncStore->addJobPrice($manualId, 2, 'SKU-CHANGE', '12060', '13.30', 'EUR');
    $syncStore->saveVariant('SKU-SAME', 'gid://shopify/ProductVariant/2');
    $syncStore->saveVariant('SKU-CHANGE', 'gid://shopify/ProductVariant/3');
    $syncStore->attachVariants($manualId);
    $seedJob = $syncStore->queuePreview();
    $syncStore->addJobPrice($seedJob, 1, 'SKU-SAME', '12060', '12.30', 'EUR');
    $syncStore->saveVariant('SKU-SAME', 'gid://shopify/ProductVariant/2');
    $syncStore->attachVariants($seedJob);
    $syncStore->confirmPrice($seedJob, 'SKU-SAME', '12060');
    $candidates = $syncStore->syncPrices($manualId);
    $candidateByArticle = array_column($candidates, null, 'article_id');
    if (count($candidates) !== 2 || $candidateByArticle['SKU-SAME']['synced_amount'] !== '12.30' || $candidateByArticle['SKU-CHANGE']['synced_amount'] !== null || (int) $manualJob['id'] === $manualId) {
        fwrite(STDERR, "La comparación de precios no distinguió el valor confirmado del nuevo cambio.\n");
        $failures++;
    }
    // Legacy sync: two unmapped references repeated for ten tariffs, one real
    // Shopify rejection and one unchanged reference. Display must separate them.
    $legacyId = $syncStore->queueSync();
    $legacyCounts = [];
    foreach ($tariffs as $tariff) {
        foreach (['UNMAPPED-A', 'UNMAPPED-B', 'REJECTED', 'UNCHANGED'] as $index => $article) {
            $syncStore->addJobPrice($legacyId, $index + 1, $article, $tariff, '1.000', 'EUR');
        }
        foreach (['UNMAPPED-A', 'UNMAPPED-B'] as $index => $article) {
            $syncStore->addError($legacyId, $index + 1, $article, 'variant_unmapped', 'Sin asociación local.');
        }
        $syncStore->addError($legacyId, 3, 'REJECTED', 'price_sync_failed', 'Shopify no confirmó este precio.');
        $legacyCounts[$tariff] = ['changed' => 3, 'sent' => 1, 'confirmed' => 0, 'failed' => 3];
    }
    $syncStore->saveVariant('REJECTED', 'gid://shopify/ProductVariant/10');
    $syncStore->saveVariant('UNCHANGED', 'gid://shopify/ProductVariant/11');
    $syncStore->attachVariants($legacyId);
    $legacySummary = ['article_count' => 4, 'tariff_count' => 10, 'per_tariff' => $legacyCounts,
        'changed_count' => 30, 'sent_count' => 10, 'confirmed_count' => 0, 'failed_count' => 30];
    $syncStore->setProgress($legacyId, 'running', 'sync', null, 10, 10, $legacySummary);
    $syncStore->finish($legacyId, 'partial', 4, 10, $legacySummary);
    $legacy = $syncStore->latest();
    $legacyDisplay = $legacy['summary'];
    if ($legacyDisplay['changed_count'] !== 10 || $legacyDisplay['failed_count'] !== 10 ||
        $legacyDisplay['skipped_count'] !== 20 || $legacyDisplay['unchanged_count'] !== 10 ||
        $legacyDisplay['affected_reference_count'] !== 3 || $legacyDisplay['incident_count'] !== 3 ||
        $legacyDisplay['error_count'] !== 30 || count($legacy['errors']) !== 3 ||
        $legacyDisplay !== $syncStore->jobStatus($legacyId)['summary']) {
        fwrite(STDERR, "El panel confundió omisiones, errores reales y precios sin cambios de un sync antiguo.\n");
        $failures++;
    }
    $omittedOnly = $syncStore->jobStatus($legacyId);
    $omittedOnly['summary']['changed_count'] = 0;
    if (ProgressPresenter::describe($omittedOnly)['progress'] !== 'No hay precios pendientes de actualizar entre las referencias asociadas a Shopify.') {
        fwrite(STDERR, "El sync sin cambios se presentó como un envío fallido.\n");
        $failures++;
    }
    unset($syncStore);
    $second = $store->queueInitialLoad();
    if ($second <= $first) {
        fwrite(STDERR, "No se permitió una nueva carga después de terminar la primera.\n");
        $failures++;
    }
    $store->claimNext();
    $store->failInterruptedJobs();
    if ($store->latestInitialLoad()['status'] !== 'failed' || $store->activeInitialLoad()) {
        fwrite(STDERR, "Una carga interrumpida quedó bloqueando nuevas acciones.\n");
        $failures++;
    }
    unset($store);

    $sourceSqlPath = $envRoot . '/duplicate-source.sql';
    $columns = ["'1567RCAJA' AS \"IdArtículo\"", "'Marca' AS \"Marca\""];
    foreach ($tariffs as $tariff) {
        $columns[] = "'1.00' AS \"{$tariff}\"";
    }
    file_put_contents($sourceSqlPath, 'SELECT ' . implode(', ', $columns) . " UNION ALL SELECT '1567RCAJA', 'Marca', " . implode(', ', array_fill(0, 10, "'1.00'")));
    $currencies = array_fill_keys($tariffs, 'EUR');
    $decimals = array_fill_keys($tariffs, '2');
    $sourceConfig = new Config('sqlite::memory:', '', '', $tariffs, $envRoot . '/var/block.sqlite', $sourceSqlPath, $envRoot . '/var', 'example.myshopify.com', 'test-token', $currencies, $decimals);
    $blockStore = new StateStore($sourceConfig->sqlitePath);
    $blockedJob = $blockStore->queueInitialLoad();
    (new InitialLoad($sourceConfig, $blockStore, new ShopifyClient($sourceConfig)))->start($blockedJob);
    $blocked = $blockStore->latestInitialLoad();
    if ($blocked['status'] !== 'failed' || $blocked['operation_id'] !== null || !in_array('duplicate_article_id', array_column($blocked['errors'], 'code'), true)) {
        fwrite(STDERR, "La carga inicial no falló cuando ninguna referencia quedó apta.\n");
        $failures++;
    }
    unset($blockStore);

    $regularPrices = implode(', ', array_fill(0, 10, "'1.00'"));
    $precisionPrices = "'1.234', " . implode(', ', array_fill(0, 9, "'1.00'"));
    file_put_contents($sourceSqlPath, 'SELECT ' . implode(', ', $columns)
        . " UNION ALL SELECT '1567RCAJA', 'Marca', {$regularPrices}"
        . " UNION ALL SELECT 'SKU-OK', 'Marca', {$regularPrices}"
        . " UNION ALL SELECT 'SKU-PRECISION', 'Marca', {$precisionPrices}");
    $mixedConfig = new Config('sqlite::memory:', '', '', $tariffs, $envRoot . '/var/mixed.sqlite', $sourceSqlPath, $envRoot . '/var', 'example.myshopify.com', 'test-token', $currencies, $decimals);
    $mixedStore = new StateStore($mixedConfig->sqlitePath);
    $mixedJob = $mixedStore->queueInitialLoad();
    $mixedSummary = (new InitialLoad($mixedConfig, $mixedStore, new ShopifyClient($mixedConfig)))->prepareSource($mixedJob);
    $mixedArticles = $mixedStore->articles($mixedJob);
    $mixedCodes = array_column($mixedStore->latestInitialLoad()['errors'], 'code');
    if ($mixedSummary['eligible_articles'] !== 1 || $mixedArticles !== ['SKU-OK'] ||
        !in_array('duplicate_article_id', $mixedCodes, true) || !in_array('price_precision', $mixedCodes, true) ||
        count($mixedStore->expectedCounts($mixedJob)) !== 10) {
        fwrite(STDERR, "La preparación no omitió referencias con incidencias y conservó las válidas.\n");
        $failures++;
    }
    unset($mixedStore);
} finally {
    unset($syncStore, $mixedStore, $blockStore, $store);
    if (is_file($envPath ?? '')) {
        unlink($envPath);
    }
    if (is_file($sourceSqlPath ?? '')) {
        unlink($sourceSqlPath);
    }
    foreach (glob($envRoot . '/var/*') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($envRoot . '/var')) {
        rmdir($envRoot . '/var');
    }
    rmdir($envRoot);
}

require_once __DIR__ . '/verify-variant-sync.php';
try {
    verifyVariantSync();
    echo "Verificación offline correcta: búsqueda de variantes y sincronización en el mismo job.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Falló la verificación de variantes: {$error->getMessage()}\n");
    $failures++;
}

if ($failures > 0) {
    fwrite(STDERR, "Verificación fallida: {$failures} problema(s).\n");
    exit(1);
}
echo "Verificación correcta: sintaxis PHP y validaciones básicas.\n";
