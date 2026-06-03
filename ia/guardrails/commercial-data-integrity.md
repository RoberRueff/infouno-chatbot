# Guardrail: Integridad de Datos Comerciales

> Protege los datos que forman la base del valor comercial de la plataforma:
> scores de leads, oportunidades, revenue, tokens consumidos.
> Un dato comercial corrupto destruye la confianza del tenant y la atribución de ROI.

---

## Activos de Datos Comerciales Protegidos

| Dato | Tabla | Criticidad | Por qué importa |
|------|-------|-----------|-----------------|
| `tokens_used` en mensajes | `wp_infouno_messages` | 🔴 Máxima | Base de la facturación y del ROI |
| `score` en leads | `wp_infouno_leads` | 🟠 Alta | Determina calificación y notificaciones |
| `status` en leads | `wp_infouno_leads` | 🟠 Alta | Refleja el pipeline real del tenant |
| `quota_used` en tenants | `wp_infouno_tenants` | 🔴 Máxima | Controla el límite de facturación |
| `estimated_value` en opportunities | `wp_infouno_opportunities` *(v7)* | 🟠 Alta | Base del revenue attribution |
| `stage` en opportunities | `wp_infouno_opportunities` *(v7)* | 🟠 Alta | Pipeline comercial del tenant |

---

## Reglas de Protección de Tokens

### R1 — `tokens_used` NUNCA decrece

El campo `tokens_used` en `wp_infouno_messages` es de solo incremento. Nunca actualizar su valor a uno menor o a cero.

```php
// Correcto ✅ — setear una sola vez al guardar
$wpdb->insert(..., ['tokens_used' => $result->totalTokens(), ...]);

// Incorrecto ❌ — no corregir ni resetear
$wpdb->update('wp_infouno_messages', ['tokens_used' => 0], ['id' => $msgId]);
```

### R2 — `quota_used` se incrementa con tokens reales, nunca estimados

`TenantManager::incrementQuota()` recibe el resultado real del LLM (`$result->totalTokens()`), no estimaciones pre-cálculo.

### R3 — `tokens_used > 0` implica mensaje no borrable físicamente

Ver `ia/guardrails/legal-copliance.md` — regla 1. Aquí se refuerza desde la perspectiva financiera: sin tokens = sin auditoría = sin facturación confiable.

### R4 — Registro de tokens SIEMPRE post-stream, nunca pre-stream

Los tokens se registran después de recibir la respuesta completa del LLM, cuando el count es exacto. Nunca estimar y ajustar después.

---

## Reglas de Protección de Lead Score

### R5 — El score solo sube, nunca baja

`LeadRepository::save()` implementa lógica de upsert que actualiza el score SOLO si el nuevo es mayor al almacenado. Un mensaje menos entusiasta después de uno de alta intención no debe degradar el score.

```sql
-- Correcto ✅
UPDATE ... SET score = GREATEST(score, %d) WHERE session_hash = %s
-- O verificación en PHP antes de update
```

### R6 — Score calculado exclusivamente por `LeadScorer`

El score no puede ser seteado arbitrariamente desde endpoints REST o desde el panel admin. Solo `LeadScorer::analyze()` produce scores. El panel admin puede cambiar el `status`, nunca el `score` directamente.

### R7 — Score inmutable post-calificación para notificaciones

El hook `infouno_lead_captured` se dispara cuando `score >= 60`. La notificación incluye el score en ese momento. Si el score sube después, no re-disparar la notificación para el mismo `lead_id` (anti-spam transient).

---

## Reglas de Integridad del Pipeline de Status

### R8 — Transiciones de status válidas

```
LEADS:
  new → contacted | lost
  contacted → interested | lost
  interested → converted | lost
  converted → (terminal — solo corrección manual)
  lost → (terminal — solo corrección manual)

OPPORTUNITIES (v7):
  new → contacted | lost
  contacted → interested | lost
  interested → quoted | lost
  quoted → won | lost
  won → (terminal)
  lost → (terminal)
```

### R9 — Timestamps de transición son inmutables post-seteo

`contacted_at` y `converted_at` en leads se setean una sola vez. Si ya tienen valor, no sobrescribir.

### R10 — Status `won` dispara Revenue Attribution

Cuando una oportunidad (v7) cambia a `won`, debe disparar `do_action('infouno_deal_won', ...)` para que el Revenue Attribution Engine registre el ingreso. No omitir este hook.

---

## Reglas de Integridad de Cuotas

### R11 — `quota_used` nunca puede ser negativo

Todas las operaciones de incremento de quota son aditivas (`quota_used + N`). Verificar que `N >= 0` antes de la query.

### R12 — Reset de cuota SOLO vía `resetExpiredQuotas()`

El cron `infouno_reset_monthly_quotas` es el único mecanismo autorizado para setear `quota_used = 0`. No resetear manualmente desde ningún otro componente.

### R13 — `quota_reset_at` avanza siempre en +30 días

Al resetear, `quota_reset_at = quota_reset_at + INTERVAL 30 DAY`. Nunca calcular como `NOW() + 30 días` (evita drift acumulativo en clientes con consumo variable).

---

## Reglas de Consistency en Reportes

### R14 — Métricas calculadas desde la fuente, nunca desde caché

Los dashboards de stats (total leads, calificados, convertidos) se calculan en tiempo real desde la BD, nunca desde un cache que pueda estar desactualizado.

### R15 — Costo por token calculado desde tokens reales

El costo de IA se calcula como `tokens_used * precio_modelo`. Nunca usar estimaciones de tokens de la request del usuario.

---

## ⚠️ Alerta de Violación

Si la IA detecta:
- Decremento de `tokens_used` o `score`
- Reset de `quota_used` fuera del cron autorizado
- Cambio de `status` sin validación de transición
- Score modificado desde un endpoint REST sin pasar por `LeadScorer`

Debe reportar: `[GUARDRAIL TRIGGERED: VIOLACIÓN DE INTEGRIDAD COMERCIAL — {campo} en {tabla}]`
y detener el cambio hasta revisar el impacto en la facturación y el pipeline del tenant.
