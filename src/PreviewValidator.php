<?php

declare(strict_types=1);

final class PreviewValidator
{
    private array $seenArticleIds = [];

    public function __construct(private array $tariffs)
    {
    }

    public function validate(array $row, int $rowNumber, callable $onError): bool
    {
        $articleId = isset($row['IdArtículo']) ? trim((string) $row['IdArtículo']) : '';
        $valid = true;
        if ($articleId === '') {
            $onError($rowNumber, null, 'missing_article_id', 'Falta IdArtículo.');
            $valid = false;
        } elseif (isset($this->seenArticleIds[$articleId])) {
            $onError($rowNumber, $articleId, 'duplicate_article_id', 'IdArtículo está repetido en la consulta fuente.');
            $valid = false;
        } else {
            $this->seenArticleIds[$articleId] = true;
        }

        foreach ($this->tariffs as $tariff) {
            if (!isset($row[$tariff]) || trim((string) $row[$tariff]) === '') {
                $onError($rowNumber, $articleId !== '' ? $articleId : null, 'missing_price', 'Falta un precio para la tarifa ' . $tariff . '.');
                $valid = false;
            } elseif (!is_numeric($row[$tariff]) || (float) $row[$tariff] < 0) {
                $onError($rowNumber, $articleId, 'invalid_price', 'El precio de la tarifa ' . $tariff . ' no es un importe numérico no negativo.');
                $valid = false;
            }
        }

        return $valid;
    }
}
