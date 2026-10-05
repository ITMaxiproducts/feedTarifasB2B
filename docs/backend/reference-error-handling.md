# 🎯 Registrar y omitir referencias con incidencias

## 💡 Convention

Una incidencia propia de una referencia no detiene toda la carga. Registra la fila, el `IdArtículo`, el código y el mensaje; omite los diez precios de esa referencia y continúa con las demás. Esto se aplica a precios ausentes o inválidos, precisión excedida y SKU ausente o ambiguo en Shopify.

Si un `IdArtículo` aparece varias veces en la consulta, omite **todas** sus filas: no elijas la primera, la última ni un precio agregado. Las incidencias de configuración global, como un catálogo ambiguo o una moneda incompatible, impiden continuar con la operación; si no queda ninguna referencia apta, la carga termina como fallida. Una carga que confirma precios pero conserva incidencias termina como `partial`.

## 🏆 Benefits

- Una referencia defectuosa no retrasa las demás.
- Los precios ambiguos no se publican de forma arbitraria.
- El panel conserva un rastro de las referencias omitidas y distingue una carga parcial de una fallida.

## 👀 Examples

### ✅ Good: Omitir una referencia y seguir

Si un artículo `SKU-123` trae `1.2345` con precisión configurada a tres decimales, se registra `price_precision`, no se preparan sus tarifas y la fila siguiente continúa. Si `1567RCAJA` aparece varias veces, se retiran también los precios de sus apariciones previas.

### ❌ Bad: Bloquear o escoger un precio ambiguo

```php
throw new RuntimeException('Un precio inválido cancela toda la carga');
```

También es incorrecto conservar el primer precio de un `IdArtículo` duplicado o enviar solo algunas de sus diez tarifas.

## 🧐 Real world examples

- [Validación de la fuente y duplicados](../../src/PreviewValidator.php).
- [Preparación de precios y descarte de SKU](../../src/InitialLoad.php).
- [Persistencia de incidencias y precios preparados](../../src/StateStore.php).

## 🔗 Related agreements

- [Fuente de precios con tres decimales](../database/tariff-price-source.md).
- [Progreso real de la carga](progress-reporting.md).
- [Sincronización incremental y reintentos](price-sync.md).

Doc created by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)’s mascot).
