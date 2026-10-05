# 🎯 Fuente de precios con tres decimales

## 💡 Convention

La consulta de `sql/prices.sql` devuelve `IdArtículo`, `Marca` y exactamente las diez tarifas `12060`, `11961`, `112463`, `161`, `187`, `188`, `192`, `193`, `194` y `195`. Mantén la consulta acordada: las dos ramas de `PRECIOS_BASE` calculan `[12060]` con `CONVERT(DECIMAL(18,3), ROUND(..., 3))`; las otras nueve tarifas se calculan desde `[12060]` y se convierten a `DECIMAL(18,3)`.

En producción, `TARIFF_DECIMAL_PLACES` debe indicar `3` para esos diez códigos mientras esta sea la consulta fuente. El SQL redondea a tres decimales. `InitialLoad::normalizeAmount()` no vuelve a redondear: acepta los decimales configurados, elimina ceros sobrantes y normaliza valores como `.890` a `0.890`. Si un valor tiene más decimales significativos, se registra `price_precision` y se omite su referencia.

La acción **Validar datos** comprueba que los importes sean numéricos y no negativos, pero la precisión de `TARIFF_DECIMAL_PLACES` se comprueba al preparar la carga inicial. Una validación previa correcta no garantiza que no aparezca `price_precision` durante la carga.

## 🏆 Benefits

- El tipo que recibe PHP tiene una escala explícita y estable.
- Los importes inferiores a uno no se rechazan por venir sin cero inicial.
- El origen de cualquier redondeo queda claro y se evita inventar una segunda regla en PHP.

## 👀 Examples

### ✅ Good: Conversión explícita en SQL y precisión coherente

```sql
CONVERT(DECIMAL(18,3), ROUND(SP.PRI_0 * 1.1, 3)) AS [12060]
CONVERT(DECIMAL(18,3), [12060] * 1.10) AS [11961]
```

```env
TARIFF_DECIMAL_PLACES=12060:3,11961:3,112463:3,161:3,187:3,188:3,192:3,193:3,194:3,195:3
```

`normalizeAmount('.890', 3)` produce `0.890`.

### ❌ Bad: Escala implícita o rechazo del cero omitido

```sql
ROUND(SP.PRI_0 * 1.1, 3) AS [12060]
```

La expresión anterior puede conservar el tipo y la escala de origen. También es incorrecto rechazar `.890` como si tuviera demasiados decimales o cambiar la consulta acordada por otra conversión sin revisar su efecto en los precios.

## 🧐 Real world examples

- [Consulta fuente](../../sql/prices.sql).
- [Normalización e incidencia de precisión](../../src/InitialLoad.php).
- [Comprobación de `.890` y de precisión](../../bin/verify.php).

## 🔗 Related agreements

- [Omitir referencias con incidencias](../backend/reference-error-handling.md).
- [Configuración privada del worker](../operations/private-configuration.md).

Doc created by 🐢 💨 (Turbotuga™, [Codely](https://codely.com)’s mascot).
