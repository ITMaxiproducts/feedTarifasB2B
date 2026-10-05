<?php

declare(strict_types=1);

final class StateStore
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo crear el directorio privado de estado.');
        }

        $this->pdo = new PDO('sqlite:' . $path, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                action TEXT NOT NULL,
                status TEXT NOT NULL,
                created_at TEXT NOT NULL,
                started_at TEXT,
                completed_at TEXT,
                article_count INTEGER NOT NULL DEFAULT 0,
                tariff_count INTEGER NOT NULL DEFAULT 0,
                summary TEXT
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS job_errors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL REFERENCES jobs(id) ON DELETE CASCADE,
                row_number INTEGER,
                article_id TEXT,
                code TEXT NOT NULL,
                message TEXT NOT NULL
            )
            SQL);
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS job_errors_by_job ON job_errors(job_id)');
        $jobColumns = array_column($this->pdo->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
        foreach (['phase TEXT', 'operation_id TEXT', 'object_count INTEGER NOT NULL DEFAULT 0', 'expected_count INTEGER NOT NULL DEFAULT 0', 'progress_at TEXT'] as $column) {
            if (!in_array(strtok($column, ' '), $jobColumns, true)) {
                $this->pdo->exec('ALTER TABLE jobs ADD COLUMN ' . $column);
            }
        }
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS one_active_initial_load ON jobs(action) WHERE action = \'initial_load\' AND status IN (\'pending\', \'running\', \'waiting_shopify\')');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS catalog_map (tariff TEXT PRIMARY KEY, catalog_id TEXT NOT NULL, price_list_id TEXT NOT NULL, currency TEXT NOT NULL, resolved_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS variant_map (article_id TEXT PRIMARY KEY, variant_id TEXT NOT NULL, resolved_at TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS job_prices (job_id INTEGER NOT NULL, article_id TEXT NOT NULL, tariff TEXT NOT NULL, amount TEXT NOT NULL, currency TEXT NOT NULL, variant_id TEXT, row_number INTEGER NOT NULL, confirmed INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (job_id, article_id, tariff))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS bulk_lines (job_id INTEGER NOT NULL, line_number INTEGER NOT NULL, tariff TEXT NOT NULL, article_ids TEXT NOT NULL, PRIMARY KEY (job_id, line_number))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS synced_prices (article_id TEXT NOT NULL, tariff TEXT NOT NULL, amount TEXT NOT NULL, currency TEXT NOT NULL, variant_id TEXT NOT NULL, confirmed_at TEXT NOT NULL, PRIMARY KEY (article_id, tariff))');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS nightly_sync_runs (run_date TEXT PRIMARY KEY, job_id INTEGER NOT NULL REFERENCES jobs(id))');
    }

    public function queuePreview(): int
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO jobs (action, status, created_at) VALUES ('preview', 'pending', :created_at)",
        );
        $statement->execute(['created_at' => gmdate(DATE_ATOM)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function queueInitialLoad(): int
    {
        try {
            $statement = $this->pdo->prepare("INSERT INTO jobs (action, status, created_at, phase) VALUES ('initial_load', 'pending', :created_at, 'source')");
            $statement->execute(['created_at' => gmdate(DATE_ATOM)]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $error) {
            if (str_contains($error->getMessage(), 'UNIQUE constraint failed')) {
                throw new RuntimeException('Ya hay una carga inicial activa.', previous: $error);
            }
            throw $error;
        }
    }

    public function queueSync(string $origin = 'manual'): int
    {
        $statement = $this->pdo->prepare("INSERT INTO jobs (action, status, created_at, phase, summary) VALUES ('sync', 'pending', :created_at, 'source', :summary)");
        $statement->execute([
            'created_at' => gmdate(DATE_ATOM),
            'summary' => json_encode(['origin' => $origin], JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function queueNightlySync(string $runDate, string $scheduledTime): ?int
    {
        $this->pdo->beginTransaction();
        try {
            $existing = $this->pdo->prepare('SELECT job_id FROM nightly_sync_runs WHERE run_date = ?');
            $existing->execute([$runDate]);
            $jobId = $existing->fetchColumn();
            if ($jobId !== false) {
                $this->pdo->commit();
                return null;
            }
            $statement = $this->pdo->prepare("INSERT INTO jobs (action, status, created_at, phase, summary) VALUES ('sync', 'pending', :created_at, 'source', :summary)");
            $statement->execute([
                'created_at' => gmdate(DATE_ATOM),
                'summary' => json_encode(['origin' => 'nightly', 'scheduled_time' => $scheduledTime], JSON_THROW_ON_ERROR),
            ]);
            $jobId = (int) $this->pdo->lastInsertId();
            $run = $this->pdo->prepare('INSERT INTO nightly_sync_runs (run_date, job_id) VALUES (?, ?)');
            $run->execute([$runDate, $jobId]);
            $this->pdo->commit();
            return $jobId;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function syncPrices(int $jobId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT p.article_id, p.tariff, p.amount, p.currency, p.row_number,
                   v.variant_id,
                   s.amount AS synced_amount, s.currency AS synced_currency
            FROM job_prices p
            LEFT JOIN variant_map v ON v.article_id = p.article_id
            LEFT JOIN synced_prices s ON s.article_id = p.article_id AND s.tariff = p.tariff
            WHERE p.job_id = ?
            ORDER BY p.tariff, p.article_id
            SQL);
        $statement->execute([$jobId]);
        return $statement->fetchAll();
    }

    public function lastNightlyRun(): ?string
    {
        $date = $this->pdo->query('SELECT MAX(run_date) FROM nightly_sync_runs')->fetchColumn();
        return $date === false || $date === null ? null : (string) $date;
    }

    public function waitingInitialLoad(): ?array
    {
        $job = $this->pdo->query("SELECT * FROM jobs WHERE action = 'initial_load' AND status = 'waiting_shopify' ORDER BY id LIMIT 1")->fetch();
        return $job === false ? null : $job;
    }

    public function waitingShopifyJob(): ?array
    {
        $job = $this->pdo->query("SELECT * FROM jobs WHERE action IN ('initial_load', 'sync') AND status = 'waiting_shopify' ORDER BY id LIMIT 1")->fetch();
        return $job === false ? null : $job;
    }

    public function unmappedArticles(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT p.article_id FROM job_prices p LEFT JOIN variant_map v ON v.article_id = p.article_id WHERE p.job_id = ? AND v.article_id IS NULL ORDER BY p.article_id');
        $statement->execute([$jobId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function activeInitialLoad(): bool
    {
        return (bool) $this->pdo->query("SELECT 1 FROM jobs WHERE action = 'initial_load' AND status IN ('pending','running','waiting_shopify') LIMIT 1")->fetchColumn();
    }

    public function failInterruptedJobs(): void
    {
        foreach ($this->pdo->query("SELECT * FROM jobs WHERE status = 'running'") as $job) {
            $jobId = (int) $job['id'];
            $this->addError($jobId, null, null, 'worker_interrupted', 'El trabajador anterior se detuvo antes de terminar esta acción.');
            $summary = $job['summary'] ? json_decode($job['summary'], true, flags: JSON_THROW_ON_ERROR) : [];
            $summary['error_count'] = $this->errorCount($jobId);
            $this->finish($jobId, 'failed', (int) $job['article_count'], (int) $job['tariff_count'], $summary);
        }
    }

    public function setProgress(int $jobId, string $status, string $phase, ?string $operationId, int $objectCount, int $expectedCount, array $summary): void
    {
        $statement = $this->pdo->prepare('UPDATE jobs SET status = :status, phase = :phase, operation_id = :operation_id, object_count = :object_count, expected_count = :expected_count, article_count = :article_count, tariff_count = :tariff_count, summary = :summary, progress_at = :progress_at WHERE id = :id');
        $statement->execute([
            'status' => $status, 'phase' => $phase, 'operation_id' => $operationId,
            'object_count' => $objectCount, 'expected_count' => $expectedCount,
            'article_count' => $summary['article_count'] ?? 0,
            'tariff_count' => $summary['tariff_count'] ?? 0,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'progress_at' => gmdate(DATE_ATOM), 'id' => $jobId,
        ]);
    }

    public function addJobPrice(int $jobId, int $rowNumber, string $article, string $tariff, string $amount, string $currency): void
    {
        $statement = $this->pdo->prepare('INSERT INTO job_prices (job_id, row_number, article_id, tariff, amount, currency) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$jobId, $rowNumber, $article, $tariff, $amount, $currency]);
    }

    public function discardArticlePrices(int $jobId, string $article): void
    {
        $statement = $this->pdo->prepare('DELETE FROM job_prices WHERE job_id = ? AND article_id = ?');
        $statement->execute([$jobId, $article]);
    }

    public function articles(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT DISTINCT article_id FROM job_prices WHERE job_id = ?');
        $statement->execute([$jobId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function saveVariant(string $article, string $variantId): void
    {
        $statement = $this->pdo->prepare('INSERT INTO variant_map VALUES (?, ?, ?) ON CONFLICT(article_id) DO UPDATE SET variant_id = excluded.variant_id, resolved_at = excluded.resolved_at');
        $statement->execute([$article, $variantId, gmdate(DATE_ATOM)]);
    }

    public function attachVariants(int $jobId): void
    {
        $statement = $this->pdo->prepare('UPDATE job_prices SET variant_id = (SELECT variant_id FROM variant_map WHERE article_id = job_prices.article_id) WHERE job_id = ?');
        $statement->execute([$jobId]);
    }

    public function pricesForTariff(int $jobId, string $tariff): array
    {
        $statement = $this->pdo->prepare('SELECT article_id, amount, currency, variant_id, row_number FROM job_prices WHERE job_id = ? AND tariff = ? ORDER BY article_id');
        $statement->execute([$jobId, $tariff]);
        return $statement->fetchAll();
    }

    public function saveCatalog(string $tariff, string $catalogId, string $priceListId, string $currency): void
    {
        $statement = $this->pdo->prepare('INSERT INTO catalog_map VALUES (?, ?, ?, ?, ?) ON CONFLICT(tariff) DO UPDATE SET catalog_id = excluded.catalog_id, price_list_id = excluded.price_list_id, currency = excluded.currency, resolved_at = excluded.resolved_at');
        $statement->execute([$tariff, $catalogId, $priceListId, $currency, gmdate(DATE_ATOM)]);
    }

    public function catalogs(): array
    {
        return $this->pdo->query('SELECT tariff, catalog_id, price_list_id, currency, resolved_at FROM catalog_map ORDER BY tariff')->fetchAll();
    }

    public function saveBulkLine(int $jobId, int $lineNumber, string $tariff, array $articles): void
    {
        $statement = $this->pdo->prepare('INSERT INTO bulk_lines VALUES (?, ?, ?, ?) ON CONFLICT(job_id, line_number) DO UPDATE SET tariff = excluded.tariff, article_ids = excluded.article_ids');
        $statement->execute([$jobId, $lineNumber, $tariff, json_encode($articles, JSON_THROW_ON_ERROR)]);
    }

    public function bulkLine(int $jobId, int $lineNumber): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bulk_lines WHERE job_id = ? AND line_number = ?');
        $statement->execute([$jobId, $lineNumber]);
        $line = $statement->fetch();
        if ($line !== false) {
            $line['article_ids'] = json_decode($line['article_ids'], true, flags: JSON_THROW_ON_ERROR);
        }
        return $line === false ? null : $line;
    }

    public function bulkLineNumbers(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT line_number FROM bulk_lines WHERE job_id = ?');
        $statement->execute([$jobId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public function confirmPrice(int $jobId, string $article, string $tariff): void
    {
        $statement = $this->pdo->prepare('INSERT INTO synced_prices SELECT article_id, tariff, amount, currency, variant_id, ? FROM job_prices WHERE job_id = ? AND article_id = ? AND tariff = ? AND variant_id IS NOT NULL ON CONFLICT(article_id, tariff) DO UPDATE SET amount = excluded.amount, currency = excluded.currency, variant_id = excluded.variant_id, confirmed_at = excluded.confirmed_at');
        $statement->execute([gmdate(DATE_ATOM), $jobId, $article, $tariff]);
        $mark = $this->pdo->prepare('UPDATE job_prices SET confirmed = 1 WHERE job_id = ? AND article_id = ? AND tariff = ? AND variant_id IS NOT NULL');
        $mark->execute([$jobId, $article, $tariff]);
    }

    public function confirmedCounts(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT tariff, SUM(confirmed) AS confirmed FROM job_prices WHERE job_id = ? GROUP BY tariff');
        $statement->execute([$jobId]);
        return $statement->fetchAll();
    }

    public function expectedCounts(int $jobId): array
    {
        $statement = $this->pdo->prepare('SELECT tariff, COUNT(*) AS expected FROM job_prices WHERE job_id = ? GROUP BY tariff');
        $statement->execute([$jobId]);
        return $statement->fetchAll();
    }

    public function errorCount(int $jobId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM job_errors WHERE job_id = ?');
        $statement->execute([$jobId]);
        return (int) $statement->fetchColumn();
    }

    public function claimNext(): ?array
    {
        if ($this->waitingShopifyJob() !== null) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $job = $this->pdo->query("SELECT * FROM jobs WHERE status = 'pending' ORDER BY CASE WHEN action = 'initial_load' THEN 0 ELSE 1 END, id LIMIT 1")->fetch();
            if ($job === false) {
                $this->pdo->commit();
                return null;
            }

            $statement = $this->pdo->prepare(
                "UPDATE jobs SET status = 'running', started_at = :started_at, progress_at = :started_at WHERE id = :id AND status = 'pending'",
            );
            $statement->execute(['started_at' => gmdate(DATE_ATOM), 'id' => $job['id']]);
            $this->pdo->commit();
            return $statement->rowCount() === 1 ? $job : null;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function addError(int $jobId, ?int $rowNumber, ?string $articleId, string $code, string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO job_errors (job_id, row_number, article_id, code, message) VALUES (:job_id, :row_number, :article_id, :code, :message)',
        );
        $statement->execute([
            'job_id' => $jobId,
            'row_number' => $rowNumber,
            'article_id' => $articleId,
            'code' => $code,
            'message' => $message,
        ]);
    }

    public function finish(int $jobId, string $status, int $articleCount, int $tariffCount, array $summary): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE jobs SET status = :status, completed_at = :completed_at, progress_at = :completed_at, article_count = :article_count, tariff_count = :tariff_count, summary = :summary WHERE id = :id',
        );
        $statement->execute([
            'status' => $status,
            'completed_at' => gmdate(DATE_ATOM),
            'article_count' => $articleCount,
            'tariff_count' => $tariffCount,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'id' => $jobId,
        ]);
    }

    public function latest(): ?array
    {
        $job = $this->pdo->query('SELECT * FROM jobs ORDER BY id DESC LIMIT 1')->fetch();
        return $job === false ? null : $this->hydrateJob($job);
    }

    public function jobStatus(int $jobId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE id = ?');
        $statement->execute([$jobId]);
        $job = $statement->fetch();
        if ($job === false) {
            return null;
        }
        return $this->hydrateJob($job);
    }

    public function latestInitialLoad(): ?array
    {
        $job = $this->pdo->query("SELECT * FROM jobs WHERE action = 'initial_load' ORDER BY id DESC LIMIT 1")->fetch();
        return $job === false ? null : $this->hydrateJob($job);
    }

    private function hydrateJob(array $job): array
    {
        $statement = $this->pdo->prepare('SELECT row_number, article_id, code, message, COUNT(*) AS occurrences FROM job_errors WHERE job_id = :job_id GROUP BY row_number, article_id, code, message ORDER BY MAX(id) DESC LIMIT 50');
        $statement->execute(['job_id' => $job['id']]);
        $job['errors'] = $statement->fetchAll();
        $job['summary'] = $job['summary'] === null ? [] : json_decode($job['summary'], true, flags: JSON_THROW_ON_ERROR);
        $totals = $this->pdo->prepare('SELECT COUNT(DISTINCT article_id) AS affected_references, COUNT(*) AS records FROM job_errors WHERE job_id = ?');
        $totals->execute([$job['id']]);
        $totals = $totals->fetch();
        $groups = $this->pdo->prepare('SELECT COUNT(*) FROM (SELECT 1 FROM job_errors WHERE job_id = ? GROUP BY row_number, article_id, code, message)');
        $groups->execute([$job['id']]);
        $job['summary']['error_count'] = (int) $totals['records'];
        $job['summary']['incident_count'] = (int) $groups->fetchColumn();
        $job['summary']['affected_reference_count'] = (int) $totals['affected_references'];

        // Adapt legacy counters for display only; retain the original records in SQLite.
        if ($job['action'] === 'sync' && $job['phase'] === 'sync' && !isset($job['summary']['lookup'])) {
            $omitted = $this->pdo->prepare(<<<'SQL'
                SELECT p.tariff, COUNT(*) AS skipped
                FROM job_prices p
                JOIN (SELECT DISTINCT article_id FROM job_errors
                      WHERE job_id = ? AND code = 'variant_unmapped') e
                  ON e.article_id = p.article_id
                WHERE p.job_id = ? AND p.variant_id IS NULL
                GROUP BY p.tariff
                SQL);
            $omitted->execute([$job['id'], $job['id']]);
            $skipped = array_column($omitted->fetchAll(), 'skipped', 'tariff');
            $prepared = array_column($this->expectedCounts((int) $job['id']), 'expected', 'tariff');
            $unmapped = $this->pdo->prepare("SELECT COUNT(DISTINCT article_id) FROM job_errors WHERE job_id = ? AND code = 'variant_unmapped'");
            $unmapped->execute([$job['id']]);
            $job['summary']['unmapped_article_count'] = (int) $unmapped->fetchColumn();
            foreach ($job['summary']['per_tariff'] ?? [] as $tariff => $counts) {
                $counts['skipped'] = min((int) ($skipped[$tariff] ?? 0), (int) ($counts['changed'] ?? 0));
                $counts['unchanged'] = max(0, (int) ($prepared[$tariff] ?? 0) - (int) ($counts['changed'] ?? 0));
                $counts['changed'] = max(0, (int) ($counts['changed'] ?? 0) - $counts['skipped']);
                $counts['failed'] = max(0, (int) ($counts['failed'] ?? 0) - $counts['skipped']);
                $job['summary']['per_tariff'][$tariff] = $counts;
            }
            foreach (['changed', 'failed', 'skipped', 'unchanged'] as $key) {
                $job['summary'][$key . '_count'] = array_sum(array_column($job['summary']['per_tariff'] ?? [], $key));
            }
        }
        return $job;
    }
}
