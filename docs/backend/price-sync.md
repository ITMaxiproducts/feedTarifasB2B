# 🎯 Sincronizar únicamente los precios que cambiaron

## 💡 Convention

El sync vuelve a leer la consulta SQL y compara cada `(IdArtículo, tarifa, importe, moneda)` con `synced_prices`. Omite los valores iguales y envía los demás a la lista de precios resuelta para esa tarifa, en peticiones GraphQL de hasta 250 precios.

Antes de comparar, identifica las referencias de los precios preparados que no tienen asociación en `variant_map`. Si existen, inicia una única búsqueda masiva de variantes y guarda su ID en el mismo job. Manual y nocturno usan este flujo. Solo un SKU exacto con un único ID de variante crea una asociación; los ausentes o ambiguos se omiten y se vuelven a buscar en sincronizaciones posteriores. Las asociaciones positivas existentes se conservan; este flujo no comprueba reasignaciones o eliminaciones de variantes ya asociadas.

La continuación consulta el ID guardado y usa `job_prices`, sin releer SQL ni iniciar otra búsqueda. Las etapas `variants`, `variants_match` y `variants_retry` con operación y precios preparados se recuperan al arrancar el worker. Asociaciones, incidencias, contadores de búsqueda y el punto `sync_ready` se guardan en una transacción. Una interrupción revierte todo ese resultado o deja el punto completo; desde `sync_ready` se continúa la comparación sin repetir el emparejamiento.

Un fallo temporal de consulta (red, HTTP 429/5xx o GraphQL transitorio), descarga incompleta o JSONL inválido deja `variants_retry`, conserva el ID y muestra el motivo en el panel. El siguiente cron vuelve a consultar la misma operación, sin clasificar referencias como ausentes. Una operación fallida, cancelada, expirada o inexistente, o un error permanente de GraphQL, produce `lookup_status=terminal_failure`: las referencias sin resolver quedan inconcluyentes y las ya asociadas continúan. Nunca se usan resultados parciales de una operación fallida. Los fallos temporales recuperados no crean incidencias definitivas.

Sin ID persistido no hay recuperación automática segura: una interrupción en `source` o `variants_start` termina como fallida, sin iniciar otra búsqueda para ese job. La etapa de envío directo `sync` mantiene ese comportamiento; los valores ya confirmados sobreviven y una sincronización posterior compara de nuevo los pendientes. La recuperación descrita cubre la búsqueda y su procesamiento, no la repetición automática de mutaciones cuyo resultado se desconoce.

Un precio solo pasa a `synced_prices` cuando la respuesta de Shopify incluye el `variant.id` correspondiente. Los errores de referencia se guardan en `job_errors`, se muestran en el panel y no detienen otras referencias ni tarifas. Si un precio no queda confirmado, el valor anterior permanece en SQLite; una ejecución posterior vuelve a considerarlo mientras la fuente siga indicando el cambio.

El panel encola el sync manual mediante `POST /actions/sync`. El worker puede encolar un sync nocturno una vez por fecha local desde `NIGHTLY_SYNC_TIME` (por defecto `02:00`). Ejecuta el cron cada minuto para atender esa hora, las acciones manuales y las cargas Shopify pendientes. Un bloqueo de archivo impide workers simultáneos; mientras una carga inicial o una sincronización espera su operación o recupera sus resultados, las acciones nuevas permanecen en cola.

Los reintentos HTTP y GraphQL transitorios se aplican a las peticiones directas del sync. Shopify user errors y precios no confirmados se registran por referencia y se pueden reintentar en ejecuciones futuras. La petición es idempotente respecto al estado local: solo actualiza `synced_prices` después de confirmar Shopify.

## 🏆 Benefits

- Evita reenviar precios que ya están confirmados con el mismo importe y moneda.
- Mantiene los errores aislados por referencia y permite que el resto del lote continúe.
- Conserva una comparación fiable entre el precio fuente y el último valor confirmado.
- Evita crear más de un job nocturno por día local y mantiene manual y cron en la misma cola.

## 👀 Examples

### ✅ Good: Confirmar solo los resultados que Shopify devolvió

Para una tarifa con 620 cambios, envía tres peticiones con 250, 250 y 120 precios. Si Shopify devuelve los IDs de 618 variantes, persiste esos 618 valores y deja dos fallidos con su artículo, tarifa y mensaje. La siguiente ejecución vuelve a comparar la fuente y reintenta los que siguen sin confirmar.

```text
Tarifa 12060: 620 cambiados · 620 enviados · 618 confirmados · 2 fallidos
```

### ❌ Bad: Avanzar SQLite antes de la respuesta

```php
$store->confirmPrice($jobId, $article, $tariff);
$shopify->graphql($mutation, $variables);
```

Si la petición falla, SQLite afirmaría que el precio está sincronizado y el siguiente sync omitiría el cambio. También es incorrecto contar `objectCount` de una operación masiva como si fueran precios confirmados o mezclar ese total con peticiones de 250.

## 🧐 Real world examples

- [Comparación, lotes, errores y confirmación](../../src/SyncPrices.php).
- [Persistencia de precios confirmados y cola diaria](../../src/StateStore.php).
- [Reintentos transitorios para GraphQL de sync](../../src/ShopifyClient.php).
- [Acción manual y próxima ejecución](../../public/index.php).
- [Encolado nocturno y exclusión durante la carga inicial](../../bin/worker.php).
- [Configuración de hora y cron](../../README.md).

## 🔗 Related agreements

- [Registrar incidencias por referencia](reference-error-handling.md).
- [Mostrar conteos comparables](progress-reporting.md).
- [Configuración privada y cron](../operations/private-configuration.md).
- [Precisión y fuente SQL](../database/tariff-price-source.md).

Sync notes kept tidy by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)’s mascot).
