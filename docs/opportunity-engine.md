# Opportunity Engine — Especificación Fase 2

> Estado: 🔲 Planificado — development branch: `feature/opportunity-engine`
> DB: v7 — `wp_infouno_opportunities` + `wp_infouno_automation_logs`
> Reglas de desarrollo: `ia/rules/opportunity-engine.md`

---

## Visión

El Opportunity Engine transforma leads calificados en oportunidades de venta gestionables. Cierra el loop entre la captación automática (Lead Engine) y la conversión real (venta confirmada por el tenant).

**Sin Opportunity Engine:** El tenant recibe un email con el lead y debe gestionarlo manualmente fuera del sistema.
**Con Opportunity Engine:** El tenant tiene un CRM liviano integrado que trackea cada oportunidad desde el lead hasta el cierre, con automatizaciones que reducen el tiempo de respuesta y el trabajo manual.

---

## El Pipeline Comercial

```
LEAD (score ≥ 60)
    ↓  [automático — hook infouno_lead_captured]
OPPORTUNITY CREATED (stage: new)
    ↓  [manual o automático]
CONTACTED (stage: contacted)
    ↓  [manual — el tenant confirma que habló con el lead]
INTERESTED (stage: interested)
    ↓  [manual — el lead confirmó interés real]
QUOTED (stage: quoted)
    ↓  [manual — se envió presupuesto]
    ├── WON  → [do_action('infouno_deal_won')] → Revenue Attribution
    └── LOST → [registrar razón] → Análisis de pérdidas
```

---

## Modelo de Datos

### `wp_infouno_opportunities`
```sql
id               BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id        INT UNSIGNED     NOT NULL FK INDEX
lead_id          BIGINT UNSIGNED  NOT NULL FK INDEX
bot_id           INT UNSIGNED     NOT NULL FK INDEX
stage            ENUM('new','contacted','interested','quoted','won','lost') DEFAULT 'new'
estimated_value  DECIMAL(12,2)    NULL          -- ARS (configurado por el tenant por bot/segmento)
currency         VARCHAR(3)       DEFAULT 'ARS'
assigned_to      BIGINT UNSIGNED  NULL           -- WP user_id del vendedor asignado
notes            TEXT             NULL
stage_changed_at DATETIME         NULL           -- última vez que cambió de stage
won_at           DATETIME         NULL
lost_at          DATETIME         NULL
lost_reason      VARCHAR(200)     NULL           -- por qué se perdió (opcional)
created_at       DATETIME         DEFAULT CURRENT_TIMESTAMP
updated_at       DATETIME         ON UPDATE CURRENT_TIMESTAMP

KEY tenant_stage (tenant_id, stage)
KEY lead_id      (tenant_id, lead_id)
KEY won_at       (won_at)
```

### `wp_infouno_automation_logs`
```sql
id              BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED     NOT NULL FK INDEX
opportunity_id  BIGINT UNSIGNED  NULL FK
lead_id         BIGINT UNSIGNED  NULL FK
action_type     VARCHAR(50)      NOT NULL
  -- Valores: 'email_sent' | 'webhook_fired' | 'whatsapp_sent' | 'reminder_set' | 'crm_sync'
status          VARCHAR(20)      DEFAULT 'ok'
  -- Valores: 'ok' | 'failed' | 'pending' | 'skipped'
metadata        JSON             NULL           -- payload enviado, respuesta, error
created_at      DATETIME         DEFAULT CURRENT_TIMESTAMP

KEY tenant_action (tenant_id, action_type, created_at)
```

---

## Clases PHP a Implementar

### `OpportunityService.php`

**Responsabilidades:**
- Escuchar `infouno_lead_captured` y crear la oportunidad si corresponde.
- Validar que no existe oportunidad activa para el mismo lead (evitar duplicados).
- Calcular `estimated_value` desde la configuración del bot/tenant.
- Disparar `infouno_opportunity_created` después de crear.
- Manejar cambios de stage con validación de transiciones válidas.
- Disparar `infouno_deal_won` cuando el stage pasa a `won`.

**Método principal:**
```php
public function onLeadCaptured(int $leadId, int $tenantId, int $botId, array $result): void
public function changeStage(int $opportunityId, int $tenantId, string $newStage, ?string $lostReason = null): bool
```

### `OpportunityRepository.php`

**Responsabilidades:**
- CRUD en `wp_infouno_opportunities` con `tenant_id` siempre en WHERE.
- `findActiveByLead(int $leadId, int $tenantId): ?array`
- `create(array $data): int`
- `updateStage(int $id, int $tenantId, string $stage, array $extras = []): bool`
- `getForTenant(int $tenantId, string $stage = '', int $page = 1): array`

### `AutomationEngine.php`

**Responsabilidades:**
- Evaluar reglas de automatización configuradas por el tenant.
- Delegar acciones a `SequenceRunner`.
- Registrar todas las acciones en `automation_logs`.

### `SequenceRunner.php`

**Responsabilidades:**
- `sendEmail(int $tenantId, int $leadId, string $template): void`
- `fireWebhook(int $tenantId, string $url, array $payload): void`
- `sendWhatsApp(int $tenantId, string $phone, string $message): void`
- `setReminder(int $tenantId, int $opportunityId, string $datetime): void`

---

## Endpoints REST Planeados

```
GET    /infouno/v1/opportunities                 → Listar oportunidades (paginado, ?stage=)
POST   /infouno/v1/opportunities                 → Crear manualmente desde lead_id
GET    /infouno/v1/opportunities/{id}            → Ver detalle
PUT    /infouno/v1/opportunities/{id}/stage      → Cambiar stage
PUT    /infouno/v1/opportunities/{id}/value      → Actualizar estimated_value
PUT    /infouno/v1/opportunities/{id}/assign     → Asignar vendedor
GET    /infouno/v1/opportunities/{id}/timeline   → Historial de cambios
```

---

## Panel Admin — Funcionalidades

### Vista de Pipeline (Kanban o Lista)
- Columnas por stage: New | Contacted | Interested | Quoted | Won | Lost
- Cards con: nombre del lead, bot, score, valor estimado, días en stage
- Filtros: por bot, por vendedor asignado, por rango de fechas

### Métricas del Panel
- Valor total del pipeline (suma estimated_value donde stage ≠ won/lost)
- Win rate: won / (won + lost)
- Tiempo promedio de conversión (lead → won)
- Leads en stage > N días sin actividad (alertas de stagnation)

### Acciones Inline
- Cambiar stage (drag & drop en kanban o select en lista)
- Agregar nota rápida
- Asignar vendedor
- Ver conversación de chat original

---

## Automatizaciones Configurables por Tenant

| Trigger | Acción | Configuración |
|---------|--------|---------------|
| Lead calificado capturado | Email al lead con información de la empresa | Template de email |
| Oportunidad creada | Notificación al vendedor asignado | Webhook o WhatsApp |
| Stage: contacted sin respuesta 48h | Recordatorio al vendedor | Hora del reminder |
| Stage: interested | Enviar presupuesto automático | Template + precio estimado |
| Stage: won | Webhook a CRM externo | URL + API key del CRM |
| Stage: lost | Email de re-engagement 7 días después | Template de recuperación |

---

## Revenue Attribution (Fase 3 Preview)

Cuando el Opportunity Engine esté operativo, el Revenue Attribution Engine puede calcular:

| Métrica | Fórmula |
|---------|---------|
| Costo por lead | `total_tokens_cost / COUNT(leads con score ≥ 60)` |
| Costo por oportunidad | `total_tokens_cost / COUNT(oportunidades)` |
| Costo por venta | `total_tokens_cost / COUNT(stage = 'won')` |
| ROI por bot | `SUM(estimated_value WHERE won) / total_tokens_cost` |
| Win rate | `COUNT(won) / COUNT(won + lost)` |
| Valor promedio de deal | `AVG(estimated_value WHERE won)` |

---

## Reglas de Transición de Stages

```
new        → contacted, lost
contacted  → interested, lost
interested → quoted, lost
quoted     → won, lost
won        → (terminal — no puede cambiar)
lost       → (terminal — solo corrección manual con confirmación)
```

Una transición inválida (ej: `new → won` directamente) debe rechazarse con `422 Unprocessable Entity`.

---

## Criterio de Inicio de Desarrollo

El Opportunity Engine debe desarrollarse cuando:
1. ≥ 10 tenants activos con ≥ 5 leads calificados/mes cada uno, O
2. El feedback de tenants indica que la gestión manual de leads es el principal pain point, O
3. El tenant más activo supera 50 leads/mes y requiere pipeline estructurado.

**Acción previa al desarrollo:** Ejecutar `ia/templates/component-registration.md` completo.
