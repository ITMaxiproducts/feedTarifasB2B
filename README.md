# Validador de tarifas Shopify

Panel pequeño en PHP para validar las diez tarifas de SQL Server, iniciar una carga inicial y sincronizar cambios de precios en Shopify. El panel solo encola acciones; `bin/worker.php`, ejecutado por cron de cPanel, procesa la fuente, resuelve variantes por SKU, sigue las operaciones masivas y envía cambios incrementales. Cada resultado y error queda visible en el panel.

## Convenciones del proyecto

- [Fuente SQL y precisión de precios](docs/database/tariff-price-source.md).
- [Incidencias por referencia y continuidad de la carga](docs/backend/reference-error-handling.md).
- [Progreso del worker y del panel](docs/backend/progress-reporting.md).
- [Sincronización incremental y reintentos](docs/backend/price-sync.md).
- [Configuración privada y cron de producción](docs/operations/private-configuration.md).

## Despliegue en cPanel

1. Sube el proyecto a `/home/ohyeah/public_html/tools/feedTarifas`. El archivo `.htaccess` de esa carpeta sirve el contenido de `public/` y bloquea peticiones directas a `.env`, `src/`, `sql/`, `bin/` y `var/`. Si el hosting no permite `mod_rewrite` o `.htaccess`, configura el document root para que apunte directamente a `/home/ohyeah/public_html/tools/feedTarifas/public`.
2. Protege el panel con **Directory Privacy** de cPanel o las reglas de acceso de tu hosting. La aplicación aplica protección CSRF a la acción POST y no implementa cuentas de usuario.
3. Guarda las credenciales en `/home/ohyeah/public_html/tools/feedTarifas/.env`. Si aún no existe, copia `.env.example` de la raíz como `.env` y completa sus valores. Restringe sus permisos (`chmod 600 /home/ohyeah/public_html/tools/feedTarifas/.env`). El archivo está excluido de Git y el `.htaccess` de la raíz bloquea el acceso web directo. Si el hosting no aplica ese `.htaccess`, configura el document root para que apunte a `public/` antes de publicar el sitio. El usuario SQL Server solo necesita permisos de lectura.
4. Mantén `PRICE_TARIFF_COLUMNS` en el orden y con los aliases `12060`, `11961`, `112463`, `161`, `187`, `188`, `192`, `193`, `194` y `195`. Revisa que estén activos `PDO_SQLSRV` y `PDO_SQLITE` en el PHP de cPanel.
5. Completa `SHOPIFY_SHOP_DOMAIN` con el dominio `*.myshopify.com` sin `https://`, `SHOPIFY_ACCESS_TOKEN`, `TARIFF_CURRENCIES` según la moneda real de cada catálogo y `TARIFF_DECIMAL_PLACES` con `3` para los diez códigos. `NIGHTLY_SYNC_TIME` acepta una hora local `HH:MM` y por defecto es `02:00`. El token de Admin API se lee del `.env` privado; no lo pongas en el código, el comando cron ni el panel. La app necesita acceso para leer variantes y catálogos y escribir listas de precios. La consulta SQL redondea y convierte los precios a `DECIMAL(18,3)`; PHP acepta `.890` como `0.890` y no vuelve a redondear. Si un importe excede la precisión configurada, registra la incidencia y omite esa referencia. Hace lo mismo con referencias inválidas o SKU ausentes o ambiguos; las demás siguen. Si `IdArtículo` se repite, omite todas sus filas sin elegir un precio arbitrario.
6. Da al usuario de PHP web y CLI permisos de escritura en `/home/ohyeah/public_html/tools/feedTarifas/var`. Allí quedan SQLite, el bloqueo, JSONL de entrada, resultados Shopify y el log del worker. El `.htaccess` de la raíz bloquea el acceso web directo a `var/`.
7. Configura en **Cron Jobs** una ejecución cada minuto. El worker encola una sincronización diaria al alcanzar `NIGHTLY_SYNC_TIME`, una sola vez por fecha local, y también procesa acciones manuales y operaciones Shopify pendientes:

   ```cron
   * * * * * /usr/local/bin/php /home/ohyeah/public_html/tools/feedTarifas/bin/worker.php >> /home/ohyeah/public_html/tools/feedTarifas/var/worker.log 2>&1
   ```

   Usa el binario PHP CLI habilitado por el hosting. Habilita `PDO_SQLSRV`, `PDO_SQLITE` y `cURL`. El bloqueo evita que dos procesos trabajen al mismo tiempo. Cada sincronización compara los diez importes actuales con los últimos confirmados, agrupa cambios por tarifa y envía como máximo 250 precios por petición. El panel permite encolar la misma tarea con **Sincronizar ahora**. Los errores y precios sin confirmar quedan elegibles para el siguiente intento. El panel muestra la próxima hora nocturna; durante las operaciones de Shopify, la frecuencia real de actualización depende de este cron.

## Verificación local

Con PHP CLI disponible, ejecuta:

```sh
php bin/verify.php
```

El comando pasa `php -l` a los archivos PHP y comprueba las validaciones de fuente, precisión y estado sin conectar a SQL Server ni a Shopify.
=======
# feedTarifasB2B
Script para actualizar tarifas B2B SARAO
>>>>>>> 308ba5730c3131f3f3e90f4a938bd378bc28b7a6
