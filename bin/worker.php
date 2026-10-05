#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = app_config();
    $lockPath = $config->storagePath . '/worker.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Ya hay un trabajador activo.\n");
        exit(0);
    }

    $store = app_store();
    $store->failInterruptedJobs();
    $now = new DateTimeImmutable('now');
    if ($now->format('H:i') >= $config->nightlySyncTime) {
        $nightlyJobId = $store->queueNightlySync($now->format('Y-m-d'), $config->nightlySyncTime);
        if ($nightlyJobId !== null) {
            fwrite(STDOUT, "Sincronización nocturna #{$nightlyJobId} encolada para {$now->format('Y-m-d')}.\n");
        }
    }
    $waiting = $store->waitingInitialLoad();
    if ($waiting !== null) {
        try {
            (new InitialLoad($config, $store, new ShopifyClient($config)))->poll($waiting);
        } catch (Throwable $error) {
            fwrite(STDERR, "No se pudo consultar la carga inicial #{$waiting['id']}; cron volverá a intentarlo: {$error->getMessage()}\n");
        }
    }
    $processedJobs = 0;
    if ($waiting !== null && $store->activeInitialLoad()) {
        fwrite(STDOUT, "La carga inicial sigue activa; las demás acciones quedan en cola.\n");
    } else {
    while (($job = $store->claimNext()) !== null) {
        $processedJobs++;
        $jobId = (int) $job['id'];
        fwrite(STDOUT, "Procesando {$job['action']} #{$jobId}.\n");
        $errorCount = 0;
        try {
            if ($job['action'] === 'initial_load') {
                (new InitialLoad($config, $store, new ShopifyClient($config)))->start($jobId);
                fwrite(STDOUT, "Carga inicial #{$jobId} preparada o bloqueada; consulta el panel.\n");
                if ($store->activeInitialLoad()) {
                    fwrite(STDOUT, "La carga inicial sigue activa; las demás acciones quedan en cola.\n");
                    break;
                }
                continue;
            }
            if ($job['action'] === 'sync') {
                (new SyncPrices($config, $store, new ShopifyClient($config)))->start($jobId, $job);
                fwrite(STDOUT, "Sincronización #{$jobId} finalizada o con incidencias; consulta el panel.\n");
                continue;
            }
            $summary = (new SourceReader($config))->preview(
                static function (int $rowNumber, ?string $articleId, string $code, string $message) use ($store, $jobId, &$errorCount): void {
                    $store->addError($jobId, $rowNumber, $articleId, $code, $message);
                    $errorCount++;
                },
                static function (int $rows, int $validRows, int $invalidRows) use ($store, $jobId, &$errorCount, $config): void {
                    $store->setProgress($jobId, 'running', 'source', null, $rows, 0, [
                        'phase_label' => 'Validando artículos de SQL Server',
                        'article_count' => $rows,
                        'tariff_count' => count($config->tariffColumns),
                        'valid_rows' => $validRows,
                        'invalid_rows' => $invalidRows,
                        'error_count' => $errorCount,
                    ]);
                },
            );
            $summary['error_count'] = $errorCount;
            $status = $errorCount === 0 ? 'completed' : 'partial';
            $store->finish($jobId, $status, $summary['article_count'], $summary['tariff_count'], $summary);
            fwrite(STDOUT, "Validación #{$jobId}: {$status}, {$summary['article_count']} artículos y {$errorCount} incidencias.\n");
        } catch (Throwable $error) {
            $store->addError($jobId, null, null, $job['action'] . '_failed', $error->getMessage());
            $store->finish($jobId, 'failed', 0, count($config->tariffColumns), ['error_count' => $store->errorCount($jobId)]);
            fwrite(STDERR, "Falló {$job['action']} #{$jobId}: {$error->getMessage()}\n");
        }
    }
    if ($processedJobs === 0) {
        fwrite(STDOUT, "No hay acciones pendientes.\n");
    }
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Error del trabajador: {$error->getMessage()}\n");
    exit(1);
} finally {
    if (isset($lock) && is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
