# Guardrail: Cumplimiento Legal — Ley 25.326 (Argentina)

> **Prioridad máxima.** Estas reglas no son negociables ni omisibles bajo ninguna circunstancia.
> El incumplimiento genera responsabilidad legal directa para la empresa y sus clientes.

---

## 1. Prohibición de DELETE Físico en Mensajes con Tokens

**Regla:** Las filas de `wp_infouno_messages` con `tokens_used > 0` NUNCA se borran físicamente.

**Procedimiento de eliminación:**
1. Reemplazar `content` por `'[Contenido eliminado — Ley 25.326]'`
2. Setear `deleted_at = NOW()`
3. Preservar `tokens_input`, `tokens_output`, `tokens_used` intactos

**Razón:** Los tokens son la base de la auditoría financiera. Borrarlos destruye la capacidad de facturar y controlar costos.

**Alerta si se viola:** `[GUARDRAIL TRIGGERED: DELETE FÍSICO DE MENSAJES CON TOKENS DETECTADO]`

---

## 2. Consentimiento Previo e Informado (Art. 5)

**Regla:** Ningún dato personal del usuario final se persiste sin consentimiento explícito registrado server-side.

**Implementación:**
- Chat general: `POST /infouno/v1/consent` → registro en `wp_infouno_consents` antes del primer mensaje.
- PII del Lead Engine: `POST /infouno/v1/consent/lead` → registro en `wp_infouno_lead_consents` por campo.
- El `localStorage` del widget es solo para UX (evitar pantalla repetida) — **no reemplaza el registro server-side**.

**Verificación obligatoria:** `LeadService::getConsents()` consulta `wp_infouno_lead_consents` antes de cualquier extracción de PII. Si no hay registro, retornar vacío y no procesar.

---

## 3. Consentimiento Granular por Campo PII (Art. 6)

**Regla:** Nombre, teléfono y email son campos independientes. El consentimiento de uno no implica el consentimiento de otro.

**Implementación:**
- `can_capture_name`, `can_capture_phone`, `can_capture_email` son flags separados en `wp_infouno_lead_consents`.
- `LeadService` filtra PII detectada por `LeadScorer` antes de persistir: solo guarda lo consentido.
- Un campo detectado sin consent se descarta silenciosamente — nunca se almacena ni se logea.

---

## 4. Derecho de Supresión (Art. 16)

**Regla:** El usuario final puede eliminar su historial en cualquier momento. La solicitud debe procesarse de forma inmediata y completa.

**Implementación:**
- `DELETE /infouno/v1/session` → `ConversationRepository::deleteSession()`:
  - Marca `deleted_at` en la conversación.
  - Anonimiza `content` en todos sus mensajes.
  - Preserva tokens para auditoría financiera.
- Verificar que el `session_id` pertenece al `bot_token` de la request (no permitir borrar sesiones ajenas).
- El botón "Eliminar mis datos" en el widget es OBLIGATORIO y no puede ocultarse ni desactivarse.

---

## 5. Minimización de Datos (Art. 4)

**Regla:** Solo se colectan datos estrictamente necesarios para el propósito declarado.

**Implementación:**
- `wp_infouno_consents`: almacena `session_hash` (SHA-256, no reversible), `ip_hash`, `user_agent_hash`. Sin datos personales directos.
- `wp_infouno_lead_consents`: mismos principios — sin PII directa en la tabla de evidencia legal.
- El `session_id` del widget es un UUID anónimo generado en `sessionStorage` (no se vincula con identidad real sin consentimiento explícito).

---

## 6. Retención Limitada (Art. 4, inc. e)

**Regla:** Los datos de usuarios en planes free/trial tienen retención máxima de 30 días.

**Implementación:**
- `expires_at` se setea en `saveExchange()` para planes free/trial.
- Cron `infouno_purge_expired_messages` (diario) purga mensajes expirados.
- Los datos de leads en `wp_infouno_leads` no tienen expiración automática — el tenant es responsable de su gestión. En Fase 2, implementar política de retención configurable por tenant.

---

## 7. Evidencia de Consentimiento

**Regla:** Cada consentimiento debe tener evidencia inmutable server-side que incluya: quién (hash de sesión e IP), cuándo (timestamp), qué versión del aviso legal, y para qué propósito (scope).

**Formato de evidencia:**
```sql
session_hash    → SHA-256 del session_id del widget
ip_hash         → SHA-256 de CF-Connecting-IP > X-Real-IP > REMOTE_ADDR
user_agent_hash → SHA-256 del User-Agent header
consent_version → Versión del texto legal que se mostró (ej: '1.0')
scope           → 'chat' o 'lead_capture'
accepted_at     → DATETIME UTC
```

---

## 8. Resets sin Recarga de Página (Widget)

**Regla:** Los componentes del widget NUNCA deben invocar `window.location.reload()`.

**Razón:** Una recarga destruye el Shadow DOM y puede interrumpir el proceso de consentimiento o el streaming activo.

**Implementación:** Usar la key de React/Preact (`resetKey++`) para remontar componentes y `resetSessionId()` para invalidar la sesión local.

---

## ⚠️ Alerta de Violación

Si la IA detecta código que viola cualquiera de estas reglas, debe:
1. Detener el cambio.
2. Reportar: `[GUARDRAIL TRIGGERED: VIOLACIÓN LEY 25.326 — {descripción}]`
3. Proponer la implementación correcta antes de continuar.
