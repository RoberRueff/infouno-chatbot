# Modelo de Datos Comercial — Lead Engine v6

> Sincronizado con `Migrator.php` v6 y el código real de `LeadService.php`, `LeadRepository.php` y `LeadDashboard.php`.
> Actualizar junto con cualquier migración de BD que afecte estas tablas.

---

## Principios del Modelo

1. **Lead First:** Toda conversación es una oportunidad de capturar un lead. El modelo prioriza la captura rápida y la calificación automática.
2. **Tenant Isolation:** Ningún lead es visible entre tenants. `tenant_id` está en toda query.
3. **PII by Consent:** Name, phone y email se persisten SOLO si el usuario otorgó consentimiento granular por campo (Ley 25.326).
4. **Score Progression:** El score solo puede subir — nunca decrece. Captura la mejor señal detectada en la conversación.
5. **Extensible:** Datos adicionales por vertical van en el `system_prompt` del bot (LLM context), no en columnas nuevas.

---

## Flujo del Lead

```
VISITANTE ANÓNIMO
    │
    ├── Acepta ConsentScreen (chat general)
    │       → registrado en wp_infouno_consents (scope='chat')
    │
    ├── Escribe en el chat → ChatService llama a LeadService (best-effort)
    │
    ├── (Si detecta keyword O llegó a 5 mensajes)
    │   → LeadConsentScreen: acepta captura de nombre/teléfono/email
    │           → registrado en wp_infouno_lead_consents
    │
    ├── LeadScorer::analyze() → score 0-100 + PII extraída
    │
    ├── LeadRepository::save() → upsert en wp_infouno_leads
    │       (solo persiste PII que fue consentida)
    │
    └── Si score ≥ 60:
            do_action('infouno_lead_captured')
            → wp_mail() al tenant con datos de contacto
```

---

## Tabla Principal: `wp_infouno_leads`

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `id` | BIGINT UNSIGNED PK | Auto-incremental |
| `tenant_id` | INT UNSIGNED FK | Propietario del lead — siempre en WHERE |
| `bot_id` | INT UNSIGNED FK | Bot que generó la conversación |
| `conversation_id` | BIGINT UNSIGNED NULL | Conversación de origen |
| `session_hash` | VARCHAR(64) INDEX | SHA-256 del session_id — clave de upsert. NUNCA el session_id crudo |
| `name` | VARCHAR(100) NULL | Solo si `can_capture_name = 1` |
| `phone` | VARCHAR(50) NULL | Solo si `can_capture_phone = 1`. Mínimo 8 dígitos verificados |
| `email` | VARCHAR(255) NULL | Solo si `can_capture_email = 1` |
| `interest` | TEXT NULL | `'compra'` \| `'informacion'` \| `'consulta'` |
| `score` | TINYINT UNSIGNED | 0-100. Solo sube. Umbral qualified ≥ 60 |
| `source` | VARCHAR(50) | `'chat'` (actual) \| `'form'` \| `'api'` (futuros) |
| `page_url` | VARCHAR(500) NULL | URL de la página donde ocurrió la conversación [v6] |
| `status` | ENUM | `new` → `contacted` → `interested` → `converted` \| `lost` |
| `notes` | TEXT NULL | Notas del tenant sobre el lead |
| `assigned_to` | BIGINT UNSIGNED NULL | WP user_id del agente asignado |
| `contacted_at` | DATETIME NULL | Solo se setea una vez al pasar a `contacted` |
| `converted_at` | DATETIME NULL | Solo se setea una vez al pasar a `converted` |
| `created_at` | DATETIME | Timestamp de creación |
| `updated_at` | DATETIME | Timestamp de última actualización |

### Índices
- `KEY status_score (status, score)` — filtrado en panel admin
- `KEY session_hash (session_hash)` — upsert principal

---

## Tabla de Consentimiento PII: `wp_infouno_lead_consents`

Evidencia legal del consentimiento granular por campo PII.

| Columna | Tipo | Descripción |
|---------|------|-------------|
| `session_hash` | VARCHAR(64) INDEX | SHA-256 del session_id |
| `can_capture_name` | TINYINT(1) | 1 = autorizó nombre |
| `can_capture_phone` | TINYINT(1) | 1 = autorizó teléfono |
| `can_capture_email` | TINYINT(1) | 1 = autorizó email |
| `consent_version` | VARCHAR(10) | Versión del texto legal |
| `ip_hash` | VARCHAR(64) | SHA-256 de la IP — evidencia sin PII directa |
| `user_agent_hash` | VARCHAR(64) | SHA-256 del User-Agent |
| `accepted_at` | DATETIME | Timestamp del consentimiento |

### Regla de uso
`LeadService::getConsents()` hace una sola query aquí antes de cualquier scoring. Si el resultado está vacío o todos los flags son `0`, el proceso termina sin persistir nada.

---

## Pipeline de Status del Lead

```
new
 ├── → contacted   (setea contacted_at si NULL)
 │        ├── → interested   (lead confirmó interés activo)
 │        │        ├── → converted  (venta confirmada — setea converted_at)
 │        │        └── → lost
 │        └── → lost
 └── → lost
```

**Reglas:**
- `contacted` y `converted` timestamps se setean **una sola vez** (no sobrescribir).
- `converted` y `lost` son terminales — solo corrección manual.
- `interested` captura el momento de interés confirmado sin conversión — útil para el Opportunity Engine (v7).

---

## Relaciones con Otras Entidades

```
wp_infouno_tenants (1)
    └──< wp_infouno_bots (1)
              └──< wp_infouno_conversations (1)
                        └──< wp_infouno_leads (n por session_hash)
                                  └── wp_infouno_lead_consents (1 por session)

── Fase 2: ──
wp_infouno_leads (1)
    └──< wp_infouno_opportunities
              └──< wp_infouno_automation_logs
```

---

## Tablas Planeadas (v7 — Fase 2)

Ver `docs/opportunity-engine.md` para `wp_infouno_opportunities` y `wp_infouno_automation_logs`.

---

## Reglas de Evolución

- No agregar columnas de verticales específicas — datos por industria van en el `system_prompt` del bot.
- No usar `session_id` crudo — siempre `session_hash`.
- No crear índices sobre `name`, `email`, `phone`.
- Toda nueva columna requiere migración versionada en `Migrator.php` y actualización de este archivo.
