<?php

declare(strict_types=1);

final class Config
{
    public function __construct(
        public readonly string $sqlsrvDsn,
        public readonly string $sqlsrvUsername,
        public readonly string $sqlsrvPassword,
        public readonly array $tariffColumns,
        public readonly string $sqlitePath,
        public readonly string $sourceSqlPath,
        public readonly string $storagePath,
        public readonly string $shopDomain = '',
        public readonly string $shopToken = '',
        public readonly array $currencies = [],
        public readonly array $decimalPlaces = [],
        public readonly string $nightlySyncTime = '02:00',
    ) {
    }

    public static function load(string $rootPath): self
    {
        $path = rtrim($rootPath, '/\\') . '/.env';
        if (!is_file($path)) {
            throw new RuntimeException('Falta .env en la raíz del proyecto. Copia .env.example y completa sus valores.');
        }

        $values = self::parseEnvFile($path);
        foreach (['SQLSRV_DSN', 'SQLSRV_USERNAME', 'SQLSRV_PASSWORD', 'PRICE_TARIFF_COLUMNS'] as $key) {
            if (!array_key_exists($key, $values)) {
                throw new RuntimeException('Falta la variable ' . $key . ' en .env.');
            }
        }

        $tariffs = array_values(array_filter(array_map('trim', explode(',', $values['PRICE_TARIFF_COLUMNS']))));

        $nightlySyncTime = $values['NIGHTLY_SYNC_TIME'] ?? '02:00';
        if (preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $nightlySyncTime) !== 1) {
            throw new RuntimeException('NIGHTLY_SYNC_TIME debe tener formato HH:MM (00:00–23:59).');
        }

        return new self(
            $values['SQLSRV_DSN'],
            $values['SQLSRV_USERNAME'],
            $values['SQLSRV_PASSWORD'],
            $tariffs,
            self::resolvePath($rootPath, $values['SQLITE_PATH'] ?? 'var/jobs.sqlite'),
            self::resolvePath($rootPath, $values['SOURCE_SQL_PATH'] ?? 'sql/prices.sql'),
            rtrim($rootPath, '/\\') . '/var',
            $values['SHOPIFY_SHOP_DOMAIN'] ?? '',
            $values['SHOPIFY_ACCESS_TOKEN'] ?? '',
            self::parseTariffMap($values['TARIFF_CURRENCIES'] ?? '', $tariffs),
            self::parseTariffMap($values['TARIFF_DECIMAL_PLACES'] ?? '', $tariffs),
            $nightlySyncTime,
        );
    }

    public function requireShopify(): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $this->shopDomain) || $this->shopToken === '') {
            throw new RuntimeException('Configura SHOPIFY_SHOP_DOMAIN y SHOPIFY_ACCESS_TOKEN en .env.');
        }
        foreach ($this->tariffColumns as $tariff) {
            if (!preg_match('/^[A-Z]{3}$/', $this->currencies[$tariff] ?? '') ||
                !preg_match('/^[0-9]$/', $this->decimalPlaces[$tariff] ?? '')) {
                throw new RuntimeException('Configura moneda y decimales explícitos para la tarifa ' . $tariff . '.');
            }
        }
    }

    private static function parseTariffMap(string $value, array $tariffs): array
    {
        if ($value === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $value));
        $map = [];
        foreach ($parts as $part) {
            [$key, $item] = array_pad(array_map('trim', explode(':', $part, 2)), 2, '');
            if (!in_array($key, $tariffs, true) || $item === '' || isset($map[$key])) {
                throw new RuntimeException('Mapa de tarifas inválido en .env.');
            }
            $map[$key] = $item;
        }
        return $map;
    }

    private static function parseEnvFile(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $key = trim($key);
            if ($value === null || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
                throw new RuntimeException('Hay una línea inválida en el archivo .env.');
            }

            $value = trim($value);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    private static function resolvePath(string $rootPath, string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            ? $path
            : $rootPath . '/' . ltrim($path, '/\\');
    }
}
