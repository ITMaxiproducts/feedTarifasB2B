<?php

declare(strict_types=1);

final class SyncPrices
{
    public function __construct(private Config $config, private StateStore $store, private ShopifyClient $shopify)
    {
    }

    public function start(int $jobId, array $job): void
    {
        $this->config->requireShopify();
        $summary = json_decode((string) ($job['summary'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR);
        $summary['phase_label'] = 'Leyendo artículos de SQL Server';
        $this->store->setProgress($jobId, 'running', 'source', null, 0, 0, $summary);

        $summary = (new InitialLoad($this->config, $this->store, $this->shopify))->prepareSource($jobId);
        $summary['origin'] = json_decode((string) ($job['summary'] ?? '{}'), true, flags: JSON_THROW_ON_ERROR)['origin'] ?? 'manual';
        if ($summary['eligible_articles'] === 0) {
            $this->store->addError($jobId, null, null, 'no_eligible_articles', 'No quedaron referencias aptas para sincronizar.');
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }

        $catalogs = [];
        foreach ($this->store->catalogs() as $catalog) {
            $catalogs[$catalog['tariff']] = $catalog;
        }
        $catalogErrorsBefore = $this->store->errorCount($jobId);
        foreach ($this->config->tariffColumns as $tariff) {
            if (!isset($catalogs[$tariff]) || $catalogs[$tariff]['price_list_id'] === '') {
                $this->store->addError($jobId, null, null, 'price_list_missing', 'No hay una lista de precios resuelta para la tarifa ' . $tariff . '. Ejecuta primero la carga inicial.');
                continue;
            }
            if ($catalogs[$tariff]['currency'] !== $this->config->currencies[$tariff]) {
                $this->store->addError($jobId, null, null, 'price_list_currency', 'La moneda guardada para la tarifa ' . $tariff . ' no coincide con .env.');
            }
        }
        if ($this->store->errorCount($jobId) > $catalogErrorsBefore) {
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }

        $this->store->attachVariants($jobId);
        $counts = array_fill_keys($this->config->tariffColumns, ['changed' => 0, 'sent' => 0, 'confirmed' => 0, 'failed' => 0]);
        $changed = [];
        foreach ($this->store->syncPrices($jobId) as $price) {
            $tariff = $price['tariff'];
            if ($price['synced_amount'] !== null && $price['synced_amount'] === $price['amount'] && $price['synced_currency'] === $price['currency']) {
                continue;
            }
            $counts[$tariff]['changed']++;
            if ($price['variant_id'] === null) {
                $counts[$tariff]['failed']++;
                $this->store->addError($jobId, (int) $price['row_number'], $price['article_id'], 'variant_unmapped', 'No existe un Shopify ProductVariant asociado a esta referencia; se reintentará cuando se resuelva.');
                continue;
            }
            $changed[$tariff][] = $price;
        }

        $expectedRequests = 0;
        foreach ($changed as $prices) {
            $expectedRequests += (int) ceil(count($prices) / 250);
        }
        $summary['per_tariff'] = $counts;
        $summary['phase_label'] = 'Enviando cambios de precios a Shopify';
        $this->store->setProgress($jobId, 'running', 'sync', null, 0, $expectedRequests, $summary);

        $requestNumber = 0;
        foreach ($this->config->tariffColumns as $tariff) {
            foreach (array_chunk($changed[$tariff] ?? [], 250) as $chunk) {
                $inputs = [];
                $byVariant = [];
                foreach ($chunk as $price) {
                    $inputs[] = ['variantId' => $price['variant_id'], 'price' => ['amount' => $price['amount'], 'currencyCode' => $price['currency']]];
                    $byVariant[$price['variant_id']] = $price;
                }
                $counts[$tariff]['sent'] += count($chunk);
                try {
                    $data = $this->shopify->graphql(<<<'GQL'
                        mutation SyncPrices($priceListId: ID!, $prices: [PriceListPriceInput!]!) {
                          priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {
                            prices { variant { id } }
                            userErrors { field message }
                          }
                        }
                        GQL, ['priceListId' => $catalogs[$tariff]['price_list_id'], 'prices' => $inputs], true);
                    $payload = $data['priceListFixedPricesAdd'] ?? [];
                    $returned = [];
                    foreach ($payload['prices'] ?? [] as $result) {
                        $variantId = $result['variant']['id'] ?? '';
                        if (isset($byVariant[$variantId])) {
                            $returned[$variantId] = true;
                            $this->store->confirmPrice($jobId, $byVariant[$variantId]['article_id'], $tariff);
                            $counts[$tariff]['confirmed']++;
                        }
                    }
                    $userErrors = array_values(array_filter(array_map(static fn (array $error): string => (string) ($error['message'] ?? ''), $payload['userErrors'] ?? [])));
                    foreach ($byVariant as $variantId => $price) {
                        if (isset($returned[$variantId])) {
                            continue;
                        }
                        $counts[$tariff]['failed']++;
                        $message = $userErrors === [] ? 'Shopify no confirmó este precio.' : implode('; ', $userErrors);
                        $this->store->addError($jobId, (int) $price['row_number'], $price['article_id'], 'price_sync_failed', $message);
                    }
                } catch (Throwable $error) {
                    foreach ($chunk as $price) {
                        $counts[$tariff]['failed']++;
                        $this->store->addError($jobId, (int) $price['row_number'], $price['article_id'], 'price_sync_request_failed', $error->getMessage());
                    }
                }
                $requestNumber++;
                $summary['per_tariff'] = $counts;
                $summary['changed_count'] = array_sum(array_column($counts, 'changed'));
                $summary['sent_count'] = array_sum(array_column($counts, 'sent'));
                $summary['confirmed_count'] = array_sum(array_column($counts, 'confirmed'));
                $summary['failed_count'] = array_sum(array_column($counts, 'failed'));
                $summary['error_count'] = $this->store->errorCount($jobId);
                $this->store->setProgress($jobId, 'running', 'sync', null, $requestNumber, $expectedRequests, $summary);
            }
        }

        $summary['per_tariff'] = $counts;
        $summary['changed_count'] = array_sum(array_column($counts, 'changed'));
        $summary['sent_count'] = array_sum(array_column($counts, 'sent'));
        $summary['confirmed_count'] = array_sum(array_column($counts, 'confirmed'));
        $summary['failed_count'] = array_sum(array_column($counts, 'failed'));
        $summary['error_count'] = $this->store->errorCount($jobId);
        $summary['phase_label'] = 'Resultado de la sincronización';
        $status = $summary['error_count'] === 0 ? 'completed' : 'partial';
        $this->store->finish($jobId, $status, $summary['article_count'], $summary['tariff_count'], $summary);
    }
}
