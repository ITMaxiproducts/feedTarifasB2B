<?php

declare(strict_types=1);

class ShopifyClient
{
    private string $endpoint;

    public function __construct(private Config $config)
    {
        $config->requireShopify();
        if (!extension_loaded('curl')) {
            throw new RuntimeException('La extensión PHP cURL es necesaria para Shopify.');
        }
        $this->endpoint = 'https://' . $config->shopDomain . '/admin/api/2026-07/graphql.json';
    }

    public function graphql(string $query, array $variables = [], bool $retryTransient = false): array
    {
        $body = json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $maxAttempts = $retryTransient ? 4 : 1;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $retryAfter = null;
            $handle = curl_init($this->endpoint);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Shopify-Access-Token: ' . $this->config->shopToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$retryAfter): int {
                    if (stripos($header, 'Retry-After:') === 0) {
                        $retryAfter = trim(substr($header, strlen('Retry-After:')));
                    }
                    return strlen($header);
                },
            ]);
            $response = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            $curlError = curl_errno($handle);
            curl_close($handle);
            $retryable = $response === false && $curlError !== CURLE_ABORTED_BY_CALLBACK;
            if ($response !== false && $status >= 200 && $status < 300) {
                $result = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
                $codes = array_column(array_map(static fn (array $entry): array => (array) ($entry['extensions'] ?? []), $result['errors'] ?? []), 'code');
                $retryable = $retryTransient && array_intersect($codes, ['THROTTLED', 'INTERNAL_SERVER_ERROR', 'SERVICE_UNAVAILABLE']) !== [];
                if (!$retryable) {
                    if (isset($result['errors']) || !isset($result['data'])) {
                        throw new RuntimeException('Shopify GraphQL: ' . json_encode($result['errors'] ?? $result, JSON_UNESCAPED_UNICODE));
                    }
                    return $result['data'];
                }
            } elseif ($response !== false) {
                $retryable = $retryTransient && ($status === 429 || $status >= 500);
            }
            if (!$retryable || $attempt === $maxAttempts - 1) {
                if ($response === false || $status < 200 || $status >= 300) {
                    throw new RuntimeException('Shopify GraphQL HTTP ' . $status . ($error !== '' ? ': ' . $error : '.'));
                }
                throw new RuntimeException('Shopify GraphQL temporalmente no disponible tras ' . $maxAttempts . ' intento(s).');
            }
            $delay = is_numeric($retryAfter) ? min(30, max(1, (int) $retryAfter)) : min(8, 1 << $attempt);
            sleep($delay);
        }
        throw new RuntimeException('Shopify GraphQL no respondió.');
    }

    public function uploadJsonl(string $path): string
    {
        $result = $this->graphql(<<<'GQL'
            mutation Stage($input: [StagedUploadInput!]!) {
              stagedUploadsCreate(input: $input) {
                stagedTargets { url parameters { name value } }
                userErrors { field message }
              }
            }
            GQL, ['input' => [[
                'filename' => basename($path), 'mimeType' => 'text/jsonl',
                'httpMethod' => 'POST', 'resource' => 'BULK_MUTATION_VARIABLES',
            ]]]);
        $stage = $result['stagedUploadsCreate'];
        self::assertNoUserErrors($stage);
        $target = $stage['stagedTargets'][0] ?? null;
        if ($target === null || !str_starts_with($target['url'] ?? '', 'https://')) {
            throw new RuntimeException('Shopify no devolvió un destino seguro para el JSONL.');
        }
        $fields = [];
        foreach ($target['parameters'] as $parameter) {
            $fields[$parameter['name']] = $parameter['value'];
        }
        $key = $fields['key'] ?? null;
        if ($key === null) {
            throw new RuntimeException('Shopify no devolvió la ruta del archivo staged.');
        }
        $fields['file'] = new CURLFile($path, 'text/jsonl', basename($path));
        $handle = curl_init($target['url']);
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180]);
        $response = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false || $status < 200 || $status >= 300) {
            throw new RuntimeException('Falló la subida staged HTTP ' . $status . ($error !== '' ? ': ' . $error : '.'));
        }
        return $key;
    }

    public function download(string $url, string $path): void
    {
        if (!str_starts_with($url, 'https://')) {
            throw new RuntimeException('Shopify devolvió una URL de resultado no segura.');
        }
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new RuntimeException('No se puede escribir el resultado privado de Shopify.');
        }
        try {
            $handle = curl_init($url);
            curl_setopt_array($handle, [CURLOPT_FILE => $stream, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180]);
            $ok = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($ok === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Falló la descarga Shopify HTTP ' . $status . ($error !== '' ? ': ' . $error : '.'));
            }
        } finally {
            fclose($stream);
        }
    }

    public static function assertNoUserErrors(array $payload): void
    {
        if (($payload['userErrors'] ?? []) !== []) {
            throw new RuntimeException('Shopify: ' . implode('; ', array_column($payload['userErrors'], 'message')));
        }
    }
}
