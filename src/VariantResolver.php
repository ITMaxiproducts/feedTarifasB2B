<?php

declare(strict_types=1);

final class VariantResolver
{
    public function __construct(private ShopifyClient $shopify)
    {
    }

    public function start(): string
    {
        $result = $this->shopify->graphql(<<<'GQL'
            mutation MapVariants($query: String!) {
              bulkOperationRunQuery(query: $query) {
                bulkOperation { id status }
                userErrors { field message }
              }
            }
            GQL, ['query' => 'query { productVariants { edges { node { id sku } } } }']);
        $payload = $result['bulkOperationRunQuery'];
        ShopifyClient::assertNoUserErrors($payload);
        $id = $payload['bulkOperation']['id'] ?? '';
        if ($id === '') {
            throw new RuntimeException('Shopify no inició la búsqueda masiva de variantes.');
        }
        return $id;
    }

    public function poll(string $operationId, string $resultPath, array $articleIds): array
    {
        try {
            return $this->pollResult($operationId, $resultPath, $articleIds);
        } catch (Throwable $error) {
            if ($error instanceof ShopifyRequestException && !$error->transient) {
                return ['status' => 'failed', 'shopify_status' => 'REQUEST_FAILED', 'object_count' => 0,
                    'error' => $error->getMessage(), 'outcomes' => []];
            }
            // No matching conclusions may be drawn from an unavailable status,
            // incomplete download or malformed JSONL. Re-poll the saved ID.
            return ['status' => 'temporary_failure', 'error' => $error->getMessage(), 'outcomes' => []];
        }
    }

    private function pollResult(string $operationId, string $resultPath, array $articleIds): array
    {
        $data = $this->shopify->graphql(<<<'GQL'
            query BulkStatus($id: ID!) {
              bulkOperation(id: $id) { id status errorCode objectCount url partialDataUrl }
            }
            GQL, ['id' => $operationId]);
        $operation = $data['bulkOperation'] ?? null;
        if ($operation === null || $operation['id'] !== $operationId) {
            return ['status' => 'failed', 'shopify_status' => 'NOT_FOUND', 'object_count' => 0,
                'error' => 'No se encontró la operación Shopify ' . $operationId . '.', 'outcomes' => []];
        }
        $result = ['status' => 'pending', 'shopify_status' => $operation['status'],
            'object_count' => (int) $operation['objectCount'], 'outcomes' => []];
        if (in_array($operation['status'], ['CREATED', 'RUNNING', 'CANCELING'], true)) {
            return $result;
        }
        if ($operation['status'] !== 'COMPLETED') {
            $result['status'] = 'failed';
            $result['error'] = 'La búsqueda masiva terminó con ' . $operation['status'] . ': ' . ($operation['errorCode'] ?? 'sin detalle') . '.';
            return $result;
        }
        // A completed empty catalog can have no JSONL URL. Failed partial results
        // are never used to infer missing or unique SKUs.
        if (($operation['url'] ?? null) === null) {
            if ($result['object_count'] !== 0) {
                throw new RuntimeException('Shopify completó la búsqueda sin devolver el JSONL.');
            }
            if (file_put_contents($resultPath, '') === false) {
                throw new RuntimeException('No se pudo guardar el resultado vacío de Shopify.');
            }
        } else {
            $this->shopify->download($operation['url'], $resultPath);
        }
        $result['status'] = 'completed';
        $result['outcomes'] = $this->matchFile($resultPath, $articleIds);
        return $result;
    }

    public function matchFile(string $path, array $articleIds, ?callable $onProgress = null): array
    {
        $needed = array_fill_keys($articleIds, true);
        $matches = [];
        $file = new SplFileObject($path, 'r');
        $examined = 0;
        while (!$file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }
            $variant = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($variant) || !array_key_exists('sku', $variant) || empty($variant['id'])) {
                throw new RuntimeException('Shopify devolvió una fila de variantes incompleta.');
            }
            $examined++;
            if ($onProgress !== null && $examined % 1000 === 0) {
                $onProgress($examined);
            }
            $sku = (string) ($variant['sku'] ?? '');
            if (isset($needed[$sku])) {
                $id = (string) ($variant['id'] ?? '');
                if ($id === '') {
                    throw new RuntimeException('Shopify devolvió una variante sin ID para ' . $sku . '.');
                }
                $matches[$sku][$id] = true;
            }
        }
        $outcomes = [];
        foreach ($articleIds as $article) {
            $ids = array_keys($matches[$article] ?? []);
            $outcomes[$article] = ['status' => match (count($ids)) {
                0 => 'missing', 1 => 'unique', default => 'ambiguous',
            }, 'variant_id' => count($ids) === 1 ? $ids[0] : null];
        }
        return $outcomes;
    }
}
