<?php

declare(strict_types=1);

final class ProgressPresenter
{
    public static function describe(array $job): array
    {
        $status = (string) $job['status'];
        $phase = (string) ($job['phase'] ?? '');
        $summary = $job['summary'] ?? [];
        $active = in_array($status, ['pending', 'running', 'waiting_shopify'], true);
        $labels = [
            'pending' => 'Pendiente',
            'running' => 'En curso',
            'waiting_shopify' => 'Esperando a Shopify',
            'completed' => 'Completado',
            'partial' => 'Parcial',
            'failed' => 'Fallido',
        ];
        $display = [
            'active' => $active,
            'status' => $status,
            'status_label' => $labels[$status] ?? $status,
            'phase' => $phase,
            'stage' => (string) ($summary['phase_label'] ?? 'Preparando la acción'),
            'progress' => 'Preparando la acción',
            'detail' => '',
            'last_activity' => $job['progress_at'] ?? $job['completed_at'] ?? $job['started_at'] ?? $job['created_at'],
            'completed_at' => $job['completed_at'] ?? null,
            'article_count' => (int) $job['article_count'],
            'error_count' => (int) ($summary['error_count'] ?? 0),
            'affected_reference_count' => (int) ($summary['affected_reference_count'] ?? 0),
            'lead' => match ($status) {
                'completed' => 'Proceso completado correctamente.',
                'partial' => 'Proceso completado con referencias omitidas o precios sin confirmar. Revisa el desglose.',
                'failed' => 'La operación no pudo completarse. Revisa las incidencias para continuar.',
                'pending' => 'La acción está en cola y el worker la procesará en segundo plano.',
                default => 'La operación continúa en segundo plano.',
            },
        ];

        if (!$active) {
            $display['stage'] = match ($status) {
                'completed' => 'Finalizada',
                'partial' => 'Finalizada con incidencias',
                default => 'Fallida',
            };
            if ($job['action'] === 'preview') {
                $display['progress'] = (int) ($summary['valid_rows'] ?? 0) . ' filas válidas de ' . (int) $job['article_count'] . ' leídas';
            } elseif ($job['action'] === 'sync') {
                $changed = (int) ($summary['changed_count'] ?? 0);
                $display['progress'] = $changed > 0
                    ? (int) ($summary['confirmed_count'] ?? 0) . ' de ' . $changed . ' precios a actualizar confirmados'
                    : ($phase === 'sync' ? 'No hay precios pendientes de actualizar entre las referencias asociadas a Shopify.' : 'La sincronización terminó antes de comparar los precios.');
                $display['detail'] = (int) ($summary['sent_count'] ?? 0) . ' precios enviados · ' . (int) ($summary['failed_count'] ?? 0) . ' precios con error de envío o confirmación';
                if (isset($summary['skipped_count'])) {
                    $display['detail'] .= ' · ' . (int) ($summary['unchanged_count'] ?? 0) . ' precios sin cambios · ' . (int) ($summary['unmapped_article_count'] ?? 0) . ' referencias omitidas sin variante asociada (' . (int) $summary['skipped_count'] . ' precios)';
                }
                if (isset($summary['lookup'])) {
                    $lookup = $summary['lookup'];
                    $display['detail'] .= ' · Búsqueda de SKU: ' . (int) $lookup['unique'] . ' asociados, ' . (int) $lookup['missing'] . ' ausentes, ' . (int) $lookup['ambiguous'] . ' ambiguos, ' . (int) $lookup['inconclusive'] . ' sin resultado concluyente';
                }
            } else {
                $prepared = 0;
                $confirmed = 0;
                foreach ($summary['per_tariff'] ?? [] as $counts) {
                    $prepared += (int) ($counts['prepared'] ?? 0);
                    $confirmed += (int) ($counts['confirmed'] ?? 0);
                }
                $display['progress'] = $prepared > 0
                    ? $confirmed . ' / ' . $prepared . ' precios confirmados por Shopify'
                    : 'La carga terminó antes de preparar precios';
                if (isset($summary['result_lines'], $summary['expected_lines'])) {
                    $display['detail'] = (int) $summary['result_lines'] . ' / ' . (int) $summary['expected_lines'] . ' bloques con respuesta de Shopify';
                }
            }
            return $display;
        }

        if ($status === 'pending') {
            $display['stage'] = 'En cola';
            $display['progress'] = 'Esperando a que arranque el worker';
            return $display;
        }

        $count = (int) $job['object_count'];
        $expected = (int) $job['expected_count'];
        if ($phase === 'source' || $job['action'] === 'preview') {
            $display['stage'] = $job['action'] === 'preview' ? 'Validando SQL Server' : 'Leyendo SQL Server';
            $display['progress'] = $count > 0 ? $count . ' filas leídas' : 'Conectando con SQL Server';
            $display['detail'] = 'El total de filas se conoce al terminar la consulta.';
        } elseif ($phase === 'sync') {
            $display['stage'] = 'Sincronizando cambios en Shopify';
            $display['progress'] = (int) ($summary['confirmed_count'] ?? 0) . ' precios confirmados';
            $display['detail'] = (int) ($summary['sent_count'] ?? 0) . ' precios enviados · ' . (int) ($summary['failed_count'] ?? 0) . ' precios con error · ' . $count . ' de ' . $expected . ' solicitudes procesadas';
        } elseif ($phase === 'variants' && $status === 'waiting_shopify') {
            $display['progress'] = $count > 0 ? $count . ' objetos examinados por Shopify' : 'Shopify está buscando variantes';
            $display['detail'] = 'La búsqueda se comprueba periódicamente.';
            if ($job['action'] === 'sync') {
                $display['stage'] = 'Buscando variantes para referencias sin asociación';
                $display['detail'] = (int) ($summary['lookup']['requested'] ?? $expected) . ' referencias pendientes de asociación. El contador de objetos de Shopify no equivale a referencias.';
            }
            if (($summary['phase_label'] ?? '') === 'Descargando resultados de Shopify') {
                $display['progress'] = 'Descargando resultados de Shopify';
            }
        } elseif ($phase === 'variants_results') {
            $display['progress'] = $count . ' variantes descargadas y revisadas';
        } elseif ($phase === 'variants_match') {
            $display['progress'] = $count . ' / ' . $expected . ' referencias relacionadas por SKU';
        } elseif ($phase === 'prices' && $status === 'waiting_shopify') {
            $display['progress'] = $count > 0 ? $count . ' objetos procesados por Shopify' : 'Shopify está aplicando los precios';
            $display['detail'] = $expected . ' bloques enviados. El contador de objetos de Shopify no equivale a bloques.';
            if (($summary['phase_label'] ?? '') === 'Descargando resultados de Shopify') {
                $display['progress'] = 'Descargando resultados de Shopify';
            }
        } elseif ($phase === 'prices_prepare') {
            $display['progress'] = $count . ' / ' . $expected . ' bloques preparados';
        } elseif ($phase === 'prices_upload') {
            $display['progress'] = $count . ' bloques preparados; enviando a Shopify';
        } elseif ($phase === 'prices_results') {
            $display['progress'] = $count . ' / ' . $expected . ' respuestas revisadas';
        } else {
            $display['progress'] = $display['stage'];
        }
        return $display;
    }
}
