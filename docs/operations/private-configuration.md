# 🎯 Configuración en el `.env` del proyecto

## 💡 Convention

En producción, guarda las credenciales en el `.env` de la raíz del proyecto, junto a `.env.example`. Tanto el panel como el worker leen ese mismo archivo y usan `var/` del proyecto para SQLite, archivos de trabajo y logs. `.env` y el contenido de `var/` están excluidos de Git. El `.htaccess` de la raíz bloquea el acceso web directo a ambos; si el servidor no aplica esas reglas, el document root debe apuntar a `public/` antes de publicar el sitio. `SHOPIFY_SHOP_DOMAIN` contiene solo el dominio `*.myshopify.com`, sin `https://` ni barra final.

Declara moneda y decimales para los diez códigos de `PRICE_TARIFF_COLUMNS`. La moneda debe coincidir con la de cada catálogo Shopify; con la consulta actual, la precisión local de los diez códigos es `3`. El worker corre por cron y bloquea ejecuciones simultáneas. Ejecutarlo cada minuto permite consultar con esa frecuencia las operaciones Shopify pendientes; el panel por sí solo solo lee el estado guardado.

`NIGHTLY_SYNC_TIME` define la hora local `HH:MM` para encolar el sync diario y por defecto usa `02:00`. Mantén el cron cada minuto para atender esa hora, acciones manuales y operaciones Shopify pendientes. El registro por fecha hace idempotente la creación del job diario.

El cron de producción ya ejecuta el worker cada minuto; la recuperación de búsquedas no requiere cambiar su frecuencia ni la configuración privada. Al adquirir el bloqueo, el worker recupera las etapas de búsqueda de sincronización con ID de operación y precios preparados. Consulta esa operación antes de reclamar acciones nuevas: manuales y nocturnas esperan en cola mientras siga pendiente o reintentándose. El panel solo consulta SQLite y no inicia esas continuaciones.

Si una consulta o descarga falla temporalmente, conserva SQLite y los archivos privados: el siguiente minuto vuelve a consultar el mismo ID y descarga de nuevo el resultado completo. No borres el estado ni repitas la carga inicial para descubrir SKU nuevos. Un fallo terminal deja constancia y permite sincronizar las referencias ya asociadas; las no resueltas se comprueban en la próxima sincronización. Revisa el motivo visible si `variants_retry` persiste. Interrupciones sin operación guardada o durante el envío directo se marcan fallidas; una nueva sincronización conserva los precios confirmados y reintenta los cambios pendientes.

## 🏆 Benefits

- El token permanece en el `.env` ignorado por Git y no aparece en el comando cron ni en la página.
- Panel y worker leen la misma configuración y el mismo SQLite de `var/`.
- El dominio y los mapas de tarifas tienen el formato que valida la aplicación.
- La hora de la última actividad permite distinguir una espera de Shopify de un cron detenido.

## 👀 Examples

### ✅ Good: Un solo `.env` en la raíz y formato esperado

```env
SHOPIFY_SHOP_DOMAIN=saraofactory.myshopify.com
SHOPIFY_ACCESS_TOKEN=<token_guardado_solo_en_el_env_privado>
TARIFF_DECIMAL_PLACES=12060:3,11961:3,112463:3,161:3,187:3,188:3,192:3,193:3,194:3,195:3
```

Completa `TARIFF_CURRENCIES` con los mismos diez códigos y la moneda real de cada catálogo. Guarda el archivo como `/home/ohyeah/public_html/tools/feedTarifas/.env`, limita sus permisos y programa `bin/worker.php` cada minuto sin una variable de ruta adicional.

### ❌ Bad: Configuraciones divergentes o credenciales públicas

```env
SHOPIFY_SHOP_DOMAIN=https://saraofactory.myshopify.com
TARIFF_DECIMAL_PLACES=12060:3,11961:3,112463:3,161:3,1876,188:3,192:3,193:3,194:3,195:3
```

La segunda línea carece de `:` en `187:3`. También es incorrecto poner un token real en `.env.example`, en archivos seguidos por Git o en el cron, o servir `.env` y `var/` por HTTP sin las reglas de acceso indicadas.

## 🧐 Real world examples

- [Plantilla de variables sin token real](../../.env.example).
- [Lectura y validación de `.env`](../../src/Config.php).
- [Comando y frecuencia del cron](../../README.md).

## 🔗 Related agreements

- [Fuente de precios con tres decimales](../database/tariff-price-source.md).
- [Progreso real de la carga](../backend/progress-reporting.md).
- [Sincronización incremental y reintentos](../backend/price-sync.md).

Doc created by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)’s mascot).
