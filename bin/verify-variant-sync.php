<?php

declare(strict_types=1);

// Invoked by verify.php. All Shopify calls are intercepted; no network is used.
final class OfflineVariantShopify extends ShopifyClient
{
    public string $status = 'RUNNING';
    public string $jsonl = '';
    public int $lookups = 0;
    public array $sent = [];
    public bool $rejectStart = false;
    public array $catalogs = [];
    public int $uploads = 0;

    public function __construct()
    {
    }

    public function graphql(string $query, array $variables = [], bool $retryTransient = false): array
    {
        if (str_contains($query, 'query Catalogs')) {
            return ['catalogs' => ['nodes' => $this->catalogs, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];
        }
        if (str_contains($query, 'mutation RunPriceBulk')) {
            return ['bulkOperationRunMutation' => ['bulkOperation' => ['id' => 'prices-1', 'status' => 'CREATED'], 'userErrors' => []]];
        }
        if (str_contains($query, 'mutation MapVariants')) {
            $this->lookups++;
            if ($this->rejectStart) {
                throw new RuntimeException('Controlled lookup start failure');
            }
            return ['bulkOperationRunQuery' => ['bulkOperation' => ['id' => 'lookup-1', 'status' => 'CREATED'], 'userErrors' => []]];
        }
        if (str_contains($query, 'query BulkStatus')) {
            return ['bulkOperation' => ['id' => $variables['id'], 'status' => $this->status, 'objectCount' => 5,
                'url' => 'offline://variants', 'partialDataUrl' => 'offline://partial', 'errorCode' => 'CONTROLLED']];
        }
        if (str_contains($query, 'mutation SyncPrices')) {
            $this->sent[] = $variables;
            return ['priceListFixedPricesAdd' => ['prices' => array_map(static fn (array $price): array => ['variant' => ['id' => $price['variantId']]], $variables['prices']), 'userErrors' => []]];
        }
        throw new RuntimeException('Unexpected offline GraphQL call');
    }

    public function download(string $url, string $path): void
    {
        file_put_contents($path, $this->jsonl);
    }

    public function uploadJsonl(string $path): string
    {
        $this->uploads++;
        if (!is_file($path)) {
            throw new RuntimeException('Missing offline bulk price file');
        }
        return 'offline-prices';
    }
}

function verifyVariantSync(): void
{
    $path = sys_get_temp_dir() . '/feedTarifas-variants-' . bin2hex(random_bytes(6));
    mkdir($path, 0700);
    $check = static function (bool $valid, string $message): void {
        if (!$valid) {
            throw new RuntimeException($message);
        }
    };
    try {
        $tariffs = ['12060', '11961', '112463', '161', '187', '188', '192', '193', '194', '195'];
        $columns = ["'MAPPED' AS \"IdArtículo\"", "'Marca' AS \"Marca\""];
        foreach ($tariffs as $tariff) {
            $columns[] = "'1.00' AS \"{$tariff}\"";
        }
        $sql = 'SELECT ' . implode(', ', $columns);
        foreach (['NEW', 'MISSING', 'AMBIGUOUS'] as $article) {
            $sql .= " UNION ALL SELECT '{$article}', 'Marca', " . implode(', ', array_fill(0, 10, "'1.00'"));
        }
        file_put_contents($path . '/source.sql', $sql);
        $config = new Config('sqlite::memory:', '', '', $tariffs, $path . '/state.sqlite', $path . '/source.sql', $path,
            'example.myshopify.com', 'offline', array_fill_keys($tariffs, 'EUR'), array_fill_keys($tariffs, '2'));
        $store = new StateStore($config->sqlitePath);
        foreach ($tariffs as $tariff) {
            $store->saveCatalog($tariff, 'catalog-' . $tariff, 'list-' . $tariff, 'EUR');
        }
        $store->saveVariant('MAPPED', 'variant-mapped');
        $shopify = new OfflineVariantShopify();
        $shopify->jsonl = implode("\n", array_map(static fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR), [
            ['id' => 'variant-new', 'sku' => 'NEW'],
            ['id' => 'variant-new', 'sku' => 'NEW'], // repeated ID remains unique
            ['id' => 'variant-a', 'sku' => 'AMBIGUOUS'],
            ['id' => 'variant-b', 'sku' => 'AMBIGUOUS'],
            ['id' => 'variant-space', 'sku' => ' MISSING '], // exact match only
        ]));
        $sync = new SyncPrices($config, $store, $shopify);
        $id = $store->queueSync();
        $sync->start($id, $store->claimNext());
        $waiting = $store->waitingShopifyJob();
        $check($waiting !== null && (int) $waiting['id'] === $id && $shopify->lookups === 1 && $shopify->sent === [], 'Sync did not persist asynchronous lookup');
        $check($store->unmappedArticles($id) === ['AMBIGUOUS', 'MISSING', 'NEW'], 'Unmapped references are not distinct');
        $store->queuePreview();
        $check($store->claimNext() === null, 'Waiting sync did not block queued work');
        $sync->poll($waiting);
        $check($store->waitingShopifyJob() !== null && $shopify->sent === [], 'Pending lookup sent prices prematurely');
        $display = ProgressPresenter::describe($store->jobStatus($id));
        $check($display['stage'] === 'Buscando variantes para referencias sin asociación' && str_contains($display['detail'], '3 referencias'), 'Lookup progress mixes units');
        // Source changes after preparation must not replace this job's snapshot.
        file_put_contents($path . '/source.sql', 'INVALID SQL');
        $shopify->status = 'COMPLETED';
        $sync->poll($store->waitingShopifyJob());
        $job = $store->jobStatus($id);
        $check($job['status'] === 'partial' && $job['summary']['confirmed_count'] === 20 && $job['summary']['failed_count'] === 0 && $job['summary']['skipped_count'] === 20, 'New mappings were not confirmed or omissions became failures');
        $check($job['summary']['lookup'] === ['requested' => 3, 'unique' => 1, 'missing' => 1, 'ambiguous' => 1, 'inconclusive' => 0], 'Lookup reference outcomes are incorrect');
        $check(count($job['errors']) === 2 && $store->unmappedArticles($id) === ['AMBIGUOUS', 'MISSING'], 'Absent or ambiguous SKU was mapped');
        foreach ($shopify->sent as $request) {
            $check(count($request['prices']) === 2, 'Unresolved prices were sent');
        }
        $check(array_sum(array_column($store->confirmedCounts($id), 'confirmed')) === 20 && $store->claimNext()['action'] === 'preview', 'Confirmed state or queue continuation is incorrect');

        // Already mapped references need no bulk operation and retain confirmed amounts.
        file_put_contents($path . '/source.sql', 'SELECT ' . implode(', ', $columns));
        foreach (['manual', 'nightly'] as $origin) {
            $next = $store->queueSync($origin);
            $sync->start($next, $store->claimNext());
            $same = $store->jobStatus($next);
            $check($same['status'] === 'completed' && $same['summary']['unchanged_count'] === 10 && $same['summary']['changed_count'] === 0 && $shopify->lookups === 1, 'Mapped sync unnecessarily looked up or resent unchanged prices');
            $check($same['summary']['origin'] === $origin, 'Sync origin was lost');
        }

        // Terminal lookup failure must not use partial matches or block mapped prices.
        file_put_contents($path . '/source.sql', str_replace("'1.00'", "'2.00'", $sql));
        $failedId = $store->queueSync();
        $sync->start($failedId, $store->claimNext());
        $shopify->status = 'FAILED';
        $sync->poll($store->waitingShopifyJob());
        $failed = $store->jobStatus($failedId);
        $check($failed['summary']['lookup']['inconclusive'] === 2 && $failed['summary']['lookup']['missing'] === 0 && $failed['summary']['confirmed_count'] === 20, 'Lookup failure was treated as missing or stopped mapped references');
        $check(!in_array('missing_sku', array_column($failed['errors'], 'code'), true), 'Failed lookup created missing-SKU incidents');
        $shopify->rejectStart = true;
        $startFailedId = $store->queueSync();
        $sync->start($startFailedId, $store->claimNext());
        $check($store->jobStatus($startFailedId)['summary']['lookup']['inconclusive'] === 2 && $store->waitingShopifyJob() === null, 'Lookup start failure blocked the sync');

        // Initial load retains its catalog validation, omissions and bulk price flow.
        $shopify->rejectStart = false;
        $shopify->status = 'COMPLETED';
        $shopify->jsonl .= "\n" . json_encode(['id' => 'variant-mapped', 'sku' => 'MAPPED']);
        foreach ($tariffs as $tariff) {
            $shopify->catalogs[] = ['id' => 'catalog-' . $tariff, 'title' => $tariff, 'priceList' => ['id' => 'list-' . $tariff, 'currency' => 'EUR']];
        }
        $initialId = $store->queueInitialLoad();
        $store->claimNext();
        $initial = new InitialLoad($config, $store, $shopify);
        $initial->start($initialId);
        $check($store->waitingShopifyJob()['action'] === 'initial_load' && $store->claimNext() === null, 'Initial lookup did not retain queue exclusion');
        $initial->poll($store->waitingShopifyJob());
        $initialJob = $store->jobStatus($initialId);
        $check($initialJob['phase'] === 'prices' && $initialJob['status'] === 'waiting_shopify' && $shopify->uploads === 1 && count($store->articles($initialId)) === 2, 'Shared resolver changed initial-load omissions or price bulk flow');
    } finally {
        unset($store, $sync, $initial);
        $initialDirectory = $path . '/initial-' . ($initialId ?? 0);
        foreach (glob($initialDirectory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($initialDirectory)) {
            rmdir($initialDirectory);
        }
        foreach (glob($path . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($path);
    }
}
