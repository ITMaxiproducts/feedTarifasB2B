# 🎯 Mostrar solo el progreso que se puede medir

## 💡 Convention

El worker guarda la etapa, los contadores disponibles y `progress_at` en SQLite. Mientras lee SQL Server, informa filas leídas; al preparar el JSONL, bloques preparados; y al revisar resultados, respuestas revisadas. El panel consulta `GET /status?id=<acción>` cada cinco segundos sin recargar toda la página y muestra la última actividad del worker. Si no hay novedades durante diez minutos, invita a comprobar el cron.

Las operaciones masivas de Shopify son asíncronas. Su `objectCount` cuenta objetos procesados y **no** equivale al número de bloques JSONL enviados: no lo presentes como `X / N bloques` ni calcules un porcentaje mezclando ambas cifras. El worker consulta Shopify cuando lo ejecuta el cron; un cron cada minuto da una actualización aproximada cada minuto, aunque el navegador consulte SQLite más a menudo.

Al terminar, muestra precios confirmados frente a preparados. Si están disponibles, muestra además bloques con respuesta frente a bloques enviados. Para cargas antiguas sin ese último dato, usa los precios confirmados; no dejes visible el contador de una etapa anterior.

Para las sincronizaciones directas, muestra por tarifa los precios cambiados, enviados, confirmados y fallidos. Estos son conteos de precios; el progreso general de la etapa puede contar peticiones de hasta 250 precios y no debe compararse con el total de precios.

El panel separa los precios **sin cambios**, **a actualizar**, **enviados**, **confirmados**, **con error de envío o confirmación** y **omitidos sin variante**. «A actualizar» incluye precios nuevos y modificados de referencias con una variante asociada. Los `variant_unmapped` no son envíos fallidos. El resumen cuenta referencias únicas con incidencias; la lista agrupa por fila, referencia, código y mensaje antes de limitarse a las 50 incidencias recientes. Las incidencias globales siguen visibles aunque no tengan referencia.

Para operaciones existentes, `StateStore::hydrateJob()` adapta los contadores al leer el estado usando `job_prices` y las incidencias `variant_unmapped` del mismo job. No modifica el resumen ni los registros originales en SQLite. Tanto la página como `/status` usan esa presentación. Los precios sin cambios se calculan a partir de los preparados menos los candidatos originales; no incluyen referencias descartadas por validación SQL.

## 🏆 Benefits

- El usuario ve actividad real sin interpretar `0 / 160` como una carga bloqueada.
- Los contadores y porcentajes conservan unidades comparables.
- El estado final refleja los precios confirmados y las incidencias registradas.

## 👀 Examples

### ✅ Good: Etapa y evidencia de avance

```text
Etapa: Aplicando precios en Shopify
Progreso: Shopify está aplicando los precios
Detalle: 160 bloques enviados. El contador de objetos de Shopify no equivale a bloques.
Última actividad: Hace 42 s
```

Al finalizar, por ejemplo: `8 / 10 precios confirmados por Shopify` y, si el resultado lo permite, `2 / 2 bloques con respuesta de Shopify`.

### ❌ Bad: Mezclar métricas o mantener una etapa terminada

```text
Estado: Parcial
Etapa: Aplicando precios en Shopify
Progreso: 0 / 160 bloques
```

El `0` anterior procede de `objectCount` y el `160` del JSONL; no forman una fracción válida.

## 🧐 Real world examples

- [Etapas y avances del worker](../../src/InitialLoad.php).
- [Estado persistido y fecha de actividad](../../src/StateStore.php).
- [Texto correcto para cada etapa](../../src/ProgressPresenter.php).
- [Endpoint y actualización del panel](../../public/index.php).

## 🔗 Related agreements

- [Configuración privada y cron](../operations/private-configuration.md).
- [Omitir referencias con incidencias](reference-error-handling.md).
- [Sincronización incremental y reintentos](price-sync.md).
- [Definición de `BulkOperation.objectCount` en Shopify](https://shopify.dev/docs/api/admin-graphql/latest/objects/bulkoperation).

Doc created by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)’s mascot).
