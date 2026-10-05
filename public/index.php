<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Cache-Control: no-store, max-age=0');
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'cookie_samesite' => 'Lax',
]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$notice = null;
$noticeClass = 'info';
$pageError = null;
$statusCode = 200;
$scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$publicSuffix = '/public/index.php';
$basePath = str_ends_with($scriptPath, $publicSuffix)
    ? substr($scriptPath, 0, -strlen($publicSuffix))
    : rtrim(dirname($scriptPath), '/');
$basePath = str_replace('\\', '/', $basePath);
if ($basePath === '/' || $basePath === '.') {
    $basePath = '';
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
    $path = substr($path, strlen($basePath)) ?: '/';
}

if ($path === '/status' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $requestedId = $_GET['id'] ?? null;
    if (!is_string($requestedId) || preg_match('/^[1-9][0-9]*$/', $requestedId) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'Identificador de acción inválido.']);
        exit;
    }
    try {
        $job = app_store()->jobStatus((int) $requestedId);
        if ($job === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Acción no encontrada.']);
        } else {
            echo json_encode(ProgressPresenter::describe($job), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo consultar el estado.']);
    }
    exit;
}

if (in_array($path, ['/actions/preview', '/actions/initial-load', '/actions/sync'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $providedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $providedToken)) {
        $statusCode = 403;
        $pageError = 'La solicitud caducó. Recarga la página e inténtalo de nuevo.';
    } else {
        try {
            if ($path === '/actions/initial-load') {
                app_config()->requireShopify();
                $jobId = app_store()->queueInitialLoad();
            } elseif ($path === '/actions/sync') {
                app_config()->requireShopify();
                $jobId = app_store()->queueSync();
            } else {
                $jobId = app_store()->queuePreview();
            }
            header('Location: ' . $basePath . '/?queued=' . $jobId, true, 303);
            exit;
        } catch (Throwable $error) {
            $pageError = $error->getMessage();
        }
    }
} elseif ($path !== '/' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    $statusCode = 404;
    $pageError = 'Página no encontrada.';
}

http_response_code($statusCode);
try {
    $latestJob = app_store()->latest();
    $initialJob = app_store()->latestInitialLoad();
    $activeInitial = app_store()->activeInitialLoad();
    $catalogs = app_store()->catalogs();
    $nightlySyncTime = app_config()->nightlySyncTime;
} catch (Throwable $error) {
    $latestJob = null;
    $initialJob = null;
    $activeInitial = false;
    $catalogs = [];
    $nightlySyncTime = '02:00';
    $pageError ??= $error->getMessage();
}
$jobsToShow = $latestJob === null ? [] : [$latestJob];
if ($initialJob !== null && ($latestJob === null || $initialJob['id'] !== $latestJob['id'])) {
    $jobsToShow[] = $initialJob;
}

if (isset($_GET['queued'])) {
    $queuedId = (int) $_GET['queued'];
    $notice = 'Acción #' . $queuedId . ' en cola. El trabajador de cron la procesará.';
    if ($latestJob !== null && (int) $latestJob['id'] === $queuedId) {
        if ($latestJob['status'] === 'running') {
            $notice = 'Acción #' . $queuedId . ' en curso.';
        } elseif ($latestJob['status'] === 'waiting_shopify') {
            $notice = 'Acción #' . $queuedId . ' esperando a Shopify.';
        } elseif ($latestJob['status'] === 'completed') {
            $notice = 'Acción #' . $queuedId . ' finalizada correctamente.';
            $noticeClass = 'success';
        } elseif ($latestJob['status'] === 'partial') {
            $notice = 'Acción #' . $queuedId . ' finalizada con incidencias. Revisa el detalle.';
            $noticeClass = 'warning';
        } elseif ($latestJob['status'] === 'failed') {
            $notice = 'Falló la acción #' . $queuedId . '. Revisa el detalle.';
            $noticeClass = 'danger';
        }
    }
} elseif ($latestJob !== null && $latestJob['status'] === 'pending') {
    $notice = 'Hay una acción pendiente. El trabajador de cron la procesará.';
}

$nowForSync = new DateTimeImmutable('now');
$nextSync = $nowForSync->setTime((int) substr($nightlySyncTime, 0, 2), (int) substr($nightlySyncTime, 3, 2));
if ($nextSync <= $nowForSync) {
    $nextSync = $nextSync->modify('+1 day');
}

function ui_number(int $value): string
{
    return number_format($value, 0, ',', '.');
}

function ui_datetime(?string $value, bool $includeSeconds = false): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    try {
        $date = new DateTimeImmutable($value);
        $formatted = $date->format($includeSeconds ? 'd/m/Y · H:i:s' : 'd/m/Y · H:i');
        return $formatted . ($date->getOffset() === 0 ? ' UTC' : ' ' . $date->format('T'));
    } catch (Throwable) {
        return $value;
    }
}

function ui_incident_label(string $code): string
{
    return match ($code) {
        'variant_unmapped' => 'Sin variante asociada',
        'missing_sku' => 'SKU no encontrado en Shopify',
        'ambiguous_sku' => 'SKU repetido en Shopify',
        'duplicate_article_id' => 'Referencia repetida en SQL',
        'price_precision' => 'Demasiados decimales',
        'missing_price' => 'Precio ausente',
        'invalid_price' => 'Precio inválido',
        'price_sync_failed', 'price_unconfirmed' => 'Precio sin confirmar',
        'price_sync_request_failed' => 'Error al enviar precios',
        default => $code,
    };
}

function ui_incident_message(array $error): string
{
    return $error['code'] === 'variant_unmapped'
        ? 'Referencia omitida: no tiene una variante Shopify asociada. No se han enviado sus precios.'
        : (string) $error['message'];
}

function ui_job_title(array $job): string
{
    return match ($job['action']) {
        'initial_load' => 'Carga inicial',
        'sync' => 'Sincronización',
        default => 'Validación',
    };
}

function ui_status_style(string $status): string
{
    return match ($status) {
        'completed' => 'success',
        'partial' => 'warning',
        'failed' => 'danger',
        'running' => 'primary',
        'waiting_shopify' => 'info',
        default => 'secondary',
    };
}

function ui_status_icon(string $status): string
{
    return match ($status) {
        'completed' => 'check-circle',
        'partial' => 'exclamation-triangle',
        'failed' => 'x-octagon',
        'running' => 'arrow-repeat',
        'waiting_shopify' => 'cloud-arrow-up',
        default => 'clock',
    };
}

function ui_step_position(array $job, array $display): int
{
    if (!$display['active']) {
        return 3;
    }

    $phase = (string) ($job['phase'] ?? '');
    if (in_array($phase, ['variants', 'variants_results', 'variants_match'], true)) {
        return 1;
    }
    if (in_array($phase, ['prices_prepare', 'prices_upload', 'prices', 'prices_results', 'sync'], true)) {
        return 2;
    }
    return 0;
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (($latestJob !== null && in_array($latestJob['status'], ['pending', 'running'], true)) || $activeInitial): ?>
        <noscript><meta http-equiv="refresh" content="10"></noscript>
    <?php endif; ?>
    <title>Tarifas Shopify</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= escape($basePath) ?>/app.css" rel="stylesheet">
</head>
<body>
<main class="container-fluid app-shell px-3 px-md-4 pb-5">
    <header class="app-header d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3">
        <div>
            <h1 class="h2 fw-bold mb-1">Tarifas Shopify</h1>
            <p class="app-kicker mb-0">Valida la fuente y supervisa las cargas y sincronizaciones. El cron procesa las acciones en segundo plano.</p>
        </div>
        <?php if ($latestJob !== null): ?>
            <?php $latestDisplay = ProgressPresenter::describe($latestJob); ?>
            <div class="text-md-end small text-secondary">
                <span class="d-block">Última actualización</span>
                <time datetime="<?= escape($latestDisplay['last_activity']) ?>"><?= escape(ui_datetime($latestDisplay['last_activity'])) ?></time>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($notice !== null): ?>
        <div class="alert alert-<?= escape($noticeClass) ?> d-flex align-items-center gap-2" role="status">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <span><?= escape($notice) ?></span>
        </div>
    <?php endif; ?>
    <?php if ($pageError !== null): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
            <span><?= escape($pageError) ?></span>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-start">
        <aside class="col-lg-3 order-2 order-lg-1" aria-labelledby="actions-heading">
            <div class="app-card action-panel sticky-actions">
                <h2 id="actions-heading" class="h5 fw-bold mb-1">¿Qué quieres hacer?</h2>
                <p class="text-secondary mb-3">Acciones seguras para gestionar las tarifas en Shopify.</p>

                <div class="action-list">
                    <section class="action-item action-item-primary" aria-labelledby="validate-heading">
                        <div class="d-flex gap-2 align-items-start mb-3">
                            <span class="action-icon text-primary"><i class="bi bi-search" aria-hidden="true"></i></span>
                            <div>
                                <h3 id="validate-heading" class="h6 text-primary fw-bold mb-1">Validar datos</h3>
                                <p class="action-copy mb-0">Revisa la fuente, los catálogos y las reglas. No modifica Shopify.</p>
                            </div>
                        </div>
                        <form method="post" action="<?= escape($basePath) ?>/actions/preview">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <button class="btn btn-primary w-100" type="submit">Validar datos</button>
                        </form>
                    </section>

                    <section class="action-item action-item-primary" aria-labelledby="load-heading">
                        <div class="d-flex gap-2 align-items-start mb-3">
                            <span class="action-icon text-primary"><i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i></span>
                            <div>
                                <h3 id="load-heading" class="h6 text-primary fw-bold mb-1">Iniciar carga inicial</h3>
                                <p class="action-copy mb-0">Carga tarifas y precios. Las referencias con errores se omiten; las demás continúan.</p>
                            </div>
                        </div>
                        <form method="post" action="<?= escape($basePath) ?>/actions/initial-load">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <button class="btn btn-outline-primary w-100" type="submit" <?= $activeInitial ? 'disabled aria-describedby="initial-load-help"' : '' ?>>Iniciar carga inicial</button>
                        </form>
                        <?php if ($activeInitial): ?><small id="initial-load-help" class="d-block text-secondary mt-2">Ya hay una carga inicial en curso.</small><?php endif; ?>
                    </section>

                    <section class="action-item action-item-success" aria-labelledby="sync-heading">
                        <div class="d-flex gap-2 align-items-start mb-3">
                            <span class="action-icon text-success"><i class="bi bi-arrow-repeat" aria-hidden="true"></i></span>
                            <div>
                                <h3 id="sync-heading" class="h6 text-success fw-bold mb-1">Sincronizar ahora</h3>
                                <p class="action-copy mb-0">Sincroniza cambios y vuelve a intentar los precios no confirmados.</p>
                            </div>
                        </div>
                        <form method="post" action="<?= escape($basePath) ?>/actions/sync">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <button class="btn btn-success w-100" type="submit">Sincronizar ahora</button>
                        </form>
                    </section>
                </div>

                <div class="sync-note mt-3 d-flex gap-2 align-items-start">
                    <i class="bi bi-clock-history fs-5" aria-hidden="true"></i>
                    <div>
                        <strong class="d-block mb-1">Próxima sincronización nocturna</strong>
                        <time datetime="<?= escape($nextSync->format(DateTimeInterface::ATOM)) ?>"><?= escape($nextSync->format('d/m/Y · H:i T')) ?></time>
                        <span class="d-block small mt-2">Los precios no confirmados se vuelven a intentar.</span>
                    </div>
                </div>
            </div>
        </aside>

        <div class="col-lg-9 order-1 order-lg-2">
            <?php if ($jobsToShow === []): ?>
                <section class="app-card empty-state" aria-labelledby="empty-heading">
                    <i class="bi bi-inbox fs-1 text-secondary" aria-hidden="true"></i>
                    <h2 id="empty-heading" class="h5 mt-3">Todavía no hay acciones</h2>
                    <p class="text-secondary mb-0">Valida los datos para comprobar la fuente antes de iniciar una carga.</p>
                </section>
            <?php endif; ?>

            <?php foreach ($jobsToShow as $jobIndex => $shownJob): ?>
                <?php
                $display = ProgressPresenter::describe($shownJob);
                $summary = $shownJob['summary'];
                $perTariff = $summary['per_tariff'] ?? [];
                $confirmedTotal = 0;
                foreach ($perTariff as $counts) {
                    $confirmedTotal += (int) ($counts['confirmed'] ?? 0);
                }
                if ($perTariff === []) {
                    $confirmedTotal = (int) ($summary['confirmed_count'] ?? 0);
                }
                $eligibleArticles = (int) ($summary['eligible_articles'] ?? $summary['valid_rows'] ?? 0);
                $errorCount = (int) ($summary['error_count'] ?? 0);
                [$secondaryMetricValue, $secondaryMetricLabel, $fourthMetricValue, $fourthMetricLabel, $fourthMetricClass] = match ($shownJob['action']) {
                    'sync' => [(int) ($summary['changed_count'] ?? 0), 'Precios a actualizar', $confirmedTotal, 'Precios confirmados', 'metric-success'],
                    'preview' => [(int) ($summary['valid_rows'] ?? 0), 'Filas válidas', (int) ($summary['invalid_rows'] ?? 0), 'Filas con incidencias', 'metric-warning'],
                    default => [$eligibleArticles, 'Referencias aptas', $confirmedTotal, 'Precios confirmados', 'metric-success'],
                };
                $statusStyle = ui_status_style((string) $shownJob['status']);
                $stepPosition = ui_step_position($shownJob, $display);
                $isFinalWarning = !$display['active'] && in_array($shownJob['status'], ['partial', 'failed'], true);
                $jobLead = $display['lead'];
                $steps = match ($shownJob['action']) {
                    'sync' => [
                        ['Fuente', 'Lectura de artículos', ui_number((int) $shownJob['article_count']) . ' leídos'],
                        ['Comparación', 'Precios con variante asociada', ui_number((int) ($summary['changed_count'] ?? 0)) . ' a actualizar'],
                        ['Shopify', 'Envío y confirmación', ui_number((int) ($summary['sent_count'] ?? 0)) . ' enviados · ' . ui_number((int) ($summary['confirmed_count'] ?? 0)) . ' confirmados'],
                        ['Resultado', $display['active'] ? 'Proceso en curso' : 'Proceso finalizado', $display['status_label']],
                    ],
                    'preview' => [
                        ['Fuente', 'Lectura de artículos', ui_number((int) $shownJob['article_count']) . ' leídos'],
                        ['Validación', 'Comprobación de filas', ui_number((int) ($summary['valid_rows'] ?? 0)) . ' válidas'],
                        ['Incidencias', 'Revisión de referencias', ui_number($errorCount) . ' registradas'],
                        ['Resultado', $display['active'] ? 'Proceso en curso' : 'Proceso finalizado', $display['status_label']],
                    ],
                    default => [
                        ['Fuente', 'Lectura de artículos', ui_number((int) $shownJob['article_count']) . ' leídos'],
                        ['Variantes', 'Validación y coincidencias', ui_number($eligibleArticles) . ' aptas'],
                        ['Precios', 'Carga en Shopify', ui_number($confirmedTotal) . ' confirmados'],
                        ['Resultado', $display['active'] ? 'Proceso en curso' : 'Proceso finalizado', $display['status_label']],
                    ],
                };
                $visibleErrors = array_slice($shownJob['errors'], 0, 5);
                $hiddenErrors = array_slice($shownJob['errors'], 5);
                $collapseId = 'more-incidents-' . (int) $shownJob['id'];
                $accordionId = 'technical-' . (int) $shownJob['id'];
                ?>
                <article class="mb-4" aria-labelledby="job-<?= (int) $shownJob['id'] ?>" data-job-id="<?= (int) $shownJob['id'] ?>" data-job-phase="<?= escape($display['phase']) ?>" data-job-active="<?= $display['active'] ? '1' : '0' ?>" data-last-activity="<?= escape($display['last_activity']) ?>">
                    <section class="app-card job-summary mb-3">
                        <div class="d-flex flex-column flex-xl-row justify-content-between gap-4">
                            <div>
                                <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
                                    <h2 id="job-<?= (int) $shownJob['id'] ?>" class="h4 fw-bold mb-0"><?= escape(ui_job_title($shownJob)) ?> #<?= (int) $shownJob['id'] ?></h2>
                                    <span class="status-badge status-badge-<?= escape($statusStyle) ?>" data-job-status-box>
                                        <i class="bi bi-<?= escape(ui_status_icon((string) $shownJob['status'])) ?>" data-job-status-icon aria-hidden="true"></i>
                                        <span data-job-status><?= escape($display['stage']) ?></span>
                                    </span>
                                </div>
                                <p class="text-secondary mb-0" data-job-stage><?= escape($jobLead) ?></p>
                            </div>
                            <div class="time-summary small">
                                <div><span class="d-block fw-semibold">Creada</span><time datetime="<?= escape($shownJob['created_at']) ?>"><?= escape(ui_datetime($shownJob['created_at'])) ?></time></div>
                                <div><span class="d-block fw-semibold">Inicio</span><time datetime="<?= escape($shownJob['started_at'] ?? '') ?>"><?= escape(ui_datetime($shownJob['started_at'])) ?></time></div>
                                <div><span class="d-block fw-semibold">Finalización</span><time data-job-completed datetime="<?= escape($shownJob['completed_at'] ?? '') ?>"><?= escape(ui_datetime($shownJob['completed_at'])) ?></time></div>
                            </div>
                        </div>

                        <div class="metric-strip" aria-label="Resumen de la operación">
                            <div class="metric"><span class="metric-value" data-job-articles><?= escape(ui_number((int) $shownJob['article_count'])) ?></span><span class="metric-label">Artículos leídos</span></div>
                            <div class="metric"><span class="metric-value"><?= escape(ui_number($secondaryMetricValue)) ?></span><span class="metric-label"><?= escape($secondaryMetricLabel) ?></span></div>
                            <div class="metric metric-warning"><span class="metric-value" data-job-errors><?= escape(ui_number((int) ($summary['affected_reference_count'] ?? 0))) ?></span><span class="metric-label">Referencias con incidencias</span></div>
                            <div class="metric <?= escape($fourthMetricClass) ?>"><span class="metric-value"><?= escape(ui_number($fourthMetricValue)) ?></span><span class="metric-label"><?= escape($fourthMetricLabel) ?></span></div>
                            <div class="metric"><span class="metric-value"><?= escape(ui_number((int) $shownJob['tariff_count'])) ?></span><span class="metric-label">Tarifas de la operación</span></div>
                        </div>
                        <?php if ($shownJob['action'] === 'sync' && isset($summary['skipped_count'])): ?>
                            <p class="small text-secondary mt-3 mb-0"><strong><?= escape(ui_number((int) $summary['unchanged_count'])) ?> precios sin cambios</strong>: ya estaban confirmados y no se vuelven a enviar. <strong><?= escape(ui_number((int) $summary['unmapped_article_count'])) ?> referencias omitidas</strong> por falta de variante asociada (<?= escape(ui_number((int) $summary['skipped_count'])) ?> precios). Estas omisiones no son errores de envío a Shopify.</p>
                        <?php endif; ?>
                    </section>

                    <section class="app-card mb-3" aria-labelledby="steps-<?= (int) $shownJob['id'] ?>">
                        <div class="section-heading"><h3 id="steps-<?= (int) $shownJob['id'] ?>" class="h5 mb-0">Etapas del proceso</h3></div>
                        <ol class="process-steps">
                            <?php foreach ($steps as $stepIndex => $step): ?>
                                <?php
                                $stepClass = $stepIndex < $stepPosition || (!$display['active'] && $stepIndex < 3) ? 'is-done' : ($stepIndex === $stepPosition ? 'is-current' : '');
                                if ($stepIndex === 3 && $isFinalWarning) {
                                    $stepClass = 'is-warning';
                                } elseif ($stepIndex === 3 && !$display['active'] && $shownJob['status'] === 'completed') {
                                    $stepClass = 'is-done';
                                }
                                ?>
                                <li class="process-step <?= escape($stepClass) ?>">
                                    <span class="step-marker"><i class="bi bi-<?= $stepClass === 'is-done' ? 'check-lg' : ($stepClass === 'is-warning' ? 'exclamation-lg' : 'circle-fill') ?>" aria-hidden="true"></i></span>
                                    <div class="step-content">
                                        <strong class="d-block"><?= ($stepIndex + 1) ?>. <?= escape($step[0]) ?></strong>
                                        <span class="d-block small text-secondary"><?= escape($step[1]) ?></span>
                                        <span class="step-value d-block mt-1"><?= escape($step[2]) ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <div class="progress-copy d-flex gap-2 align-items-start" aria-live="polite">
                            <span class="spinner-border spinner-border-sm mt-1<?= $display['active'] ? '' : ' d-none' ?>" role="status" aria-label="En curso"></span>
                            <div>
                                <strong data-job-progress><?= escape($display['progress']) ?></strong>
                                <small class="d-block text-secondary" data-job-detail><?= escape($display['detail']) ?></small>
                                <small class="d-block text-danger" data-job-network></small>
                            </div>
                        </div>
                    </section>

                    <section class="app-card mb-3" aria-labelledby="incidents-<?= (int) $shownJob['id'] ?>">
                        <div class="section-heading d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2">
                            <div>
                                <h3 id="incidents-<?= (int) $shownJob['id'] ?>" class="h5 mb-1">Incidencias por referencia y motivo (<?= escape(ui_number((int) ($summary['incident_count'] ?? $errorCount))) ?>)</h3>
                                <p class="small text-secondary mb-0">Mostramos hasta 50 incidencias recientes, agrupando las repeticiones de una referencia. Las incidencias sin referencia corresponden a la operación.</p>
                                <?php if ($shownJob['action'] === 'sync'): ?><p class="small text-secondary mt-1 mb-0">Sin variante asociada: falta la relación guardada entre el SKU y Shopify. No se envían sus precios. Los SKU nuevos se comprueban automáticamente durante la sincronización.</p><?php endif; ?>
                            </div>
                            <?php if ($hiddenErrors !== []): ?>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= escape($collapseId) ?>" aria-expanded="false" aria-controls="<?= escape($collapseId) ?>">Ver más incidencias recientes</button>
                            <?php endif; ?>
                        </div>
                        <?php if ($shownJob['errors'] === []): ?>
                            <div class="p-4 d-flex gap-2 align-items-center text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><span>No hay incidencias registradas en esta operación.</span></div>
                        <?php else: ?>
                            <div class="table-responsive px-3 pb-2">
                                <table class="table table-sm incidents-table mb-0">
                                    <thead><tr><th scope="col">Referencia</th><th scope="col">Mensaje</th><th scope="col">Fila</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($visibleErrors as $jobError): ?>
                                        <tr>
                                            <th scope="row"><span class="d-block"><?= escape($jobError['article_id'] ?? 'Operación general') ?></span><span class="incident-code"><?= escape(ui_incident_label($jobError['code'])) ?></span></th>
                                            <td><?= escape(ui_incident_message($jobError)) ?></td>
                                            <td><?= escape($jobError['row_number'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <?php if ($hiddenErrors !== []): ?>
                                        <tbody class="collapse" id="<?= escape($collapseId) ?>">
                                        <?php foreach ($hiddenErrors as $jobError): ?>
                                            <tr>
                                                <th scope="row"><span class="d-block"><?= escape($jobError['article_id'] ?? 'Operación general') ?></span><span class="incident-code"><?= escape(ui_incident_label($jobError['code'])) ?></span></th>
                                                <td><?= escape(ui_incident_message($jobError)) ?></td>
                                                <td><?= escape($jobError['row_number'] ?? '—') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    <?php endif; ?>
                                </table>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="accordion technical-accordion" aria-label="Detalles técnicos de la operación">
                        <div class="accordion-item app-card overflow-hidden border">
                            <h3 class="accordion-header">
                                <button class="accordion-button<?= $jobIndex === 0 ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= escape($accordionId) ?>" aria-expanded="<?= $jobIndex === 0 ? 'true' : 'false' ?>" aria-controls="<?= escape($accordionId) ?>">
                                    <span><strong class="d-block">Detalles técnicos</strong><small class="d-block text-secondary fw-normal">Información completa de la operación, catálogos y tarifas procesadas.</small></span>
                                </button>
                            </h3>
                            <div id="<?= escape($accordionId) ?>" class="accordion-collapse collapse<?= $jobIndex === 0 ? ' show' : '' ?>">
                                <div class="accordion-body pt-0">
                                    <ul class="nav nav-tabs technical-tabs" role="tablist">
                                        <li class="nav-item" role="presentation"><button class="nav-link active" id="summary-tab-<?= (int) $shownJob['id'] ?>" data-bs-toggle="tab" data-bs-target="#summary-pane-<?= (int) $shownJob['id'] ?>" type="button" role="tab" aria-controls="summary-pane-<?= (int) $shownJob['id'] ?>" aria-selected="true">Resumen</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link" id="catalogs-tab-<?= (int) $shownJob['id'] ?>" data-bs-toggle="tab" data-bs-target="#catalogs-pane-<?= (int) $shownJob['id'] ?>" type="button" role="tab" aria-controls="catalogs-pane-<?= (int) $shownJob['id'] ?>" aria-selected="false">Catálogos y tarifas</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link" id="tariffs-tab-<?= (int) $shownJob['id'] ?>" data-bs-toggle="tab" data-bs-target="#tariffs-pane-<?= (int) $shownJob['id'] ?>" type="button" role="tab" aria-controls="tariffs-pane-<?= (int) $shownJob['id'] ?>" aria-selected="false">Progreso por tarifa</button></li>
                                        <li class="nav-item" role="presentation"><button class="nav-link" id="data-tab-<?= (int) $shownJob['id'] ?>" data-bs-toggle="tab" data-bs-target="#data-pane-<?= (int) $shownJob['id'] ?>" type="button" role="tab" aria-controls="data-pane-<?= (int) $shownJob['id'] ?>" aria-selected="false">Datos de la operación</button></li>
                                    </ul>
                                    <div class="tab-content pt-3">
                                        <div class="tab-pane fade show active" id="summary-pane-<?= (int) $shownJob['id'] ?>" role="tabpanel" aria-labelledby="summary-tab-<?= (int) $shownJob['id'] ?>" tabindex="0">
                                            <div class="row g-3 small">
                                                <div class="col-md-6 col-xl-3"><span class="d-block fw-semibold">Operación Shopify</span><?php if ($shownJob['operation_id'] !== null): ?><span class="d-flex align-items-center gap-2"><code class="technical-value"><?= escape($shownJob['operation_id']) ?></code><button class="btn btn-sm btn-light copy-button" type="button" data-copy="<?= escape($shownJob['operation_id']) ?>" aria-label="Copiar identificador de operación"><i class="bi bi-copy" aria-hidden="true"></i></button></span><?php else: ?><span>—</span><?php endif; ?></div>
                                                <div class="col-md-6 col-xl-3"><span class="d-block fw-semibold">Última actividad</span><span data-job-activity><?= escape(ui_datetime($display['last_activity'], true)) ?></span></div>
                                                <div class="col-md-6 col-xl-3"><span class="d-block fw-semibold">Estado</span><span><?= escape($display['status_label']) ?></span></div>
                                                <div class="col-md-6 col-xl-3"><span class="d-block fw-semibold">Etapa</span><span><?= escape($display['stage']) ?></span></div>
                                            </div>
                                        </div>
                                        <div class="tab-pane fade" id="catalogs-pane-<?= (int) $shownJob['id'] ?>" role="tabpanel" aria-labelledby="catalogs-tab-<?= (int) $shownJob['id'] ?>" tabindex="0">
                                            <?php if ($catalogs === []): ?><p class="text-secondary mb-0">Se mostrarán al preparar una carga inicial. Los títulos ambiguos aparecen en las incidencias.</p>
                                            <?php else: ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Tarifa</th><th>Catálogo</th><th>Lista de precios</th><th>Moneda</th><th>Resuelto</th></tr></thead><tbody>
                                                <?php foreach ($catalogs as $catalog): ?><tr><th><?= escape($catalog['tariff']) ?></th><td><code class="technical-value"><?= escape($catalog['catalog_id']) ?></code></td><td><code class="technical-value"><?= escape($catalog['price_list_id'] ?: 'Pendiente') ?></code></td><td><?= escape($catalog['currency']) ?></td><td><?= escape(ui_datetime($catalog['resolved_at'])) ?></td></tr><?php endforeach; ?>
                                            </tbody></table></div><?php endif; ?>
                                        </div>
                                        <div class="tab-pane fade" id="tariffs-pane-<?= (int) $shownJob['id'] ?>" role="tabpanel" aria-labelledby="tariffs-tab-<?= (int) $shownJob['id'] ?>" tabindex="0">
                                            <?php if ($perTariff === []): ?><p class="text-secondary mb-0">Esta operación no contiene un desglose por tarifa.</p>
                                            <?php else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tarifa</th><?php if ($shownJob['action'] === 'sync'): ?><th>Sin cambios</th><th>A actualizar</th><th>Enviados</th><th>Confirmados</th><th>Errores de envío o confirmación</th><th>Omitidos sin variante</th><?php else: ?><th>Preparados</th><th>Confirmados</th><?php endif; ?></tr></thead><tbody>
                                                <?php foreach ($perTariff as $tariff => $counts): ?><tr><th><?= escape($tariff) ?></th><?php if ($shownJob['action'] === 'sync'): ?><td><?= escape(ui_number((int) ($counts['unchanged'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['changed'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['sent'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['confirmed'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['failed'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['skipped'] ?? 0))) ?></td><?php else: ?><td><?= escape(ui_number((int) ($counts['prepared'] ?? 0))) ?></td><td><?= escape(ui_number((int) ($counts['confirmed'] ?? 0))) ?></td><?php endif; ?></tr><?php endforeach; ?>
                                            </tbody></table></div><?php endif; ?>
                                            <?php if ($shownJob['action'] === 'sync'): ?><p class="form-text mt-2">Cada celda cuenta precios de esa tarifa. Una referencia omitida puede tener un precio en cada tarifa; el resumen superior cuenta referencias únicas. «A actualizar» incluye precios nuevos y cambios respecto al último valor confirmado.</p><?php endif; ?>
                                            <?php if ($display['active'] && $shownJob['phase'] === 'prices'): ?><p class="form-text mt-2">Los precios confirmados se cuentan al recibir las respuestas finales de Shopify.</p><?php endif; ?>
                                        </div>
                                        <div class="tab-pane fade" id="data-pane-<?= (int) $shownJob['id'] ?>" role="tabpanel" aria-labelledby="data-tab-<?= (int) $shownJob['id'] ?>" tabindex="0">
                                            <dl class="row small mb-0">
                                                <dt class="col-sm-4 col-lg-3">Acción</dt><dd class="col-sm-8 col-lg-9"><?= escape(ui_job_title($shownJob)) ?></dd>
                                                <dt class="col-sm-4 col-lg-3">Creada</dt><dd class="col-sm-8 col-lg-9"><?= escape($shownJob['created_at']) ?></dd>
                                                <dt class="col-sm-4 col-lg-3">Inicio</dt><dd class="col-sm-8 col-lg-9"><?= escape($shownJob['started_at'] ?? '—') ?></dd>
                                                <dt class="col-sm-4 col-lg-3">Finalización</dt><dd class="col-sm-8 col-lg-9"><?= escape($shownJob['completed_at'] ?? '—') ?></dd>
                                                <dt class="col-sm-4 col-lg-3">Filas válidas</dt><dd class="col-sm-8 col-lg-9"><?= escape(ui_number((int) ($summary['valid_rows'] ?? 0))) ?></dd>
                                                <dt class="col-sm-4 col-lg-3">Filas con incidencias</dt><dd class="col-sm-8 col-lg-9"><?= escape(ui_number((int) ($summary['invalid_rows'] ?? 0))) ?></dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script>
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    try {
        await navigator.clipboard.writeText(button.dataset.copy);
        const icon = button.querySelector('i');
        icon.className = 'bi bi-check-lg';
        button.setAttribute('aria-label', 'Identificador copiado');
        window.setTimeout(() => {
            icon.className = 'bi bi-copy';
            button.setAttribute('aria-label', 'Copiar identificador de operación');
        }, 1800);
    } catch (error) {
        button.setAttribute('aria-label', 'No se pudo copiar el identificador');
    }
});

(() => {
    const cards = [...document.querySelectorAll('[data-job-id][data-job-active="1"]')];
    if (cards.length === 0) return;

    const statusUrl = <?= json_encode($basePath . '/status', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const numberLabel = new Intl.NumberFormat('es-ES');
    const statusAppearance = (status) => ({
        completed: ['success', 'check-circle'],
        partial: ['warning', 'exclamation-triangle'],
        failed: ['danger', 'x-octagon'],
        running: ['primary', 'arrow-repeat'],
        waiting_shopify: ['info', 'cloud-arrow-up'],
        pending: ['secondary', 'clock'],
    }[status] ?? ['secondary', 'clock']);
    const activityLabel = (value) => {
        const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000));
        if (!Number.isFinite(seconds)) return value;
        const elapsed = seconds < 60 ? `${seconds} s` : seconds < 3600 ? `${Math.floor(seconds / 60)} min` : `${Math.floor(seconds / 3600)} h ${Math.floor(seconds % 3600 / 60)} min`;
        return `Hace ${elapsed}` + (seconds >= 600 ? ' · Sin novedades; comprueba el cron.' : '');
    };
    const showActivity = () => {
        for (const card of cards) {
            card.querySelector('[data-job-activity]').textContent = activityLabel(card.dataset.lastActivity);
        }
    };

    let updating = false;
    const refresh = async () => {
        if (updating) return;
        updating = true;
        try {
            const updates = await Promise.all(cards.map(async (card) => {
                const response = await fetch(`${statusUrl}?id=${encodeURIComponent(card.dataset.jobId)}`, {cache: 'no-store'});
                if (!response.ok) throw new Error('La consulta de estado falló.');
                return {card, data: await response.json()};
            }));
            for (const {card, data} of updates) {
                if (!data.active || data.phase !== card.dataset.jobPhase) {
                    window.location.reload();
                    return;
                }
                const [style, icon] = statusAppearance(data.status);
                card.querySelector('[data-job-status-box]').className = `status-badge status-badge-${style}`;
                card.querySelector('[data-job-status-icon]').className = `bi bi-${icon}`;
                card.querySelector('[data-job-status]').textContent = data.stage;
                card.querySelector('[data-job-stage]').textContent = data.lead;
                card.querySelector('[data-job-progress]').textContent = data.progress;
                card.querySelector('[data-job-detail]').textContent = data.detail;
                card.querySelector('[data-job-articles]').textContent = numberLabel.format(data.article_count);
                card.querySelector('[data-job-errors]').textContent = numberLabel.format(data.affected_reference_count);
                card.querySelector('[data-job-network]').textContent = '';
                card.dataset.lastActivity = data.last_activity;
            }
            showActivity();
        } catch (error) {
            for (const card of cards) {
                card.querySelector('[data-job-network]').textContent = 'No se pudo actualizar el estado; reintentando.';
            }
        } finally {
            updating = false;
        }
    };
    showActivity();
    refresh();
    setInterval(refresh, 5000);
    setInterval(showActivity, 1000);
})();
</script>
</body>
</html>
