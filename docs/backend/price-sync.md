# 🎯 Sincronizar únicamente los precios que cambiaron

## 💡 Convention

El sync vuelve a leer la consulta SQL y compara cada `(IdArtículo, tarifa, importe, moneda)` con `synced_prices`. Omite los valores iguales y envía los demás a la lista de precios resuelta para esa tarifa, en peticiones GraphQL de hasta 250 precios.

Un precio solo pasa a `synced_prices` cuando la respuesta de Shopify incluye el `variant.id` correspondiente. Los errores de referencia se guardan en `job_errors`, se muestran en el panel y no detienen otras referencias ni tarifas. Si un precio no queda confirmado, el valor anterior permanece en SQLite; una ejecución posterior vuelve a considerarlo mientras la fuente siga indicando el cambio.

El panel encola el sync manual mediante `POST /actions/sync`. El worker puede encolar un sync nocturno una vez por fecha local desde `NIGHTLY_SYNC_TIME` (por defecto `02:00`). Ejecuta el cron cada minuto para atender esa hora, las acciones manuales y las cargas Shopify pendientes. Un bloqueo de archivo impide workers simultáneos; si una carga inicial sigue activa, las acciones de sync esperan en cola.

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
