# Guardrail: Protección de PII en el Lead Engine

> Este guardrail se activa específicamente cuando se trabaja en el Lead Engine o en cualquier componente que maneje PII (Personally Identifiable Information) de usuarios finales.
> Complementa `ia/guardrails/legal-copliance.md` con reglas técnicas específicas del Lead Engine.

---

## Definición de PII en este contexto

Son datos de identificación personal del usuario final del chatbot:

| Campo | Clase de PII | Tabla |
|-------|-------------|-------|
| `name` | PII directa | `wp_infouno_leads` |
| `phone` | PII directa + sensible | `wp_infouno_leads` |
| `email` | PII directa | `wp_infouno_leads` |
| `session_id` | PII indirecta (vincula a conversación) | En memoria / sessionStorage |
| `session_hash` | SHA-256 de session_id — pseudonimizado | `wp_infouno_leads`, `wp_infouno_lead_consents` |
| `ip_hash` | SHA-256 de IP — pseudonimizado | `wp_infouno_consents`, `wp_infouno_lead_consents` |

---

## Reglas de Flujo de PII

### R1 — Consent Gate: Sin consentimiento, sin PII

`LeadService::processMessage()` SIEMPRE consulta `wp_infouno_lead_consents` como primera acción.
Si el resultado está vacío o todos los flags son `0`, retornar inmediatamente.

```php
// Correcto ✅
$consents = $this->getConsents($sessionId, $botId);
if (empty($consents)) {
    return; // Sin consent → sin PII → sin scoring
}

// Incorrecto ❌
$result = $this->scorer->analyze($message); // Analizar antes de verificar consent
```

### R2 — Filtrado post-scoring: No persistir PII no consentida

El `LeadScorer` detecta PII en el texto sin restricciones (es un extractor). El filtro opera en `LeadService` antes de persistir:

```php
// Correcto ✅
if ($extracted['name'] && !empty($consents['can_capture_name'])) {
    $toSave['name'] = $extracted['name'];
}

// Incorrecto ❌
$toSave['name'] = $extracted['name']; // Sin verificar consent granular
```

### R3 — `session_id` nunca en la BD de leads

El `session_id` crudo se hashea con SHA-256 antes de almacenar.

```php
// Correcto ✅
'session_hash' => hash('sha256', $sessionId)

// Incorrecto ❌
'session_id' => $sessionId  // PII directa en la BD
```

### R4 — PII en logs: prohibición total

Nunca incluir `name`, `phone`, `email`, `session_id` o `IP` en ningún log de error, debug o auditoría.

```php
// Correcto ✅
error_log('[LeadService] Error en lead_id=' . $leadId . ' tenant_id=' . $tenantId);

// Incorrecto ❌
error_log('[LeadService] Error para email=' . $email . ' phone=' . $phone);
```

### R5 — PII en respuestas del LLM: nunca en el system prompt

El `system_prompt` del bot no debe incluir datos personales de usuarios anteriores. Cada conversación es independiente.

```php
// Incorrecto ❌ — NO hacer esto
$systemPrompt .= "El cliente anterior se llamaba Juan y su email era juan@mail.com";
```

---

## Reglas de Almacenamiento de PII

### R6 — Columnas de PII solo en `wp_infouno_leads`

La PII directa (name, phone, email) SOLO se almacena en `wp_infouno_leads`. No debe aparecer en otras tablas del plugin.

### R7 — `wp_infouno_lead_consents` no contiene PII directa

Esta tabla almacena evidencia legal (hashes + timestamps), no datos personales identificables.

### R8 — Upsert por session_hash, no por email/phone

El identificador de upsert en `LeadRepository` es `session_hash` (anónimo). Nunca usar `email` o `phone` como clave de upsert (evita correlación entre sesiones anónimas).

### R9 — No indexar campos PII

No crear índices sobre `name`, `email` o `phone` en `wp_infouno_leads`. Estas columnas no se usan para filtrado de alto rendimiento y los índices podrían usarse en queries globales no autorizadas.

---

## Reglas de Transmisión de PII

### R10 — PII no sale en respuestas SSE

Los chunks SSE del chat endpoint nunca deben incluir PII. Si el bot cometió el error de responder con PII de otro usuario (alucinación), el `LeadScorer` no debe propagar eso.

### R11 — PII en emails de notificación: solo para el tenant correspondiente

`Plugin::onLeadCaptured()` envía name/email/phone al tenant. Verificar que el destinatario del email es el `user_id` propietario del `tenant_id` del lead.

### R12 — Respuesta del endpoint `/leads` excluye `session_hash`

El `session_hash` es evidencia legal interna. No incluirlo en respuestas REST del `LeadController`.

---

## Derechos del Usuario que deben ser Soportados

| Derecho (Ley 25.326) | Endpoint | Implementación |
|-----------------------|----------|----------------|
| Acceso (Art. 14) | `GET /infouno/v1/session` *(futuro)* | Ver datos de la propia sesión |
| Rectificación (Art. 16) | Manual por tenant en dashboard | El tenant puede corregir name/email/phone |
| Supresión (Art. 16) | `DELETE /infouno/v1/session` | Soft delete + anonimización de mensajes |
| No compartir con terceros | Política del tenant | Los datos de leads son del tenant — no accesibles a otros tenants |

---

## ⚠️ Alerta de Violación

Si la IA detecta cualquiera de estas situaciones:
- PII sin consent gate previo
- `session_id` crudo en la BD
- PII en logs o en respuestas SSE
- Email de notificación con PII a tenant incorrecto

Debe reportar: `[GUARDRAIL TRIGGERED: VIOLACIÓN PII LEAD ENGINE — {descripción del riesgo}]`
y detener el cambio hasta que el flujo correcto esté implementado.
