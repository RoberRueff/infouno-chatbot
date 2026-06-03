# Reglas de Desarrollo — Opportunity Engine (Fase 2)

El Opportunity Engine es la tercera capa del funnel comercial. Recibe leads calificados (score ≥ 60) desde el Lead Engine y los convierte en oportunidades con pipeline stages, valor estimado y automatizaciones de seguimiento.

> **Estado:** 🔲 No implementado — planificado para v7.
> **Trigger de inicio:** Cuando el producto tenga ≥ 10 tenants activos con leads calificados regulares.
> **Branch de desarrollo:** `feature/opportunity-engine` + `migration/v7-opportunities`.

---

## Arquitectura Planeada

```
Lead Engine (v6)
    └── do_action('infouno_lead_captured', $leadId, $tenantId, $botId, $result)  [ya implementado]
            ↓
    OpportunityService::onLeadCaptured()  [v7]
            │
            ├── Crear oportunidad desde lead (si score ≥ 60 y no existe oportunidad activa)
            ├── Asignar stage inicial: 'new'
            ├── Calcular estimated_value desde configuración del bot/tenant
            └── do_action('infouno_opportunity_created', $opportunityId, $tenantId, $stage, $value)
                        ↓
            AutomationEngine::onOpportunityCreated()  [v7]
                    ├── Email de bienvenida al lead (si configurado)
                    ├── Notificación WhatsApp (si configurado)
                    └── Webhook a CRM externo (si configurado)
```

---

## Componentes a Implementar (v7)

```
Infouno\SaaS\Opportunity\
├── OpportunityService.php    → Orquestador. Crea/actualiza oportunidades desde leads.
├── OpportunityRepository.php → CRUD en wp_infouno_opportunities con tenant_id.
│
Infouno\SaaS\Automation\
├── AutomationEngine.php      → Evalúa reglas de automatización del tenant.
├── SequenceRunner.php        → Ejecuta secuencias (email, webhook, recordatorio).
└── NotificationDispatcher.php → Dispatcher: email, WhatsApp, webhook CRM.
```

---

## Modelo de Datos (v7 — wp_infouno_opportunities)

```sql
id              BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED     FK INDEX          -- AISLAMIENTO OBLIGATORIO
lead_id         BIGINT UNSIGNED  FK INDEX
bot_id          INT UNSIGNED     FK INDEX
stage           ENUM('new','contacted','interested','quoted','won','lost') DEFAULT 'new'
estimated_value DECIMAL(12,2)    NULL              -- ARS (indexado en USD internamente)
currency        VARCHAR(3)       DEFAULT 'ARS'
assigned_to     BIGINT UNSIGNED  NULL              -- WP user_id del vendedor
notes           TEXT             NULL
stage_changed_at DATETIME        NULL
won_at          DATETIME         NULL
lost_at         DATETIME         NULL
lost_reason     VARCHAR(200)     NULL
created_at      DATETIME         DEFAULT CURRENT_TIMESTAMP
updated_at      DATETIME         ON UPDATE CURRENT_TIMESTAMP

KEY tenant_stage (tenant_id, stage)
KEY tenant_lead  (tenant_id, lead_id)
```

```sql
-- wp_infouno_automation_logs
id              BIGINT UNSIGNED  PK AUTO_INCREMENT
tenant_id       INT UNSIGNED     FK INDEX
opportunity_id  BIGINT UNSIGNED  FK NULL
lead_id         BIGINT UNSIGNED  FK NULL
action_type     VARCHAR(50)                   -- 'email_sent' | 'webhook_fired' | 'whatsapp_sent' | 'reminder_set'
status          VARCHAR(20)      DEFAULT 'ok' -- 'ok' | 'failed' | 'pending'
metadata        JSON             NULL         -- payload enviado, respuesta recibida, error
created_at      DATETIME         DEFAULT CURRENT_TIMESTAMP
```

---

## Reglas de Negocio

### Pipeline de Stages

```
new → contacted → interested → quoted → [won | lost]
```

| Stage | Significado | Trigger |
|-------|-------------|---------|
| `new` | Oportunidad recién creada | Lead calificado capturado |
| `contacted` | El tenant contactó al lead | Manual o automático |
| `interested` | El lead confirmó interés | Lead responde positivamente |
| `quoted` | Se envió presupuesto/propuesta | Tenant envía cotización |
| `won` | Venta concretada | Manual por tenant |
| `lost` | Oportunidad perdida | Manual por tenant, con razón opcional |

### Reglas de Creación de Oportunidades

1. **Solo desde leads calificados:** `score >= 60` es el único criterio. No crear oportunidades para leads fríos.

2. **Una oportunidad activa por lead:** Si el lead ya tiene una oportunidad en estado distinto de `won` o `lost`, no crear una nueva — actualizar la existente.

3. **Won/Lost son estados terminales:** Una oportunidad `won` o `lost` no puede cambiar de stage. Para reactivar, crear una nueva oportunidad.

4. **Timestamps de stage change:** Cada cambio de stage debe registrar `stage_changed_at`. Para `won` y `lost`, setear `won_at` y `lost_at` respectivamente.

---

## Hooks de Comunicación Entre Capas

```php
// Lead Engine → Opportunity Engine
do_action( 'infouno_lead_captured', $leadId, $tenantId, $botId, $result );

// Opportunity Engine → Sales Automation
do_action( 'infouno_opportunity_created', $opportunityId, $tenantId, $stage, $estimatedValue );
do_action( 'infouno_opportunity_stage_changed', $opportunityId, $tenantId, $fromStage, $toStage );

// Sales Automation → Revenue Attribution (Fase 3)
do_action( 'infouno_deal_won', $opportunityId, $tenantId, $confirmedValue );
```

**Contratos de los hooks:**
- Todos los parámetros deben ser tipos primitivos o arrays serializables (no objetos).
- El payload del hook es inmutable — los listeners no deben modificarlo.
- Si un listener falla, el hook continúa. Usar `try/catch` en cada listener.

---

## Endpoints REST Planeados (v7)

```
GET    /infouno/v1/opportunities          → Listar oportunidades del tenant (paginado, filtro por stage)
POST   /infouno/v1/opportunities          → Crear oportunidad manualmente (desde lead_id)
GET    /infouno/v1/opportunities/{id}     → Ver oportunidad
PUT    /infouno/v1/opportunities/{id}/stage → Cambiar stage
PUT    /infouno/v1/opportunities/{id}/value → Actualizar estimated_value
DELETE /infouno/v1/opportunities/{id}    → Marcar como lost (soft)
```

---

## Reglas de Automatización

1. **Configuración por tenant:** Las reglas de automatización (email, webhook, WhatsApp) son configuradas por cada tenant desde su dashboard. El motor las evalúa pero no las hardcodea.

2. **Idempotencia en automations:** El `automation_logs` debe prevenir ejecuciones duplicadas. Usar transients o `INSERT IGNORE` para acciones únicas por oportunidad+acción.

3. **Fallback silencioso:** Si una automatización falla (webhook timeout, email bounce), registrar en `automation_logs` con `status = 'failed'` y continuar. No interrumpir el pipeline.

4. **Timeout en webhooks:** Máximo 5 segundos para webhooks salientes. Usar `wp_remote_post()` con `timeout=5` y no bloquear el hilo principal.

---

## Métricas a Trackear

| Métrica | Cómo calcular |
|---------|---------------|
| Lead → Oportunidad rate | COUNT(oportunidades) / COUNT(leads con score ≥ 60) |
| Oportunidad → Won rate | COUNT(won) / COUNT(oportunidades totales) |
| Tiempo promedio de conversión | AVG(won_at - created_at) para opps won |
| Valor de pipeline | SUM(estimated_value) WHERE stage NOT IN ('won','lost') |
| Revenue atribuido | SUM(confirmed_value) WHERE stage = 'won' |
| Costo por oportunidad | total_tokens_cost / COUNT(oportunidades) |

---

## Restricciones para la IA

- NO implementes el Opportunity Engine en el branch `main` sin migración v7 completa y aprobada.
- NO crees oportunidades desde leads con score < 60.
- NO permitir que una oportunidad `won` o `lost` cambie de stage (son terminales).
- NO hardcodear reglas de automatización. Siempre desde configuración del tenant.
- NO llamar APIs externas (webhooks, email) sin registrar el intento en `automation_logs`.
- SIEMPRE incluir `tenant_id` en toda query a `wp_infouno_opportunities`.
- Antes de implementar este componente, ejecutar `ia/checks/commercial-pipeline-audit.md`.
