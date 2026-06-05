# Diseño — Fugas Financieras del Core (cuota dura + fallback sin descuadre)

> Estado: Aprobado para implementación
> Fecha: 2026-06-04
> Alcance: camino de chat (`ChatPipeline`) — integridad de tokens / cuota.

---

## 1. Contexto y objetivo

El camino de chat ya corre en runtime real (validado en el smoke-test). Pero la auditoría inicial detectó **4 fugas financieras** en el conteo de tokens/cuota, todas confirmadas contra el código actual. Esta tarea las cierra para que la cuota sea un **límite duro, race-safe y sin cobros erróneos**.

### Fugas confirmadas (código actual)

1. **Cuota = límite blando.** `TenantManager::validateForChat` (`:103`) chequea `quota_used >= quota_limit` con el valor *previo*, y `incrementQuota` (`:117`) descuenta *después* del LLM (`ChatPipeline` paso 8). Un tenant a 49.999/50.000 puede gastar 2.000 más y terminar en 51.999.
2. **Race condition.** N requests concurrentes leen la cuota por debajo del límite antes de que ninguno sume → la sobrepasan proporcional a la concurrencia. El `UPDATE ... quota_used = quota_used + N` es atómico, pero el *check* no lo es respecto del increment.
3. **Fallback re-emite y descuadra.** En `LLMRouter::stream`, el mismo `$onChunk` se pasa al intento primario, a los reintentos y al fallback. Si el primario falla a mitad de stream (tras emitir deltas), el fallback re-emite con el mismo callback → texto duplicado en `$fullResponse` + los tokens del intento fallido no se contabilizan.
4. **Cobro $0.** Si el evento `usage` del proveedor no llega, `StreamResult` da 0 tokens → `incrementQuota` retorna sin cobrar (`TenantManager:118`) → respuesta gratis.

### Decisión de diseño tomada

- **Fuga 3 → streaming en vivo** (commit-on-first-delta): se preserva el streaming token-por-token del widget web. Si el primario falla **después** de emitir, se corta con error y NO se hace fallback (no se puede des-emitir). El fallback sigue cubriendo los fallos previos a la emisión (auth, rate-limit, conexión, 5xx inmediato), que son los más comunes.
- **Cuota → Enfoque A (reservar y reconciliar):** UPDATE atómico-condicional antes del LLM, reconciliación a tokens reales después. Límite duro y race-safe sin locks explícitos.

---

## 2. Componentes

### `TokenEstimator` (nuevo — `Infouno\SaaS\LLM\TokenEstimator`)

Heurística de estimación de tokens, pura y testeable.

```php
public static function estimate( string $text ): int;                  // max(1, ceil(mb_strlen / 4))
public static function estimateMessages( array $messages ): int;        // suma de estimate(content) de cada mensaje
```

- `chars/4` es la heurística estándar para texto latino; conservadora (redondea hacia arriba), apropiada para una reserva que debe cubrir el costo real.
- Sin dependencias externas (no tokenizer real). Suficiente para reservar y para el fallback de la fuga 4.

### `TenantManager` (modificado)

Reemplaza el modelo "post-cobro" por "reservar y reconciliar":

```php
// Reserva atómica-condicional. true si reservó; false si no entra (cuota agotada).
public function reserve( int $tenantId, int $estimate ): bool;

// Ajusta la reserva al consumo real: quota_used = quota_used - reserved + actual. Luego alerta 90%.
public function reconcile( int $tenantId, int $reserved, int $actual ): void;

// Devuelve una reserva no usada (request fallido). quota_used = GREATEST(0, quota_used - reserved).
public function release( int $tenantId, int $reserved ): void;
```

**`reserve` (SQL atómico — race-safe):**
```sql
UPDATE wp_infouno_tenants
   SET quota_used = quota_used + :estimate
 WHERE id = :id
   AND status = 'active'
   AND quota_used + :estimate <= quota_limit;
-- reserved = ($wpdb->rows_affected === 1)
```
Si afecta 1 fila → reservado. Si 0 → no entra (cuota agotada o inactivo) → el caller lanza 402. La condición `quota_used + :estimate <= quota_limit` evaluada dentro del UPDATE es atómica en MySQL → sin race.

**`reconcile`:** `UPDATE ... SET quota_used = GREATEST(0, quota_used - :reserved + :actual) WHERE id = :id`, luego el chequeo de alerta 90% (igual que hoy). `GREATEST(0, ...)` protege contra cualquier descuadre que llevara a negativo.

**`validateForChat`:** se mantiene para estado del tenant + plan (devuelve el array del tenant). Su chequeo de cuota (`:103`) queda como *early-reject barato* (ya agotada antes de esta request); el guardrail real pasa a ser `reserve`.

**`incrementQuota`:** deja de usarse en el camino de chat (lo reemplazan reserve/reconcile). Se conserva el método por si hay otros callers; si no los hay, se elimina (verificar con grep en la implementación).

### `LLMRouter::stream` (modificado — commit-on-first-delta)

```
emitted = false
wrappedOnChunk = fn(delta) => { emitted = true; onChunk(delta) }

for attempt in 0..MAX_RETRIES:
    try: return provider.streamChat(messages, options, wrappedOnChunk)
    catch RuntimeException e:
        if emitted: throw e            # ya se emitió → no re-emitir, propagar
        if not isRetryable(e.code): break

# fallback (un intento) SOLO si no se emitió nada todavía
if not emitted and fallback exists:
    do_action('infouno_model_fallback', ...)
    try: return fallback.streamChat(messages, options, wrappedOnChunk)
    catch RuntimeException e: lastException = e

throw RuntimeException('Todos los proveedores...', 503)
```

- El streaming en vivo se preserva: `wrappedOnChunk` reenvía cada delta al `onChunk` real inmediatamente.
- Una vez emitido el primer delta, ningún reintento/fallback puede ejecutarse → cero doble-emisión y cero doble-cobro.
- El `StreamResult` devuelto es el del único intento que produjo salida.

### `ChatPipeline::run` (modificado — reservar/reconciliar)

Nuevo orden de las etapas financieras (el resto igual):

```
1. InputGuard
2. validateForChat (estado + plan)
3. checkRateLimit
4. getOrCreate
5. techo de tokens por conversación (402)
6. history + buildMessages
7. estimate = TokenEstimator::estimateMessages(messages) + max_tokens
   reserve(tenantId, estimate)  ── false → throw RuntimeException('Cuota mensual agotada.', 402)
8. increment rate-limit
9. try {
       result = llmRouter.stream(...)   // acumula $fullResponse, commit-on-first-delta
       sink.finish()
       actual = result.totalTokens()
       if (actual === 0 && trim($fullResponse) !== '')      // fuga 4: usage no llegó pero hubo texto
           actual = TokenEstimator::estimateMessages(messages) + TokenEstimator::estimate($fullResponse)
       reconcile(tenantId, estimate, actual)
   } catch (\Throwable e) {
       release(tenantId, estimate)        // request fallido NO cobra
       throw e
   }
10. saveExchange
11. leadService (best-effort, sin cambios)
```

- `max_tokens` se lee igual que en `LLMRouter` (`settings.max_tokens`, default 1024) para que la reserva cubra el peor caso.
- `reconcile` corre en éxito; `release` en cualquier excepción (incluye 503 de LLM agotado → el request fallido no descuenta cuota). La cuota neta tras reconcile refleja el consumo real.

---

## 3. Manejo de errores

| Situación | Comportamiento |
|---|---|
| Cuota no alcanza para la reserva | `reserve` → false → `RuntimeException('Cuota mensual agotada.', 402)` antes de llamar al LLM |
| LLM falla (todos los proveedores) | `release` devuelve la reserva → request no cobra → propaga 503 (el dispatcher de canal reintenta; el web muestra error) |
| Primario falla a mitad de stream | `LLMRouter` re-lanza sin fallback → `release` → el usuario vio texto parcial + error; no se duplica ni se cobra de más |
| `usage` no llega pero hubo texto | `actual` se estima del texto → se cobra el estimado (no $0) |
| `actual` > `estimate` (estimación baja) | `reconcile` con `GREATEST(0,...)`; el exceso es acotado (output capado por `max_tokens`, prompt estimado conservador) |

---

## 4. Testing

- **`TokenEstimator`** (unit): estimate de strings vacíos/cortos/largos; estimateMessages suma; redondeo hacia arriba; mínimo 1.
- **`TenantManager`** (unit con WpdbStub): `reserve` construye el UPDATE condicional correcto y devuelve true/false según `rows_affected`; `reconcile` arma `- reserved + actual`; `release` no baja de 0.
- **`LLMRouter`** (unit): mock provider que emite 1 delta y luego lanza → el router NO reintenta ni hace fallback (re-lanza); mock que falla ANTES de emitir → sí hace fallback. Verifica cero doble-emisión.
- **`ChatPipeline`** (unit): reserve se llama con `estimateMessages + max_tokens` ANTES del LLM; en éxito llama `reconcile(estimate, real)`; en excepción del LLM llama `release(estimate)` y propaga; fuga 4: result 0 tokens + texto → reconcile con estimado > 0.
- **Regresión:** suite completa verde (web sigue equivalente, streaming intacto).

---

## 5. Fuera de alcance (explícito)

- **Atomicidad del rate-limit por transients** (capa anti-abuso por minuto, no de plata directa): requiere INCR atómico de Redis, que el proyecto no tiene aún. Se documenta como limitación conocida; no se toca en esta tarea.
- **Tokenizer real** (tiktoken/equivalente): la heurística chars/4 es suficiente para reservar/estimar; un tokenizer exacto es optimización futura.
- **Reserva por conversación / por bot:** la reserva es por tenant (la unidad de cuota). Sin cambios al techo por conversación (sigue en su paso).

---

## 6. Guardrails del proyecto respetados

- **Aislamiento multitenant:** todas las queries nuevas filtran por `id` del tenant (derivado del servidor, nunca del request).
- **Fuente de verdad financiera única:** `PLAN_QUOTAS` y la lógica de cuota siguen centralizadas en `TenantManager`.
- **Sin DROP / sin cambios de schema:** esta tarea no toca la BD (usa columnas existentes `quota_used`/`quota_limit`).
- **Best-effort del Lead Engine** preservado.
