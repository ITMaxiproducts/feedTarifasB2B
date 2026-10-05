<?php

declare(strict_types=1);

final class InitialLoad
{
    public function __construct(private Config $config, private StateStore $store, private ShopifyClient $shopify)
    {
    }

    public function start(int $jobId): void
    {
        $this->config->requireShopify();
        $this->store->setProgress($jobId, 'running', 'source', null, 0, 0, [
            'phase_label' => 'Leyendo artículos de SQL Server',
            'article_count' => 0,
            'tariff_count' => count($this->config->tariffColumns),
        ]);
        $summary = $this->prepareSource($jobId);
        if ($summary['eligible_articles'] === 0) {
            $this->store->addError($jobId, null, null, 'no_eligible_articles', 'No quedaron referencias aptas para la carga inicial.');
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }

        $summary['phase_label'] = 'Comprobando catálogos Shopify';
        $this->store->setProgress($jobId, 'running', 'catalogs', null, 0, 0, $summary);
        $catalogErrorsBefore = $this->store->errorCount($jobId);
        $this->resolveCatalogs($jobId, false);
        if ($this->store->errorCount($jobId) > $catalogErrorsBefore) {
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }

        $summary['phase_label'] = 'Iniciando búsqueda de variantes en Shopify';
        $this->store->setProgress($jobId, 'running', 'variants_start', null, 0, 0, $summary);
        $operationId = (new VariantResolver($this->shopify))->start();
        $summary['phase_label'] = 'Resolviendo SKU en Shopify';
        $this->store->setProgress($jobId, 'waiting_shopify', 'variants', $operationId, 0, $summary['eligible_articles'], $summary);
    }

    public function prepareSource(int $jobId): array
    {
        $summary = (new SourceReader($this->config))->scan(
            function (int $row, ?string $article, string $code, string $message) use ($jobId): void {
                $this->store->addError($jobId, $row, $article, $code, $message);
                if ($code === 'duplicate_article_id' && $article !== null) {
                    $this->store->discardArticlePrices($jobId, $article);
                }
            },
            function (int $row, array $values) use ($jobId): void {
                $article = trim((string) $values['IdArtículo']);
                $prices = [];
                foreach ($this->config->tariffColumns as $tariff) {
                    $rawAmount = (string) $values[$tariff];
                    $decimals = (int) $this->config->decimalPlaces[$tariff];
                    $amount = self::normalizeAmount($rawAmount, $decimals);
                    if ($amount === null) {
                        $this->store->addError($jobId, $row, $article, 'price_precision', 'El importe de la tarifa ' . $tariff . ' no se puede representar con ' . $decimals . ' decimales. Valor recibido: ' . $rawAmount . '.');
                        continue;
                    }
                    $prices[$tariff] = $amount;
                }
                if (count($prices) === count($this->config->tariffColumns)) {
                    foreach ($prices as $tariff => $amount) {
                        $this->store->addJobPrice($jobId, $row, $article, (string) $tariff, $amount, $this->config->currencies[$tariff]);
                    }
                }
            },
            function (int $rows, int $validRows, int $invalidRows) use ($jobId): void {
                $this->store->setProgress($jobId, 'running', 'source', null, $rows, 0, [
                    'phase_label' => 'Leyendo artículos de SQL Server',
                    'article_count' => $rows,
                    'tariff_count' => count($this->config->tariffColumns),
                    'valid_rows' => $validRows,
                    'invalid_rows' => $invalidRows,
                    'error_count' => $this->store->errorCount($jobId),
                ]);
            },
        );
        $summary['per_tariff'] = array_fill_keys($this->config->tariffColumns, ['prepared' => 0, 'confirmed' => 0]);
        foreach ($this->store->expectedCounts($jobId) as $count) {
            $summary['per_tariff'][$count['tariff']]['prepared'] = (int) $count['expected'];
        }
        $summary['eligible_articles'] = count($this->store->articles($jobId));
        $summary['error_count'] = $this->store->errorCount($jobId);
        return $summary;
    }

    public function poll(array $job): void
    {
        $jobId = (int) $job['id'];
        $result = $this->shopify->graphql(<<<'GQL'
            query BulkStatus($id: ID!) {
              bulkOperation(id: $id) { id status errorCode objectCount url partialDataUrl }
            }
            GQL, ['id' => $job['operation_id']]);
        $operation = $result['bulkOperation'] ?? null;
        if ($operation === null || $operation['id'] !== $job['operation_id']) {
            throw new RuntimeException('No se encontró la operación Shopify ' . $job['operation_id'] . '.');
        }
        $summary = $job['summary'] ? json_decode($job['summary'], true, flags: JSON_THROW_ON_ERROR) : [];
        $summary['shopify_status'] = (string) $operation['status'];
        if (in_array($operation['status'], ['CREATED', 'RUNNING', 'CANCELING'], true)) {
            $this->store->setProgress($jobId, 'waiting_shopify', $job['phase'], $job['operation_id'], (int) $operation['objectCount'], (int) $job['expected_count'], $summary);
            return;
        }
        $failed = $operation['status'] !== 'COMPLETED';
        $url = $failed ? ($operation['partialDataUrl'] ?? null) : ($operation['url'] ?? null);
        if ($url === null) {
            $this->store->addError($jobId, null, null, 'bulk_failed', 'Operación Shopify ' . $operation['status'] . ': ' . ($operation['errorCode'] ?? 'sin resultado JSONL') . '.');
            $summary['error_count'] = $this->store->errorCount($jobId);
            $summary['phase_label'] = 'Operación Shopify fallida';
            $this->store->finish($jobId, 'failed', (int) $job['article_count'], (int) $job['tariff_count'], $summary);
            return;
        }
        $summary['phase_label'] = 'Descargando resultados de Shopify';
        $this->store->setProgress($jobId, 'waiting_shopify', $job['phase'], $job['operation_id'], (int) $operation['objectCount'], (int) $job['expected_count'], $summary);
        $path = $this->jobPath($jobId, $job['phase'] . '-result.jsonl');
        $this->shopify->download($url, $path);
        if ($job['phase'] === 'variants') {
            if ($failed) {
                $this->store->addError($jobId, null, null, 'variant_bulk_failed', 'La búsqueda masiva de variantes terminó con ' . $operation['status'] . '.');
                $summary['error_count'] = $this->store->errorCount($jobId);
                $this->store->finish($jobId, 'failed', (int) $job['article_count'], (int) $job['tariff_count'], $summary);
                return;
            }
            $this->finishVariants($jobId, $path, $summary, (int) $operation['objectCount'], (string) $job['operation_id']);
        } elseif ($job['phase'] === 'prices') {
            $this->finishPrices($jobId, $path, $summary, $failed, (string) ($operation['errorCode'] ?? $operation['status']), (int) $job['expected_count'], (string) $job['operation_id']);
        } else {
            throw new RuntimeException('Fase de carga desconocida.');
        }
    }

    private function finishVariants(int $jobId, string $path, array $summary, int $expectedObjects, string $operationId): void
    {
        $needed = array_fill_keys($this->store->articles($jobId), true);
        $summary['phase_label'] = 'Revisando variantes de Shopify';
        $this->store->setProgress($jobId, 'running', 'variants_results', $operationId, 0, $expectedObjects, $summary);
        $outcomes = (new VariantResolver($this->shopify))->matchFile($path, array_keys($needed), function (int $examined) use ($jobId, $operationId, $expectedObjects, $summary): void {
            $this->store->setProgress($jobId, 'running', 'variants_results', $operationId, $examined, $expectedObjects, $summary);
        });
        $summary['phase_label'] = 'Relacionando referencias por SKU';
        $this->store->setProgress($jobId, 'running', 'variants_match', $operationId, 0, count($needed), $summary);
        $matched = 0;
        foreach ($needed as $article => $_) {
            $outcome = $outcomes[$article];
            if ($outcome['status'] !== 'unique') {
                $code = $outcome['status'] === 'missing' ? 'missing_sku' : 'ambiguous_sku';
                $this->store->addError($jobId, null, (string) $article, $code, $outcome['status'] === 'missing' ? 'No hay una variante Shopify con ese SKU.' : 'Varias variantes Shopify tienen ese SKU.');
                $this->store->discardArticlePrices($jobId, (string) $article);
            } else {
                $this->store->saveVariant((string) $article, $outcome['variant_id']);
            }
            $matched++;
            if ($matched % 250 === 0) {
                $summary['error_count'] = $this->store->errorCount($jobId);
                $this->store->setProgress($jobId, 'running', 'variants_match', $operationId, $matched, count($needed), $summary);
            }
        }
        $summary['error_count'] = $this->store->errorCount($jobId);
        $this->store->setProgress($jobId, 'running', 'variants_match', $operationId, $matched, count($needed), $summary);
        $summary['eligible_articles'] = count($this->store->articles($jobId));
        if ($summary['eligible_articles'] === 0) {
            $this->store->addError($jobId, null, null, 'no_mapped_articles', 'No quedó ninguna referencia con una variante Shopify única.');
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }
        $this->store->attachVariants($jobId);
        $catalogErrorsBefore = $this->store->errorCount($jobId);
        $catalogs = $this->resolveCatalogs($jobId, true);
        if ($this->store->errorCount($jobId) > $catalogErrorsBefore) {
            $summary['error_count'] = $this->store->errorCount($jobId);
            $this->store->finish($jobId, 'failed', $summary['article_count'], $summary['tariff_count'], $summary);
            return;
        }
        $this->startPrices($jobId, $catalogs, $summary);
    }

    private function resolveCatalogs(int $jobId, bool $createPriceLists): array
    {
        $catalogErrorsBefore = $this->store->errorCount($jobId);
        $matches = array_fill_keys($this->config->tariffColumns, []);
        $after = null;
        do {
            $data = $this->shopify->graphql(<<<'GQL'
                query Catalogs($after: String) {
                  catalogs(first: 250, after: $after) {
                    nodes { id title priceList { id currency } }
                    pageInfo { hasNextPage endCursor }
                  }
                }
                GQL, ['after' => $after]);
            $connection = $data['catalogs'];
            foreach ($connection['nodes'] as $catalog) {
                if (array_key_exists($catalog['title'], $matches)) {
                    $matches[$catalog['title']][] = $catalog;
                }
            }
            $after = $connection['pageInfo']['hasNextPage'] ? $connection['pageInfo']['endCursor'] : null;
        } while ($after !== null);

        $resolved = [];
        foreach ($matches as $tariff => $catalogs) {
            if (count($catalogs) !== 1) {
                $this->store->addError($jobId, null, null, 'catalog_match', 'La tarifa ' . $tariff . ' requiere exactamente un catálogo Shopify con ese título; encontrados: ' . count($catalogs) . '.');
                continue;
            }
            $catalog = $catalogs[0];
            $priceList = $catalog['priceList'];
            if ($priceList !== null && $priceList['currency'] !== $this->config->currencies[$tariff]) {
                $this->store->addError($jobId, null, null, 'currency_mismatch', 'La moneda del catálogo ' . $tariff . ' no coincide con .env.');
                continue;
            }
            $resolved[$tariff] = ['catalog' => $catalog['id'], 'price_list' => $priceList['id'] ?? '', 'currency' => $this->config->currencies[$tariff]];
        }
        foreach ($resolved as $tariff => $entry) {
            $this->store->saveCatalog((string) $tariff, $entry['catalog'], $entry['price_list'], $entry['currency']);
        }
        if ($this->store->errorCount($jobId) > $catalogErrorsBefore) {
            return $resolved;
        }
        foreach ($resolved as $tariff => &$entry) {
            if ($entry['price_list'] === '' && $createPriceLists) {
                $result = $this->shopify->graphql(<<<'GQL'
                    mutation CreatePriceList($input: PriceListCreateInput!) {
                      priceListCreate(input: $input) {
                        priceList { id currency }
                        userErrors { field message }
                      }
                    }
                    GQL, ['input' => [
                        'name' => 'Tarifa ' . $tariff,
                        'currency' => $entry['currency'],
                        'catalogId' => $entry['catalog'],
                        'parent' => ['adjustment' => ['type' => 'PERCENTAGE_DECREASE', 'value' => 0]],
                    ]]);
                ShopifyClient::assertNoUserErrors($result['priceListCreate']);
                $entry['price_list'] = $result['priceListCreate']['priceList']['id'] ?? '';
                if ($entry['price_list'] === '') {
                    throw new RuntimeException('Shopify no devolvió la lista de precios de ' . $tariff . '.');
                }
            }
            if ($createPriceLists) {
                $this->store->saveCatalog((string) $tariff, $entry['catalog'], $entry['price_list'], $entry['currency']);
            }
        }
        unset($entry);
        return $resolved;
    }

    private function startPrices(int $jobId, array $catalogs, array $summary): void
    {
        $expectedLines = 0;
        $summary['per_tariff'] = array_fill_keys($this->config->tariffColumns, ['prepared' => 0, 'confirmed' => 0]);
        foreach ($this->store->expectedCounts($jobId) as $count) {
            $expectedLines += (int) ceil((int) $count['expected'] / 250);
            $summary['per_tariff'][$count['tariff']]['prepared'] = (int) $count['expected'];
        }
        $summary['error_count'] = $this->store->errorCount($jobId);
        $summary['phase_label'] = 'Preparando bloques de precios';
        $this->store->setProgress($jobId, 'running', 'prices_prepare', null, 0, $expectedLines, $summary);
        $path = $this->jobPath($jobId, 'prices-input.jsonl');
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('No se puede escribir el JSONL privado de precios.');
        }
        $lineNumber = 0;
        $counts = [];
        try {
            foreach ($this->config->tariffColumns as $tariff) {
                $prices = $this->store->pricesForTariff($jobId, $tariff);
                $counts[$tariff] = ['prepared' => count($prices), 'confirmed' => 0];
                foreach (array_chunk($prices, 250) as $chunk) {
                    $inputs = [];
                    $articles = [];
                    foreach ($chunk as $price) {
                        $inputs[] = ['variantId' => $price['variant_id'], 'price' => ['amount' => $price['amount'], 'currencyCode' => $price['currency']]];
                        $articles[$price['variant_id']] = $price['article_id'];
                    }
                    $json = json_encode(['priceListId' => $catalogs[$tariff]['price_list'], 'prices' => $inputs], JSON_THROW_ON_ERROR);
                    if (fwrite($stream, $json . "\n") === false) {
                        throw new RuntimeException('No se pudo completar el archivo JSONL.');
                    }
                    $this->store->saveBulkLine($jobId, $lineNumber++, $tariff, $articles);
                }
                $this->store->setProgress($jobId, 'running', 'prices_prepare', null, $lineNumber, $expectedLines, $summary);
            }
        } finally {
            fclose($stream);
        }
        $summary['phase_label'] = 'Subiendo bloques de precios a Shopify';
        $this->store->setProgress($jobId, 'running', 'prices_upload', null, $lineNumber, $expectedLines, $summary);
        $stagePath = $this->shopify->uploadJsonl($path);
        $mutation = <<<'GQL'
            mutation AddPrices($priceListId: ID!, $prices: [PriceListPriceInput!]!) {
              priceListFixedPricesAdd(priceListId: $priceListId, prices: $prices) {
                prices { variant { id } }
                userErrors { field message }
              }
            }
            GQL;
        $result = $this->shopify->graphql(<<<'GQL'
            mutation RunPriceBulk($mutation: String!, $path: String!, $client: String) {
              bulkOperationRunMutation(mutation: $mutation, stagedUploadPath: $path, clientIdentifier: $client) {
                bulkOperation { id status }
                userErrors { field message }
              }
            }
            GQL, ['mutation' => $mutation, 'path' => $stagePath, 'client' => 'feedTarifas-' . $jobId]);
        $payload = $result['bulkOperationRunMutation'];
        ShopifyClient::assertNoUserErrors($payload);
        $operationId = $payload['bulkOperation']['id'] ?? null;
        if ($operationId === null) {
            throw new RuntimeException('Shopify no inició la carga de precios.');
        }
        $summary['per_tariff'] = $counts;
        $summary['phase_label'] = 'Aplicando precios en Shopify';
        $summary['shopify_status'] = 'CREATED';
        $this->store->setProgress($jobId, 'waiting_shopify', 'prices', $operationId, 0, $lineNumber, $summary);
    }

    private function finishPrices(int $jobId, string $path, array $summary, bool $failed, string $reason, int $expectedLines, string $operationId): void
    {
        $summary['phase_label'] = 'Revisando respuestas de Shopify';
        $this->store->setProgress($jobId, 'running', 'prices_results', $operationId, 0, $expectedLines, $summary);
        $seenLines = [];
        $reviewedResults = 0;
        foreach ($this->lines($path) as $result) {
            $reviewedResults++;
            if ($reviewedResults % 10 === 0) {
                $this->store->setProgress($jobId, 'running', 'prices_results', $operationId, $reviewedResults, $expectedLines, $summary);
            }
            $lineNumber = $result['__lineNumber'] ?? null;
            $line = is_int($lineNumber) ? $this->store->bulkLine($jobId, $lineNumber) : null;
            if ($line === null) {
                $this->store->addError($jobId, null, null, 'bulk_result_line', 'Shopify devolvió una línea sin referencia válida.');
                continue;
            }
            $seenLines[$lineNumber] = true;
            $payload = $result['data']['priceListFixedPricesAdd'] ?? null;
            if ($payload === null) {
                $this->store->addError($jobId, $lineNumber + 1, null, 'bulk_line_failed', json_encode($result['errors'] ?? $result, JSON_UNESCAPED_UNICODE));
                continue;
            }
            foreach ($payload['userErrors'] ?? [] as $error) {
                $this->store->addError($jobId, $lineNumber + 1, null, 'price_rejected', $error['message'] ?? 'Error Shopify sin detalle.');
            }
            $confirmed = [];
            foreach ($payload['prices'] ?? [] as $price) {
                $variantId = $price['variant']['id'] ?? '';
                if (isset($line['article_ids'][$variantId])) {
                    $this->store->confirmPrice($jobId, $line['article_ids'][$variantId], $line['tariff']);
                    $confirmed[$variantId] = true;
                }
            }
            foreach ($line['article_ids'] as $variantId => $article) {
                if (!isset($confirmed[$variantId])) {
                    $this->store->addError($jobId, $lineNumber + 1, $article, 'price_unconfirmed', 'Shopify no confirmó este precio.');
                }
            }
        }
        foreach ($this->store->bulkLineNumbers($jobId) as $lineNumber) {
            if (!isset($seenLines[$lineNumber])) {
                $this->store->addError($jobId, (int) $lineNumber + 1, null, 'missing_bulk_result', 'Shopify no devolvió un resultado para esta línea JSONL.');
            }
        }
        if ($failed) {
            $this->store->addError($jobId, null, null, 'bulk_failed', 'La operación masiva terminó con ' . $reason . '.');
        }
        $summary['per_tariff'] = array_fill_keys($this->config->tariffColumns, ['prepared' => 0, 'confirmed' => 0]);
        foreach ($this->store->expectedCounts($jobId) as $row) {
            $summary['per_tariff'][$row['tariff']]['prepared'] = (int) $row['expected'];
        }
        foreach ($this->store->confirmedCounts($jobId) as $row) {
            $summary['per_tariff'][$row['tariff']]['confirmed'] = (int) $row['confirmed'];
        }
        $summary['error_count'] = $this->store->errorCount($jobId);
        $summary['result_lines'] = count($seenLines);
        $summary['expected_lines'] = $expectedLines;
        $summary['phase_label'] = 'Resultado de la carga de precios';
        $this->store->setProgress($jobId, 'running', 'prices_results', $operationId, count($seenLines), $expectedLines, $summary);
        $status = $summary['error_count'] === 0 ? 'completed' : 'partial';
        $this->store->finish($jobId, $status, $summary['article_count'], $summary['tariff_count'], $summary);
    }

    public static function normalizeAmount(string $value, int $decimals): ?string
    {
        $value = trim($value);
        if (!preg_match('/^(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/', $value)) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $decimals) {
            return null;
        }
        $whole = ltrim($whole, '0');
        return ($whole === '' ? '0' : $whole) . ($decimals > 0 ? '.' . str_pad($fraction, $decimals, '0') : '');
    }

    private function jobPath(int $jobId, string $name): string
    {
        $directory = $this->config->storagePath . '/initial-' . $jobId;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el almacenamiento privado de la carga.');
        }
        return $directory . '/' . $name;
    }

    private function lines(string $path): Generator
    {
        $file = new SplFileObject($path, 'r');
        while (!$file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line !== '') {
                yield json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            }
        }
    }
}
