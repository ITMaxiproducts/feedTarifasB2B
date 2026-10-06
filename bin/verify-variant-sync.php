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
    public bool $failStatus = false;
    public bool $failDownload = false;
    public bool $rejectPrices = false;
    public bool $operationMissing = false;
    public bool $denyStatus = false;
    public int $downloads = 0;

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
            if ($this->denyStatus) {
                throw new ShopifyRequestException('Controlled HTTP 401', false);
            }
            if ($this->failStatus) {
                throw new ShopifyRequestException('Controlled HTTP 503', true);
            }
            if ($this->operationMissing) {
                return ['bulkOperation' => null];
            }
            return ['bulkOperation' => ['id' => $variables['id'], 'status' => $this->status, 'objectCount' => 5,
                'url' => 'offline://variants', 'partialDataUrl' => 'offline://partial', 'errorCode' => 'CONTROLLED']];
        }
        if (str_contains($query, 'mutation SyncPrices')) {
            $this->sent[] = $variables;
            if ($this->rejectPrices) {
                return ['priceListFixedPricesAdd' => ['prices' => [], 'userErrors' => [['message' => 'Controlled price rejection']]]];
            }
            return ['priceListFixedPricesAdd' => ['prices' => array_map(static fn (array $price): array => ['variant' => ['id' => $price['variantId']]], $variables['prices']), 'userErrors' => []]];
        }
        throw new RuntimeException('Unexpected offline GraphQL call');
    }

    public function download(string $url, string $path): void
    {
        $this->downloads++;
        if ($this->failDownload) {
            file_put_contents($path, '{incomplete');
            throw new RuntimeException('Controlled download interruption');
        }
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
        $sync->poll($waiting);
        $check($shopify->lookups === 1 && $shopify->sent === [], 'Repeated pending poll duplicated lookup or sent prices');
        $display = ProgressPresenter::describe($store->jobStatus($id));
        $check($display['stage'] === 'Buscando variantes para referencias sin asociación' && str_contains($display['detail'], '3 referencias'), 'Lookup progress mixes units');
        // Source changes after preparation must not replace this job's snapshot.
        file_put_contents($path . '/source.sql', 'INVALID SQL');
        $shopify->status = 'COMPLETED';
        $shopify->failStatus = true;
        $sync->poll($waiting);
        $retry = $store->jobStatus($id);
        $check($retry['phase'] === 'variants_retry' && $retry['summary']['lookup_status'] === 'temporary_failure' && $retry['errors'] === [] && $store->claimNext() === null, 'Transient status failure lost continuation or became a missing SKU');
        $check(str_contains(ProgressPresenter::describe($retry)['detail'], '503'), 'Retry reason is not visible');
        $shopify->failStatus = false;
        $shopify->failDownload = true;
        $sync->poll($waiting);
        $check($store->waitingShopifyJob()['operation_id'] === 'lookup-1' && $store->unmappedArticles($id) === ['AMBIGUOUS', 'MISSING', 'NEW'], 'Incomplete download saved unsafe mappings');
        $shopify->failDownload = false;
        $validJsonl = $shopify->jsonl;
        $shopify->jsonl = '{malformed';
        $sync->poll($waiting);
        $check($store->jobStatus($id)['phase'] === 'variants_retry' && $store->jobStatus($id)['errors'] === [], 'Malformed JSONL became missing SKU outcomes');
        $shopify->jsonl = $validJsonl;
        // Simulate a killed cron during result processing, before its transaction.
        $retry = $store->jobStatus($id);
        $store->setProgress($id, 'running', 'variants_match', 'lookup-1', 0, 3, $retry['summary']);
        $manualQueued = $store->queueSync();
        $nightlyQueued = $store->queueNightlySync('2026-10-06', '02:00');
        unset($sync, $store);
        $store = new StateStore($config->sqlitePath);
        $sync = new SyncPrices($config, $store, $shopify);
        $store->failInterruptedJobs();
        $store->failInterruptedJobs();
        $check($store->waitingShopifyJob()['phase'] === 'variants_match' && $store->claimNext() === null && $store->jobStatus($id)['errors'] === [], 'Interrupted matching was failed or allowed conflicting work');
        // Force a failure after saving a unique mapping inside the transaction.
        $db = new PDO('sqlite:' . $config->sqlitePath);
        $db->exec("CREATE TRIGGER interrupt_matching BEFORE INSERT ON job_errors BEGIN SELECT RAISE(ABORT, 'Controlled matching interruption'); END");
        $rolledBack = false;
        try {
            $store->completeVariantLookup($id, 'lookup-1', ['status' => 'completed', 'outcomes' => [
                'NEW' => ['status' => 'unique', 'variant_id' => 'variant-new'],
                'MISSING' => ['status' => 'missing', 'variant_id' => null],
            ]], $store->jobStatus($id)['summary']);
        } catch (PDOException $error) {
            $rolledBack = str_contains($error->getMessage(), 'Controlled matching interruption');
        }
        $db->exec('DROP TRIGGER interrupt_matching');
        $db = null;
        $check($rolledBack && $store->unmappedArticles($id) === ['AMBIGUOUS', 'MISSING', 'NEW'] && $store->jobStatus($id)['summary']['lookup']['unique'] === 0, 'Interrupted matching committed partial mappings or counters');
        $sync->poll($store->waitingShopifyJob());
        $job = $store->jobStatus($id);
        $check($job['status'] === 'partial' && $job['summary']['confirmed_count'] === 20 && $job['summary']['failed_count'] === 0 && $job['summary']['skipped_count'] === 20, 'New mappings were not confirmed or omissions became failures');
        $check($job['summary']['lookup'] === ['requested' => 3, 'unique' => 1, 'missing' => 1, 'ambiguous' => 1, 'inconclusive' => 0], 'Lookup reference outcomes are incorrect');
        $check(count($job['errors']) === 2 && $store->unmappedArticles($id) === ['AMBIGUOUS', 'MISSING'], 'Absent or ambiguous SKU was mapped');
        foreach ($shopify->sent as $request) {
            $check(count($request['prices']) === 2, 'Unresolved prices were sent');
        }
        $check(array_sum(array_column($store->confirmedCounts($id), 'confirmed')) === 20 && $store->claimNext()['action'] === 'preview', 'Confirmed state or queue continuation is incorrect');
        $sentBefore = count($shopify->sent);
        $sync->poll($waiting);
        $check(count($shopify->sent) === $sentBefore && $shopify->lookups === 1 && count($store->jobStatus($id)['errors']) === 2, 'Stale completed poll repeated sends or incidents');
        $check((int) $store->claimNext()['id'] === $manualQueued, 'Manual queue ordering changed');
        $store->finish($manualQueued, 'completed', 0, 0, []);
        $check((int) $store->claimNext()['id'] === $nightlyQueued && $store->queueNightlySync('2026-10-06', '02:00') === null, 'Nightly queue ordering or daily deduplication changed');
        $store->finish($nightlyQueued, 'completed', 0, 0, []);

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
        $check($failed['summary']['lookup_status'] === 'terminal_failure' && $failed['summary']['failed_count'] === 0, 'Terminal lookup became a price-send failure');

        // Retry unresolved references in a later job, including prices whose
        // older confirmed value must survive a rejected replacement.
        file_put_contents($path . '/source.sql', str_replace("'1.00'", "'3.00'", $sql));
        $recoveredId = $store->queueSync();
        $sync->start($recoveredId, $store->claimNext());
        $shopify->status = 'COMPLETED';
        $shopify->jsonl = json_encode(['id' => 'variant-missing', 'sku' => 'MISSING']);
        $operation = $store->waitingShopifyJob();
        $outcomes = (new VariantResolver($shopify))->poll('lookup-1', $path . '/checkpoint.jsonl', $store->unmappedArticles($recoveredId));
        $store->completeVariantLookup($recoveredId, 'lookup-1', $outcomes, json_decode($operation['summary'], true));
        $checkpoint = $store->jobStatus($recoveredId);
        $store->setProgress($recoveredId, 'running', 'sync_ready', 'lookup-1', 0, 0, $checkpoint['summary']);
        $store->failInterruptedJobs();
        $downloadsBefore = $shopify->downloads;
        $lookupsBefore = $shopify->lookups;
        $shopify->rejectPrices = true;
        $sync->poll($store->waitingShopifyJob());
        $recovered = $store->jobStatus($recoveredId);
        $check($shopify->downloads === $downloadsBefore && $shopify->lookups === $lookupsBefore && $recovered['summary']['lookup']['unique'] === 1 && $recovered['summary']['lookup']['missing'] === 1, 'Result checkpoint repeated matching or lost reference counters');
        $prices = array_column($store->syncPrices($recoveredId), null, 'article_id');
        $check($prices['MAPPED']['synced_amount'] === '2.00' && $prices['NEW']['synced_amount'] === '2.00' && $prices['MISSING']['synced_amount'] === null && $recovered['summary']['failed_count'] === 30 && $recovered['summary']['skipped_count'] === 10, 'Rejected replacement destroyed confirmed values or confused omissions');
        $shopify->rejectPrices = false;
        $finalId = $store->queueSync();
        $sync->start($finalId, $store->claimNext());
        $shopify->operationMissing = true;
        $sync->poll($store->waitingShopifyJob());
        $shopify->operationMissing = false;
        $check($store->jobStatus($finalId)['summary']['confirmed_count'] === 30 && $store->jobStatus($finalId)['summary']['lookup_status'] === 'terminal_failure', 'Missing operation blocked known references or failed to retry unconfirmed prices');
        $shopify->rejectStart = true;
        $startFailedId = $store->queueSync();
        $sync->start($startFailedId, $store->claimNext());
        $check($store->jobStatus($startFailedId)['summary']['lookup']['inconclusive'] === 1 && $store->waitingShopifyJob() === null, 'Lookup start failure blocked the sync');
        $shopify->rejectStart = false;
        $deniedId = $store->queueSync();
        $sync->start($deniedId, $store->claimNext());
        $shopify->denyStatus = true;
        $sync->poll($store->waitingShopifyJob());
        $shopify->denyStatus = false;
        $check($store->jobStatus($deniedId)['summary']['lookup_status'] === 'terminal_failure' && $store->waitingShopifyJob() === null, 'Permanent GraphQL failure was retried indefinitely');

        // Stages without a durable lookup remain failed, including old jobs.
        $legacyInterrupted = $store->queueSync();
        $store->claimNext();
        $store->setProgress($legacyInterrupted, 'running', 'sync', null, 1, 2, ['confirmed_count' => 10]);
        $store->failInterruptedJobs();
        $check($store->jobStatus($legacyInterrupted)['status'] === 'failed' && $store->jobStatus($legacyInterrupted)['summary']['confirmed_count'] === 10, 'Historical interrupted job was restarted or lost confirmed counters');
        $unsafeId = $store->queueSync();
        $store->claimNext();
        $store->setProgress($unsafeId, 'running', 'variants_start', null, 0, 1, []);
        $lookupsBefore = $shopify->lookups;
        $store->failInterruptedJobs();
        $check($store->jobStatus($unsafeId)['status'] === 'failed' && $shopify->lookups === $lookupsBefore, 'Lookup without saved ID was restarted unsafely');

        // Initial load retains its catalog validation, omissions and bulk price flow.
        $shopify->rejectStart = false;
        $shopify->status = 'COMPLETED';
        $shopify->jsonl = implode("\n", array_map(static fn (array $row): string => json_encode($row), [
            ['id' => 'variant-new', 'sku' => 'NEW'], ['id' => 'variant-mapped', 'sku' => 'MAPPED'],
            ['id' => 'variant-a', 'sku' => 'AMBIGUOUS'], ['id' => 'variant-b', 'sku' => 'AMBIGUOUS'],
        ]));
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
