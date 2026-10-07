# feedTarifas — Gestión de tarifas B2B en Shopify

**feedTarifas conecta los precios de SQL Server con los catálogos B2B de Shopify.** Permite revisar los datos de origen, hacer una carga inicial de las diez tarifas y mantener sus precios actualizados mediante sincronizaciones manuales o diarias. Está pensado para el equipo que mantiene las tarifas y para quienes administran o desarrollan la integración.

El panel web muestra el avance, los resultados y las incidencias de cada acción. El procesamiento lo realiza un worker PHP en segundo plano, ejecutado por cron: pulsar un botón encola el trabajo y permite seguir su evolución sin mantener una petición web abierta.

## Contenido

- [Conceptos y alcance](#conceptos-y-alcance)
- [Funciones disponibles](#funciones-disponibles)
- [Guía de uso](#guía-de-uso)
- [Estados, progreso e incidencias](#estados-progreso-e-incidencias)
- [Cómo funciona la integración](#cómo-funciona-la-integración)
- [Requisitos y configuración](#requisitos-y-configuración)
- [Despliegue y ejecución](#despliegue-y-ejecución)
- [Resolución de problemas](#resolución-de-problemas)
- [Estructura y verificación](#estructura-y-verificación)
- [Documentación técnica](#documentación-técnica)

## Conceptos y alcance

| Concepto | Significado en este proyecto |
| --- | --- |
| Referencia o artículo | Identificador `IdArtículo` devuelto por SQL Server; se busca como SKU exacto de una variante Shopify. |
| Tarifa | Columna de precios de SQL Server que corresponde a un catálogo Shopify cuyo título coincide exactamente con su código. |
| Catálogo y lista de precios | El catálogo agrupa la oferta B2B; su lista de precios recibe los importes fijos de las variantes. |
| Acción o job | Ejecución de una validación, carga inicial o sincronización, con estado, contadores e incidencias propios. |
| Precio confirmado | Importe cuya aplicación ha confirmado Shopify y que se guarda localmente como base para futuras comparaciones. |

Las diez tarifas actuales son: **`12060`, `11961`, `112463`, `161`, `187`, `188`, `192`, `193`, `194` y `195`**. La consulta devuelve también `IdArtículo` y `Marca`. Cada referencia apta aporta un precio por tarifa.

La fuente de precios es [sql/prices.sql](sql/prices.sql). El proyecto lee SQL Server y escribe precios en Shopify. No crea productos ni variantes, no sincroniza existencias, pedidos o clientes y no crea los catálogos B2B: deben existir previamente. La carga inicial puede crear la lista de precios de un catálogo que todavía no tenga una.

La sincronización compara SQL Server con el último precio confirmado guardado en SQLite. No audita continuamente los precios actuales de Shopify: un cambio manual en Shopify puede pasar inadvertido si el precio de SQL Server sigue siendo igual al último confirmado localmente. Tampoco elimina precios por el mero hecho de que una referencia deje de aparecer en la consulta.

## Funciones disponibles

| Función | Qué hace | ¿Modifica Shopify? |
| --- | --- | --- |
| **Validar datos** | Lee la fuente SQL y comprueba columnas, referencias, duplicados y precios presentes, numéricos y no negativos. Guarda un resumen y las incidencias. | No. |
| **Iniciar carga inicial** | Prepara las diez tarifas, comprueba catálogos y monedas, relaciona referencias con variantes por SKU y carga los precios mediante operaciones masivas. | Sí; aplica precios y puede crear listas de precios. |
| **Sincronizar ahora** | Relee SQL, busca referencias sin variante asociada y envía los precios nuevos o distintos del último valor confirmado. | Sí. |
| **Sincronización diaria** | Encola automáticamente el mismo proceso incremental una vez por fecha, desde la hora configurada. | Sí. |
| **Seguimiento** | Muestra etapa, última actividad, resultados, catálogos y desglose por tarifa. | No. |
| **Descargar incidencias en Excel** | Exporta todas las incidencias guardadas de una acción a `.xlsx`. | No. |

**Validar datos comprueba la fuente SQL; no verifica los catálogos, las asociaciones SKU ni la precisión configurada para el envío.** Estas comprobaciones se realizan al preparar la carga o sincronización. Una validación correcta no garantiza que la publicación termine sin incidencias.

## Guía de uso

### Primera puesta en marcha

1. Un administrador debe completar la configuración, proteger el acceso al panel y activar el cron según las secciones de instalación de este documento.
2. Comprueba que Shopify tenga un único catálogo con el título exacto de cada código de tarifa, con la moneda prevista, y que los productos ya tengan sus SKU.
3. Abre el panel y pulsa **Validar datos**. La acción queda en cola hasta que el worker la atienda. Revisa el resultado y corrige las incidencias en el origen.
4. Pulsa **Iniciar carga inicial**. Esta acción escribe precios en Shopify. Primero prepara la fuente y comprueba los catálogos; después busca variantes y aplica los precios aptos.
5. Sigue el progreso y revisa los precios confirmados y las incidencias al terminar. El botón de carga inicial queda deshabilitado mientras haya otra carga inicial activa.
6. Una vez resueltas las listas de precios con la carga inicial, utiliza **Sincronizar ahora** para el mantenimiento habitual. Si alguna tarifa quedó sin lista resuelta, corrige el problema y completa la carga inicial antes de sincronizar.

### Uso habitual

- Los cambios de precio se hacen en la fuente SQL que alimenta la consulta. Pulsa **Sincronizar ahora** si necesitas aplicarlos antes de la siguiente ejecución diaria.
- Los SKU nuevos se buscan automáticamente durante la sincronización cuando no tienen una asociación local. No hace falta repetir la carga inicial para descubrirlos.
- Si una acción termina parcialmente, revisa sus incidencias, corrige la causa y ejecuta otra sincronización. Los precios que no quedaron confirmados vuelven a ser candidatos si la fuente sigue indicando el cambio.
- Puedes cerrar el navegador: el cron sigue procesando la cola. Al volver al panel verás el estado guardado.

La ejecución diaria se encola desde `NIGHTLY_SYNC_TIME` (por defecto `02:00`), según la zona horaria del PHP que ejecuta el worker. Si el cron se ejecuta más tarde, encola el trabajo en la primera ejecución de ese día que alcance o supere esa hora. Solo crea una acción diaria por fecha local; no recupera días completos sin ejecución. Configura la misma zona horaria en PHP web y CLI para que el horario mostrado y el ejecutado coincidan.

## Estados, progreso e incidencias

El panel muestra la última acción y, cuando es distinta, también la última carga inicial. No ofrece una vista navegable de todo el historial guardado en SQLite.

| Estado | Cómo interpretarlo |
| --- | --- |
| **Pendiente** | La acción está en cola, esperando al worker. |
| **En curso** | El worker está leyendo, preparando o procesando datos. |
| **Esperando a Shopify** | Hay una operación asíncrona pendiente o una búsqueda que se volverá a consultar. |
| **Completado** | La acción terminó sin incidencias registradas. |
| **Parcial** | La acción terminó con incidencias; revisa qué referencias o precios quedaron pendientes. |
| **Fallido** | La acción no pudo completarse. Revisa la causa; puede haber precios ya confirmados antes del fallo. |

El navegador consulta el estado cada cinco segundos. Las operaciones masivas se revisan cuando corre el worker; con cron cada minuto, sus novedades llegan aproximadamente con esa frecuencia. Tras diez minutos sin actividad, el panel invita a comprobar el cron.

Los contadores cambian según la etapa: filas SQL, bloques preparados, objetos procesados por Shopify o respuestas revisadas. Los objetos de Shopify no equivalen a bloques ni a precios confirmados, por lo que no siempre hay un porcentaje disponible.

En **Detalles técnicos** puedes consultar el identificador de operación Shopify, las fechas, los catálogos y listas resueltos y el progreso por tarifa. En sincronizaciones se distinguen precios sin cambios, a actualizar, enviados, confirmados, con error de envío o confirmación y omitidos sin variante. El resumen de referencias afectadas cuenta referencias únicas; los contadores por tarifa cuentan precios.

### Tratamiento de incidencias

- Una referencia con precios ausentes, inválidos o con demasiados decimales se omite con sus diez tarifas; las demás continúan.
- Si `IdArtículo` se repite en la fuente, se descartan todas sus apariciones, sin escoger un precio arbitrario.
- Solo se relaciona un SKU exacto con una única variante. Los SKU ausentes o ambiguos se registran y se omiten.
- Problemas globales, como un catálogo inexistente o duplicado, una moneda incompatible o una conexión fallida, pueden impedir completar la acción. Si no queda ninguna referencia apta para publicar, la carga o sincronización falla.
- Los importes se calculan en SQL con tres decimales (`DECIMAL(18,3)`). PHP normaliza valores como `.890` a `0.890`, sin aplicar un segundo redondeo. La precisión de los diez códigos debe estar configurada a `3`.

### Exportar incidencias a Excel

En **Incidencias por referencia y motivo**, pulsa **Descargar incidencias en Excel**. El panel muestra hasta 50 incidencias recientes agrupadas; el archivo incluye todas las de esa acción, con referencia, fila SQL, código, mensaje y repeticiones, además de su identificador, estado y fecha. Incluye filtros, cabecera fija e incidencias generales sin referencia. Si la acción sigue activa, refleja los datos guardados al consultar la descarga.

La exportación solo lee SQLite: no necesita consultar SQL Server ni Shopify. Genera un ZIP/XLSX estándar, con compresión si PHP tiene zlib, sin requerir `Phar` ni `ZipArchive`. El archivo temporal se guarda en almacenamiento privado y se elimina tras servirlo. La descarga debe tener la misma protección de acceso que el panel.

Para revisar una copia de producción, utiliza una instalación local separada y `SQLITE_PATH=var/copia-produccion.sqlite`, con su worker detenido. Obtén la copia mediante el backup de SQLite: no copies solo el `.sqlite` de una base activa en modo WAL, porque puede haber datos pendientes en `-wal`. Abre el panel local y descarga las incidencias de la acción visible. No reemplaces la base de producción con esa copia.

## Cómo funciona la integración

```text
Panel web → cola y estado en SQLite ← worker PHP ejecutado por cron
                                          ↓
                              Consulta de precios SQL Server
                                          ↓
                              Validación y asociación por SKU
                                          ↓
                              Listas de precios de Shopify
                                          ↓
                              Confirmaciones e incidencias en SQLite
```

La carga inicial usa operaciones masivas de Shopify y archivos JSONL. La sincronización incremental compara cada referencia, tarifa, importe y moneda con `synced_prices`, y agrupa los cambios por tarifa en peticiones GraphQL de hasta 250 precios. Un precio se registra como sincronizado solo cuando Shopify devuelve la confirmación de la variante correspondiente.

SQLite conserva la cola, incidencias, asociaciones de variantes, catálogos, precios preparados y últimos precios confirmados. Es parte del estado operativo de la integración; borrar la base hace perder esa información.

El worker usa un bloqueo de archivo para evitar procesos simultáneos. Mientras una operación Shopify sigue pendiente, las nuevas acciones esperan en cola. Las búsquedas de variantes de una sincronización con ID de operación y precios preparados pueden continuar después de una interrupción. Los fallos temporales de consulta o descarga conservan ese ID y se reintentan en el siguiente cron. Un fallo terminal deja las referencias no resueltas como inconcluyentes y permite continuar con las ya asociadas.

Esta recuperación no cubre todas las etapas: una interrupción sin ID persistido o durante el envío directo se marca como fallida. Una nueva sincronización conserva lo ya confirmado y vuelve a comparar los cambios pendientes. Las asociaciones positivas de SKU se conservan; la sincronización no comprueba automáticamente reasignaciones o eliminaciones de variantes ya asociadas.

## Requisitos y configuración

- PHP **8.1 o superior**, tanto para web como para CLI, con `PDO_SQLSRV`, `PDO_SQLITE` y `cURL`. El controlador de SQL Server necesita sus dependencias ODBC en el servidor.
- Acceso de lectura a SQL Server y conectividad HTTPS con Shopify.
- Una tienda Shopify con catálogos B2B y un token Admin API con permisos para leer variantes y catálogos y escribir listas de precios.
- Un servidor que sirva `public/` y un cron o programador equivalente que ejecute `bin/worker.php` cada minuto.
- Permisos de escritura de PHP web y CLI en `var/`, y acceso protegido al panel. La aplicación tiene protección CSRF, pero no implementa cuentas de usuario.

La aplicación carga directamente sus clases PHP; no requiere instalar paquetes con Composer ni compilar recursos con Node. El panel carga Bootstrap e iconos desde CDN, por lo que el navegador necesita acceso a esos recursos para mostrarlos correctamente.

### Archivo `.env`

Panel y worker leen el `.env` situado en la raíz del proyecto. Si tu instalación incluye `.env.example`, puedes copiarlo; si no, crea `.env` a partir de este ejemplo y sustituye los valores ficticios. Las monedas `EUR` son ilustrativas: deben coincidir con las de los catálogos reales.

```dotenv
SQLSRV_DSN="sqlsrv:Server=SERVIDOR_SQL;Database=BASE_DATOS"
SQLSRV_USERNAME=USUARIO_LECTURA
SQLSRV_PASSWORD=CONTRASENA
PRICE_TARIFF_COLUMNS=12060,11961,112463,161,187,188,192,193,194,195
SOURCE_SQL_PATH=sql/prices.sql
SQLITE_PATH=var/jobs.sqlite
SHOPIFY_SHOP_DOMAIN=tu-tienda.myshopify.com
SHOPIFY_ACCESS_TOKEN=TOKEN_ADMIN_API
TARIFF_CURRENCIES=12060:EUR,11961:EUR,112463:EUR,161:EUR,187:EUR,188:EUR,192:EUR,193:EUR,194:EUR,195:EUR
TARIFF_DECIMAL_PLACES=12060:3,11961:3,112463:3,161:3,187:3,188:3,192:3,193:3,194:3,195:3
NIGHTLY_SYNC_TIME=02:00
```

| Variable | Uso |
| --- | --- |
| `SQLSRV_DSN`, `SQLSRV_USERNAME`, `SQLSRV_PASSWORD` | Conexión de lectura a la fuente SQL Server. |
| `PRICE_TARIFF_COLUMNS` | Los diez aliases de tarifa, en el orden indicado. |
| `SOURCE_SQL_PATH` | Consulta fuente; por defecto `sql/prices.sql`. |
| `SQLITE_PATH` | Base de estado; por defecto `var/jobs.sqlite`. Las rutas relativas se resuelven desde la raíz del proyecto. |
| `SHOPIFY_SHOP_DOMAIN` | Dominio `*.myshopify.com`, sin `https://` ni barra final. |
| `SHOPIFY_ACCESS_TOKEN` | Token privado de Admin API. |
| `TARIFF_CURRENCIES` | Mapa `tarifa:moneda`, con una moneda explícita para cada código. |
| `TARIFF_DECIMAL_PLACES` | Mapa `tarifa:decimales`; con la consulta actual, `3` en todas. |
| `NIGHTLY_SYNC_TIME` | Hora diaria `HH:MM`; por defecto `02:00`. |

No guardes credenciales en Git, en el comando cron ni en el panel. `.env` y los datos generados en `var/` están excluidos de Git. La zona horaria se configura en PHP, no mediante una variable propia de la aplicación.

## Despliegue y ejecución

### Producción en cPanel

1. Sube el proyecto a `/home/ohyeah/public_html/tools/feedTarifas` o adapta las rutas a tu hosting. El `.htaccess` de la raíz sirve `public/` y bloquea el acceso web directo a `.env`, código y estado privado. Si el hosting no aplica esas reglas, configura el document root directamente en `public/` antes de publicar.
2. Protege el panel con **Directory Privacy** de cPanel o las reglas de acceso del hosting.
3. Crea el `.env` con los valores reales y restringe sus permisos, por ejemplo: `chmod 600 /home/ohyeah/public_html/tools/feedTarifas/.env`.
4. Comprueba las extensiones de PHP web y CLI, la zona horaria, el acceso SQL de lectura y los permisos de Shopify.
5. Da a PHP web y CLI permisos de escritura en `var/`. Allí se guardan SQLite, el bloqueo, archivos JSONL, resultados y el log del worker.
6. Configura **Cron Jobs** con una ejecución cada minuto:

   ```cron
   * * * * * /usr/local/bin/php /home/ohyeah/public_html/tools/feedTarifas/bin/worker.php >> /home/ohyeah/public_html/tools/feedTarifas/var/worker.log 2>&1
   ```

   Usa el binario PHP CLI de tu hosting y ajusta las rutas. Este cron atiende acciones manuales, encola el trabajo diario y consulta operaciones Shopify pendientes.
7. Abre la URL del panel, por ejemplo `https://TU_DOMINIO/tools/feedTarifas/`, y sigue la guía de primera puesta en marcha.

### Entorno local

Usa una configuración y una base SQLite separadas de producción. Para servir el panel con el servidor de desarrollo de PHP desde la raíz del proyecto:

```sh
php -S 127.0.0.1:8080 -t public public/index.php
```

Abre `http://127.0.0.1:8080/`. En otra terminal puedes ejecutar el worker:

```sh
php bin/worker.php
```

Una ejecución del worker puede dejar una operación esperando a Shopify; vuelve a ejecutarlo para continuar o configura un programador cada minuto. El worker también puede encolar la sincronización diaria al alcanzar la hora configurada. Una instalación local con credenciales de producción puede escribir precios reales: utiliza una tienda de pruebas para ensayar cargas y sincronizaciones.

## Resolución de problemas

| Síntoma | Qué revisar o hacer |
| --- | --- |
| La acción permanece pendiente o no hay actividad | Comprueba el cron, su binario PHP, los permisos de `var/` y `var/worker.log`. Otra operación Shopify puede estar reteniendo la cola. |
| La búsqueda está reintentándose (`variants_retry`) | Revisa el motivo visible y la conectividad. Conserva SQLite y los archivos privados para que el siguiente cron consulte la misma operación. |
| No hay lista de precios resuelta | Ejecuta o completa la carga inicial antes de sincronizar. |
| Catálogo inexistente, ambiguo o moneda incompatible | Comprueba el título exacto del catálogo, que sea único y que su moneda coincida con `.env`. |
| SKU ausente o ambiguo | Corrige el SKU en Shopify o la referencia de origen. Las referencias sin asociación se vuelven a buscar en futuras sincronizaciones. |
| Incidencia de precisión | Comprueba la consulta y `TARIFF_DECIMAL_PLACES`. La fuente actual entrega tres decimales; PHP no redondea los importes para forzar su aceptación. |
| Carga parcial o precios no confirmados | Descarga las incidencias, corrige la causa y sincroniza de nuevo. Revisa confirmados frente a enviados. |
| Falta `.env` o falla la conexión | Revisa las variables, extensiones, credenciales y conectividad del PHP que ejecuta la acción. |

## Estructura y verificación

| Ruta | Responsabilidad |
| --- | --- |
| `public/` | Panel, estilos, acciones web, estado y descarga de incidencias. |
| `bin/worker.php` | Procesamiento de la cola, programación diaria y continuación de operaciones Shopify. |
| `sql/prices.sql` | Consulta de origen y cálculo de las diez tarifas. |
| `src/Config.php`, `src/bootstrap.php` | Lectura de configuración e inicialización. |
| `src/SourceReader.php`, `src/PreviewValidator.php` | Lectura y validación de SQL Server. |
| `src/InitialLoad.php`, `src/SyncPrices.php` | Carga inicial y sincronización incremental. |
| `src/ShopifyClient.php`, `src/VariantResolver.php` | Cliente GraphQL, operaciones masivas y resolución de variantes. |
| `src/StateStore.php` | Persistencia SQLite, cola, asociaciones, incidencias y confirmaciones. |
| `src/ProgressPresenter.php`, `src/IncidentExport.php` | Presentación del progreso y generación de Excel. |
| `bin/verify.php` y verificadores auxiliares | Comprobaciones locales del comportamiento y la sintaxis PHP. |
| `var/` | Estado y archivos de ejecución privados, excluidos de Git. |
| `docs/` | Convenciones de desarrollo y operación. |

Con PHP CLI disponible, ejecuta desde la raíz:

```sh
php bin/verify.php
```

El comando verifica la sintaxis PHP y comprueba validaciones, precisión, estado, sincronización de variantes y exportación mediante pruebas locales, sin conectarse a SQL Server ni Shopify. No sustituye una validación de conectividad y permisos en el entorno de destino.

## Documentación técnica

Antes de modificar el comportamiento de la carga o sincronización, consulta las convenciones aplicables:

- [Fuente SQL y precisión de precios](docs/database/tariff-price-source.md).
- [Incidencias por referencia y continuidad de la carga](docs/backend/reference-error-handling.md).
- [Progreso del worker y del panel](docs/backend/progress-reporting.md).
- [Sincronización incremental y reintentos](docs/backend/price-sync.md).
- [Configuración privada y cron de producción](docs/operations/private-configuration.md).
