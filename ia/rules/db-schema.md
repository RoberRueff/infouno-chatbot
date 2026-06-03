# Reglas de Base de Datos — Esquema Custom v8

Para garantizar rendimiento con miles de mensajes concurrentes, se utilizan tablas SQL puras vía `$wpdb` — sin posts/meta de WordPress.

> Versión activa: **v8** — sincronizado con `Migrator.php`.
> Próxima versión planeada: **v9** — tablas de facturación MercadoPago + AFIP (Fase 2).

---

## Tablas Actuales (v6)

### `wp_infouno_tenants`
```sql
id             INT UNSIGNED    PK AUTO_INCREMENT
uuid           VARCHAR(36)     UNIQUE
user_id        BIGINT UNSIGNED INDEX
status         VARCHAR(20)     DEFAULT 'active'   -- active | suspended | trial | over_quota
plan           VARCHAR(50)     DEFAULT 'free'     -- free | trial | premium | agency
quota_limit    INT UNSIGNED    DEFAULT 50000      -- tokens máximos por período
quota_used     INT UNSIGNED    DEFAULT 0
quota_reset_at DATETIME        NULL
created_at     DATETIME        DEFAULT CURRENT_TIMESTAMP
```

### `wp_infouno_bots`
```sql
id              INT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED  FK INDEX
bot_name        VARCHAR(100)
public_token    VARCHAR(64)   UNIQUE              -- 256 bits de entropía
system_prompt   TEXT
settings        JSON          NULL                -- temperature, max_tokens, context_window,
                                                 -- max_conv_tokens, welcome_message,
                                                 -- quick_replies (array {label, value?}),
                                                 -- whatsapp_number (ej: +5491112345678) [v6]
llm_provider    VARCHAR(50)   DEFAULT 'anthropic'
llm_model       VARCHAR(100)  DEFAULT 'claude-haiku-4-5-20251001'
allowed_origins TEXT          NULL               -- dominios autorizados, uno por línea
is_active       TINYINT(1)    DEFAULT 1
created_at      DATETIME      DEFAULT CURRENT_TIMESTAMP
```

### `wp_infouno_conversations`
```sql
id         BIGINT UNSIGNED PK AUTO_INCREMENT
tenant_id  INT UNSIGNED    FK INDEX
bot_id     INT UNSIGNED    FK INDEX
session_id VARCHAR(64)                           -- crypto.randomUUID() generado en widget
metadata   JSON            NULL                  -- reservado (tags, canal, UTM, etc.)
deleted_at DATETIME        NULL                  -- soft delete (Ley 25.326)
created_at DATETIME        DEFAULT CURRENT_TIMESTAMP
UNIQUE KEY tenant_bot_session (tenant_id, bot_id, session_id)
KEY        tenant_bot_created (tenant_id, bot_id, created_at)
```

### `wp_infouno_messages`
```sql
id              BIGINT UNSIGNED PK AUTO_INCREMENT
conversation_id BIGINT UNSIGNED FK
role            ENUM('system','user','assistant')
content         TEXT                             -- ANONIMIZADO si deleted_at IS NOT NULL
tokens_input    INT UNSIGNED    DEFAULT 0
tokens_output   INT UNSIGNED    DEFAULT 0
tokens_used     INT UNSIGNED    DEFAULT 0        -- input + output (para queries rápidas)
deleted_at      DATETIME        NULL
expires_at      DATETIME        NULL             -- retención limitada (free/trial = 30 días)
created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP
KEY conv_created (conversation_id, created_at)
KEY expires_at   (expires_at)
KEY deleted_at   (deleted_at)
```

> **REGLA ABSOLUTA:** Filas con `tokens_used > 0` NUNCA se borran físicamente.
> Al "eliminar": `content` → `'[Contenido eliminado — Ley 25.326]'` + marcar `deleted_at`.
> Los tokens se preservan para auditoría financiera permanente.

### `wp_infouno_consents`
```sql
id              BIGINT UNSIGNED PK AUTO_INCREMENT
bot_id          INT UNSIGNED    FK INDEX
tenant_id       INT UNSIGNED    FK INDEX
session_hash    VARCHAR(64)                     -- SHA-256 del session_id (no reversible)
consent_version VARCHAR(10)     DEFAULT '1.0'
scope           VARCHAR(50)     DEFAULT 'chat'  -- 'chat' | 'lead_capture'
ip_hash         VARCHAR(64)                     -- SHA-256 de la IP real
user_agent_hash VARCHAR(64)                     -- SHA-256 del User-Agent
accepted_at     DATETIME        INDEX
KEY scope (scope)
```

### `wp_infouno_leads` (Lead Engine — v7)
```sql
id              BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED     FK INDEX
bot_id          INT UNSIGNED     FK INDEX
conversation_id BIGINT UNSIGNED  NULL
session_hash    VARCHAR(64)      INDEX          -- SHA-256 del session_id (clave de upsert)
name            VARCHAR(100)     NULL           -- Solo si can_capture_name = 1
phone           VARCHAR(50)      NULL           -- Solo si can_capture_phone = 1
email           VARCHAR(255)     NULL           -- Solo si can_capture_email = 1
interest        TEXT             NULL           -- 'compra' | 'informacion' | 'consulta'
score           TINYINT UNSIGNED DEFAULT 0      -- 0-100. Umbral qualified ≥ 60
temperature     ENUM('cold','warm','hot','ready') DEFAULT 'cold'  -- [v7] temperatura comercial derivada del score + señales BANT
intent_signals  JSON             NULL           -- [v7] BANT: {budget, authority, timeline, industry, location, company}
source          VARCHAR(50)      DEFAULT 'chat'
page_url        VARCHAR(500)     NULL           -- URL de la página donde ocurrió la conversación [v6]
status          ENUM('new','contacted','interested','converted','lost') DEFAULT 'new'
                                               -- Pipeline: new→contacted→interested→converted|lost
notes           TEXT             NULL
assigned_to     BIGINT UNSIGNED  NULL           -- WP user_id del agente asignado
contacted_at    DATETIME         NULL
converted_at    DATETIME         NULL
created_at      DATETIME         DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME         ON UPDATE CURRENT_TIMESTAMP
KEY status_score (status, score)
```

### `wp_infouno_lead_consents` (Ley 25.326 — PII granular)
```sql
id                BIGINT UNSIGNED PK AUTO_INCREMENT
tenant_id         INT UNSIGNED    FK
bot_id            INT UNSIGNED    FK
session_hash      VARCHAR(64)     INDEX
can_capture_name  TINYINT(1)      DEFAULT 0
can_capture_phone TINYINT(1)      DEFAULT 0
can_capture_email TINYINT(1)      DEFAULT 0
consent_version   VARCHAR(10)     DEFAULT '1.0'
ip_hash           VARCHAR(64)
user_agent_hash   VARCHAR(64)
accepted_at       DATETIME        DEFAULT CURRENT_TIMESTAMP
KEY tenant_bot (tenant_id, bot_id)
```

---

## Tablas del Opportunity Engine (v8)

### `wp_infouno_opportunities`
```sql
id               BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id        INT UNSIGNED     FK INDEX          -- AISLAMIENTO OBLIGATORIO
lead_id          BIGINT UNSIGNED  FK INDEX
bot_id           INT UNSIGNED     FK INDEX
stage            ENUM('new','contacted','interested','quoted','won','lost') NOT NULL DEFAULT 'new'
estimated_value  DECIMAL(12,2)    NULL              -- ARS (moneda configurable vía currency)
currency         VARCHAR(3)       NOT NULL DEFAULT 'ARS'
assigned_to      BIGINT UNSIGNED  NULL              -- WP user_id del vendedor asignado
notes            TEXT             NULL
lost_reason      VARCHAR(200)     NULL              -- Obligatorio cuando stage = 'lost'
stage_changed_at DATETIME         NULL              -- Timestamp del último cambio de stage
won_at           DATETIME         NULL              -- Setear solo una vez al pasar a 'won'
lost_at          DATETIME         NULL              -- Setear solo una vez al pasar a 'lost'
created_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
KEY tenant_stage (tenant_id, stage)
KEY tenant_lead  (tenant_id, lead_id)
KEY bot_id       (bot_id)
```

> **REGLA:** `won` y `lost` son estados terminales. Una vez alcanzados, el stage no puede
> cambiar. Para reactivar un deal perdido o duplicar uno ganado, crear una nueva oportunidad.

### `wp_infouno_automation_logs`
```sql
id              BIGINT UNSIGNED PK AUTO_INCREMENT
tenant_id       INT UNSIGNED    FK INDEX
opportunity_id  BIGINT UNSIGNED NULL               -- FK nullable (puede loguear eventos de lead sin opp)
lead_id         BIGINT UNSIGNED NULL
action_type     VARCHAR(50)     NOT NULL           -- 'opportunity_created' | 'email_sent' | 'webhook_fired' | 'whatsapp_sent' | 'reminder_set' | 'deal_won'
status          VARCHAR(20)     NOT NULL DEFAULT 'ok'  -- 'ok' | 'failed' | 'pending'
metadata        JSON            NULL               -- payload enviado, respuesta, error
created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
KEY tenant_id      (tenant_id)
KEY opportunity_id (opportunity_id)
KEY lead_id        (lead_id)
KEY action_type    (action_type)
KEY status         (status)
```

---

## Reglas de Consulta (Queries)

1. **WHERE `tenant_id` mandatorio:** Toda SELECT / UPDATE / DELETE sobre tablas con `tenant_id` DEBE incluir `WHERE tenant_id = %d`. Para `wp_infouno_messages`, el aislamiento se garantiza a través del `conversation_id` cuyo `tenant_id` se valida en la capa previa.

2. **`$wpdb->prepare()` siempre:** Nunca concatenar variables directamente en SQL.

3. **Indexación verificada:** Toda query nueva que filtre por `session_hash`, `tenant_id` o `conversation_id` debe tener índice activo.

4. **JSON settings con fallback:** Al leer `settings` de `wp_infouno_bots`, siempre hacer merge con `DEFAULT_SETTINGS` de `BotManager.php` para evitar Fatal Error por campos ausentes.

---

## Retención y Purga

| Tipo de dato | Retención | Mecanismo |
|-------------|-----------|-----------|
| Mensajes free/trial | 30 días | `expires_at` + cron `infouno_purge_expired_messages` |
| Mensajes premium/agency | Indefinido | `expires_at = NULL` |
| Mensajes con tokens | Permanente | NUNCA borrar físicamente |
| Conversaciones eliminadas | Soft delete | `deleted_at` + anonimización de `content` |
| Consentimientos | Permanente | Evidencia legal — nunca purgar |

---

## Restricciones para la IA

- NO generes migraciones con `DROP TABLE` o `DROP COLUMN` automáticas. Siempre `ADD COLUMN` idempotente.
- NO asumas que una columna existe sin verificar primero la versión del schema en `Migrator.php`.
- NO uses `update_post_meta()` ni `insert_post()` para datos de conversaciones o leads.
- NO escribas queries `SELECT *` sin `tenant_id` en el WHERE para tablas multi-tenant.
- NO incrementes `INFOUNO_DB_VERSION` sin implementar el método `migrateTo[N]()` correspondiente.
- Toda nueva tabla sigue el mismo patrón: sin FKs declaradas (integridad por aplicación), índices explícitos, `tenant_id` presente.
