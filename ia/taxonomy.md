# Taxonomía del Proyecto — infouno-Chatbot

## Qué es este proyecto

WordPress como infraestructura SaaS Multitenant para gestión y despliegue de Chatbots Comerciales.
- **Arquitectura:** WordPress Single Site con tablas custom (`wp_infouno_*`). Aislamiento multitenant por `tenant_id` en cada tabla.
- **Misión del producto:** Transformar conversaciones anónimas en leads calificados, oportunidades y ventas medibles.
- **Frontend Core:** Tema base Astra (Panel de administración del cliente / Landing).
- **Core Engine:** Plugin custom `infouno-custom` — lógica de tenants, APIs de IA, Lead Engine, pipeline comercial.
- **Stack de Desarrollo:** Un desarrollador + AI.

---

## Mapa de Áreas de Código

| Área | Ruta | Rule file | Propósito |
|------|------|-----------|-----------|
| **SaaS Core Plugin** | `plugins/infouno-custom/` | `ia/rules/plugin-core.md` | Lógica multitenant, endpoints REST, integración LLMs, licencias. |
| **Lead Engine** | `plugins/infouno-custom/src/Lead/` | `ia/rules/lead-engine.md` | Captura, scoring y gestión de leads comerciales. |
| **Opportunity Engine** | `plugins/infouno-custom/src/Opportunity/` | `ia/rules/opportunity-engine.md` | Pipeline de oportunidades (stages, valor estimado, CRM sync). [✅ v8] |
| **Sales Automation** | `plugins/infouno-custom/src/Automation/` | `ia/rules/opportunity-engine.md` | Email + webhook al CRM en eventos de pipeline. [✅ v8.5] |
| **Canales Sociales** | `plugins/infouno-custom/src/Channel/` | `ia/rules/plugin-core.md` | Adapters de canal (WhatsApp Cloud API, Telegram): webhook entrante, idempotencia, credenciales cifradas, respuesta vía BufferedSink. [✅ v9]. WhatsApp hardening: recibos de estado, wamid, clasificación de errores Graph, ventana 24h, templates. [✅ v10] |
| **Chatbot Widget** | `plugins/infouno-custom/client-widget/` | `ia/rules/chatbot-widget.md` | Script Preact/TS embebido en sitios de clientes (Shadow DOM). Entrega SSE con fallback `?mode=full`. |
| **Tema Panel/SaaS** | `themes/astra/` | `ia/rules/thema-astra.md` | Dashboard del tenant y landing page de la plataforma. |
| **Admin Dashboard** | `plugins/infouno-custom/src/Admin/` | `ia/rules/lead-engine.md` | Panel WP Admin: leads, stats, export CSV. |

---

## Namespaces PHP — `src/`

| Namespace | Carpeta | Responsabilidad |
|-----------|---------|-----------------|
| `Infouno\SaaS\Core` | `src/Core/` | Activación, migración de BD, desactivación |
| `Infouno\SaaS\API` | `src/API/` | Controllers REST y router |
| `Infouno\SaaS\Bot` | `src/Bot/` | CRUD de bots, rate limiting, settings |
| `Infouno\SaaS\Chat` | `src/Chat/` | Conversaciones, mensajes, orquestación del chat (12 pasos) |
| `Infouno\SaaS\LLM` | `src/LLM/` | Proveedores de IA, router, streaming, fallback |
| `Infouno\SaaS\Lead` | `src/Lead/` | **Lead Engine**: LeadScorer, LeadService, LeadRepository |
| `Infouno\SaaS\Opportunity` | `src/Opportunity/` | **Opportunity Engine** [v8]: OpportunityService, OpportunityRepository, pipeline stages |
| `Infouno\SaaS\Automation` | `src/Automation/` | **Sales Automation** [v8.5]: AutomationEngine, NotificationDispatcher — email + webhook on pipeline events |
| `Infouno\SaaS\Channel` | `src/Channel/` | **Canales sociales** [v9]: ChannelAdapterInterface, WhatsAppAdapter, TelegramAdapter, ChannelRegistry, InboundDispatcher, idempotencia (ChannelEventRepository). **WhatsApp hardening** [v10]: WhatsAppStatusEvent, WhatsAppGraphException, ChannelDeliveryRepository, ChannelTemplateRepository, WindowChecker, TemplateVariableResolver |
| `Infouno\SaaS\Persistence` | `src/Persistence/` | **Aislamiento fail-closed** [Bloque D COMPLETO]: TenantScopedRepository (base con `guardScope()`), MissingTenantScopeException, LeadRepository (inc 2), ConsentRepository (inc 3, dominio Consents — tablas consents/lead_consents/leads). Los repos/managers tenant-scoped extienden esta base; el guard estático (NoRawSqlOutsidePersistenceTest) con **allowlist VACÍA** prohíbe todo SQL crudo fuera de la capa de persistencia. También extienden la base: `Opportunity\OpportunityRepository` (inc 4), `Bot\BotManager` (inc 5) y `SubscriptionRepository` (Billing v11 — subscriptions + payment_events). |
| `Infouno\SaaS\Billing` | `src/Billing/` | **MercadoPago Suscripciones** [v11]: BillingConfig (credenciales env-first + precio), MercadoPagoClient (sobre HttpClientInterface inyectable), WebhookSignatureVerifier (HMAC x-signature + anti-replay), SubscriptionService (alta + reconciliación idempotente, máquina de estados premium). El SQL vive en `Persistence\SubscriptionRepository`. |
| `Infouno\SaaS\Tenant` | `src/Tenant/` | Ciclo de vida de tenants, cuotas, planes. `applyPlanChange()` aplica plan+status+quota (usado por billing) |
| `Infouno\SaaS\Security` | `src/Security/` | Validación de input, detección de prompt injection |
| `Infouno\SaaS\Admin` | `src/Admin/` | Paneles WP Admin: LeadDashboard |

---

## Capas del Funnel Comercial

```
CONVERSATION LAYER   [✅ v6]   → Widget, ChatService, LLMRouter
      ↓
LEAD ENGINE          [✅ v7]   → LeadScorer, LeadService, LeadRepository (+ temperatura BANT)
      ↓
OPPORTUNITY ENGINE   [✅ v8]   → OpportunityService, OpportunityRepository, OpportunityController
      ↓
SALES AUTOMATION     [✅ v8.5]   → AutomationEngine, NotificationDispatcher (email + webhook)
      ↓
REVENUE ATTRIBUTION  [🔲 Fase 3] → AttributionService, RevenueReporter
```

---

## Gobernanza AI — Archivos `ia/`

Todo lo que está en `ia/` es infraestructura de gobernanza. No modificar sin motivo claro.

### Documentos de Contexto

| Archivo | Propósito |
|---------|-----------|
| `CLAUDE.md` | Entry point. Lo primero que lee el modelo. |
| `ia/taxonomy.md` | Este archivo. Mapa del proyecto. |
| `ia/architecture.md` | Arquitectura técnica y comercial completa (leer antes de cambios estructurales). |
| `ia/branch-registry.md` | Estado de branches, DB version, historial de migraciones. |
| `ia/context-loader.md` | Protocolo de carga de contexto pre-tarea. |

### Rules — 1 archivo por componente técnico crítico

| Archivo | Cuándo cargar |
|---------|---------------|
| `ia/rules/plugin-core.md` | Cambios en el núcleo PHP, hooks, endpoints REST |
| `ia/rules/lead-engine.md` | Cambios en LeadScorer, LeadService, LeadRepository, LeadDashboard |
| `ia/rules/opportunity-engine.md` | Cambios en Opportunity Engine (Fase 2) |
| `ia/rules/db-schema.md` | Migraciones, nuevas tablas o queries SQL |
| `ia/rules/llm-integration.md` | Cambios en streaming o proveedores de IA |
| `ia/rules/token-economy.md` | Cambios en conteo de tokens, cuotas o modelos |
| `ia/rules/chatbot-widget.md` | Cambios en el widget Preact/TypeScript |
| `ia/rules/thema-astra.md` | Cambios en el tema hijo o dashboard del tenant |

### Guardrails — Límites que la AI nunca cruza sin confirmación

| Archivo | Qué protege |
|---------|-------------|
| `ia/guardrails/tenant-isolation.md` | Aislamiento de datos entre tenants (CRÍTICO) |
| `ia/guardrails/lead-pii-protection.md` | Datos PII en el Lead Engine (Ley 25.326) |
| `ia/guardrails/commercial-data-integrity.md` | Integridad de scores, oportunidades y revenue |
| `ia/guardrails/legal-copliance.md` | Cumplimiento Ley 25.326 Argentina |
| `ia/guardrails/api-protection.md` | Protección financiera contra abuso de tokens |
| `ia/guardrails/code-quality.md` | Estándares de calidad y seguridad del código |
| `ia/guardrails/llm-safety-output.md` | Prompt injection y output safety |
| `ia/guardrails/resource-abuse.md` | Abuso de recursos del servidor |

### Checks — Checklists por momento del ciclo de desarrollo

| Archivo | Cuándo ejecutar |
|---------|-----------------|
| `ia/checks/lead-engine-audit.md` | Antes de merge con cambios en Lead Engine |
| `ia/checks/commercial-pipeline-audit.md` | Antes de merge con cambios en el pipeline comercial completo |
| `ia/checks/pii-compliance-audit.md` | Antes de merge con cambios en PII o consentimiento |
| `ia/checks/perfomance-audit.md` | Antes de toda entrega de código |

### Templates — Esqueletos reutilizables

| Archivo | Uso |
|---------|-----|
| `ia/templates/task-completion.md` | Formato obligatorio de entrega de tareas |
| `ia/templates/component-registration.md` | Registro de nuevo componente antes de codificar |
| `ia/templates/migration-template.md` | Esqueleto para nueva migración de BD |
| `ia/templates/lead-prompt-builder.md` | Guía para construir system prompts comerciales |

---

## Documentación de Producto — `docs/`

| Archivo | Propósito |
|---------|-----------|
| `docs/lead-engine.md` | Visión estratégica del Lead Engine y roadmap |
| `docs/lead-data-model.md` | Modelo de datos comercial (Lead, Opportunity, Sale) — sincronizado con schema real |
| `docs/lead-scoring-rules.md` | Algoritmo de scoring v2 — sincronizado con LeadScorer.php |
| `docs/opportunity-engine.md` | Especificación completa del Opportunity Engine (Fase 2) |
| `docs/commercial-prompts.md` | Guía de system prompts comerciales para PyMEs argentinas |
| `docs/widget-embed-guide.md` | Guía completa de embed del widget (todos los data-* attrs) |

---

## Agregar un Nuevo Componente o Feature SaaS

Seguir el proceso en `ia/templates/component-registration.md` **antes de tocar código**.
Especial atención a las reglas de aislamiento de datos (tenant_id en toda query) y cumplimiento Ley 25.326 si el componente maneja PII.
