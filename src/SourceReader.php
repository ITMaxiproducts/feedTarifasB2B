<?php

declare(strict_types=1);

final class SourceReader
{
    public function __construct(private Config $config)
    {
    }

    public function preview(callable $onError, ?callable $onProgress = null): array
    {
        return $this->scan($onError, static function (): void {}, $onProgress);
    }

    public function scan(callable $onError, callable $onValidRow, ?callable $onProgress = null): array
    {
        $tariffs = $this->config->tariffColumns;
        if (count($tariffs) !== 10) {
            throw new RuntimeException('Configura exactamente los diez aliases de tarifa en .env.');
        }
        if (!is_file($this->config->sourceSqlPath)) {
            throw new RuntimeException('No se encontró la consulta fuente ' . $this->config->sourceSqlPath . '.');
        }

        $sql = trim((string) file_get_contents($this->config->sourceSqlPath));
        if ($sql === '' || preg_match('/\bSELECT\b/i', $sql) !== 1) {
            throw new RuntimeException('sql/prices.sql aún no contiene la consulta SQL Server fuente.');
        }

        $pdo = new PDO($this->config->sqlsrvDsn, $this->config->sqlsrvUsername, $this->config->sqlsrvPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $statement = $pdo->query($sql);
        $expected = array_merge(['IdArtículo', 'Marca'], $tariffs);
        $columns = [];
        for ($index = 0; $index < $statement->columnCount(); $index++) {
            $metadata = $statement->getColumnMeta($index);
            if (is_array($metadata) && isset($metadata['name'])) {
                $columns[] = (string) $metadata['name'];
            }
        }

        foreach ($expected as $column) {
            if (!in_array($column, $columns, true)) {
                throw new RuntimeException('La consulta fuente no devuelve la columna requerida: ' . $column . '.');
            }
        }

        $validator = new PreviewValidator($tariffs);
        $rowNumber = 0;
        $validRows = 0;
        $invalidRows = 0;
        while (($row = $statement->fetch()) !== false) {
            $rowNumber++;
            if ($validator->validate($row, $rowNumber, $onError)) {
                $validRows++;
                $onValidRow($rowNumber, $row);
            } else {
                $invalidRows++;
            }
            if ($onProgress !== null && $rowNumber % 100 === 0) {
                $onProgress($rowNumber, $validRows, $invalidRows);
            }
        }

        if ($rowNumber === 0) {
            throw new RuntimeException('La consulta fuente no devolvió ningún artículo.');
        }
        if ($onProgress !== null && $rowNumber % 100 !== 0) {
            $onProgress($rowNumber, $validRows, $invalidRows);
        }

        return [
            'article_count' => $rowNumber,
            'tariff_count' => count($tariffs),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ];
    }
}
