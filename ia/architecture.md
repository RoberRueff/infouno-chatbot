# Arquitectura del Sistema — Plataforma de Automatización Comercial para PyMEs Argentinas
## Versión 10.0 — Estado actual del producto

> Leer este documento antes de cualquier cambio estructural, de base de datos o de flujo de datos.
> Versión de BD activa: `INFOUNO_DB_VERSION = '10'`
> Capas vivas: Conversation + Lead (v7) + Opportunity (v8) + Automation (v8) + Canales sociales (v9, WhatsApp/Telegram) + WhatsApp hardening (v10).
> Transporte web: pipeline transport-agnostic (`ChatPipeline` + `OutputSink`) con entrega SSE y fallback `?mode=full` (Bloque A).
> WhatsApp grado producción (Bloque B, v10): recibos de estado (`channel_deliveries` + wamid), clasificación de errores Graph (transitorio/permanente), ventana de 24h (`WindowChecker`), templates (`channel_templates` + `sendTemplate`).
> Aislamiento de tenant fail-closed (Bloque D — **COMPLETO**, incrementos 1-5): capa `Persistence\TenantScopedRepository` (`guardScope()` lanza si el scope ≤ 0) + guard estático en CI (`NoRawSqlOutsidePersistenceTest`) **con allowlist VACÍA → guard total**: ningún `$wpdb->` fuera de la capa de persistencia en `src/API` ni `src/Admin`. Todos los dominios migrados a repos/managers que extienden la base: `LeadRepository`, `ConsentRepository` (3 tablas de consentimiento), `OpportunityRepository` (`getLeadSnapshotForTenant`/`listWithLeadDataForTenant`) y `BotManager` (`saveWizardResult`/`leadCountsForBots`; `getByPublicToken` es la única lectura sin scope — entrada pública del widget que resuelve el tenant desde el token). Controllers, Dashboards y servicios quedaron sin SQL crudo.

---

# PARTE I — VISIÓN COMERCIAL

---

## 1. Propósito y Contexto del Producto

InfoUno Chatbot es una **plataforma SaaS Multitenant de Automatización Comercial con IA** orientada a **PyMEs argentinas**. El chatbot es el punto de entrada; el negocio real es convertir conversaciones en ingresos medibles.

### Decisiones de diseño estratégicas

| Decisión | Elección | Razón |
|----------|----------|-------|
| Plataforma base | WordPress | El 70% de las PyMEs argentinas ya tienen WP. Sin fricción de adopción. |
| Infraestructura | Plugin custom (`infouno-custom`) | Control total, sin dependencia de terceros para el runtime. |
| Modelo de aislamiento | Single site + `tenant_id` en tablas custom | Más simple y barato que Multisite. Escala bien hasta miles de tenants. |
| Moneda | ARS indexada en USD | La inflación argentina hace inviable fijar precios en pesos a largo plazo. |
| Proveedor de pagos | MercadoPago (Fase 2) | Dominante en Argentina, soporta suscripciones y débito automático. |
| Facturación | AFIP WSFE (Fase 2) | Obligatorio por ley para factura electrónica en Argentina. |

---

## 2. Funnel de Conversión — Arquitectura Comercial

La plataforma implementa un funnel de cinco capas que transforma conversaciones anónimas en ingresos atribuibles. Cada capa agrega contexto y decisión sobre la anterior. Las capas se comunican exclusivamente via hooks de WordPress (`do_action`) — son independientes entre sí y se activan por fase.

```
┌─────────────────────────────────────────────────────────────────────┐
│                    CONVERSATION LAYER  [✅ v5]                       │
│                                                                     │
│  Widget → ChatService (12 pasos) → LLMRouter → ConversationRepo    │
│  Entrada: mensaje crudo del usuario final                           │
│  Salida:  respuesta del LLM + historial persistido + tokens contados │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ userMessage + conversationHistory
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      LEAD ENGINE  [✅ v5]                            │
│                                                                     │
│  LeadService → LeadScorer → LeadRepository                         │
│  Entrada:  mensaje + historial                                      │
│  Proceso:                                                           │
│    1. Verificar consentimiento granular (1 query a lead_consents)   │
│    2. LeadScorer extrae PII (regex) y calcula score 0-100           │
│    3. Filtrar: solo campos con consent explícito (Ley 25.326)       │
│    4. LeadRepository::upsert() por session_hash                    │
│  Salida:   lead_id + score + campos PII consentidos                 │
│  Trigger:  score ≥ 60 → hook infouno_lead_captured                 │
│            → wp_mail() al tenant (1/lead/24h anti-spam)            │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ lead calificado (score ≥ 60)
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   OPPORTUNITY ENGINE  [🔲 Fase 2]                   │
│                                                                     │
│  OpportunityService → OpportunityRepository                        │
│  Entrada:  lead calificado desde Lead Engine                        │
│  Proceso:                                                           │
│    1. Crear oportunidad desde lead (si no existe)                   │
│    2. Asignar pipeline stage: new → contacted → interested          │
│                               → quoted → won | lost                 │
│    3. Calcular valor estimado del deal (configurable por tenant)    │
│    4. Priorizar por score + engagement + datos disponibles          │
│  Salida:   opportunity_id + stage + estimated_value                 │
│  Tablas:   wp_infouno_opportunities (v6)                           │
│  Trigger:  infouno_opportunity_created → notificación + CRM sync   │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ oportunidad con stage y valor estimado
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                   SALES AUTOMATION  [🔲 Fase 2]                     │
│                                                                     │
│  AutomationEngine → SequenceRunner → NotificationDispatcher        │
│  Entrada:  evento de oportunidad (created, stage_changed, won)      │
│  Proceso:                                                           │
│    1. Evaluar reglas de automatización configuradas por tenant      │
│    2. Disparar secuencias de email (nurturing por stage)            │
│    3. Webhook a CRM externo (HubSpot, Zoho, Pipedrive)             │
│    4. Recordatorios de seguimiento programados (wp_cron)            │
│    5. Notificaciones WhatsApp Business API (opcional)               │
│  Salida:   acciones ejecutadas + log de automatizaciones            │
│  Tablas:   wp_infouno_automation_logs (v6)                         │
└──────────────────────────────┬──────────────────────────────────────┘
                               │ conversiones registradas
                               ▼
┌─────────────────────────────────────────────────────────────────────┐
│                  REVENUE ATTRIBUTION  [🔲 Fase 3]                   │
│                                                                     │
│  AttributionService → RevenueReporter                              │
│  Entrada:  conversiones confirmadas (won) + tokens consumidos       │
│  Proceso:                                                           │
│    1. Atribuir ingresos a bot, conversation_id, system_prompt       │
│    2. Calcular costo por lead (tokens_usados × precio_modelo)       │
│    3. Calcular ROI por bot = revenue_atribuido / costo_tokens       │
│    4. Tasa de conversión: leads → oportunidades → won               │
│    5. Dashboard de performance por bot y por tenant                 │
│  Salida:   métricas comerciales en dashboard + API de reportes      │
│  Regla:    NUNCA sobrescribir tokens_used — base de la atribución   │
└─────────────────────────────────────────────────────────────────────┘
```

### Resumen de estado por capa

| Capa | Input | Output | Estado |
|------|-------|--------|--------|
| Conversation Layer | Mensaje crudo del usuario | Respuesta LLM + tokens | ✅ v5 |
| Lead Engine | Mensaje + historial | Lead con score 0-100 + PII consentida + temperatura BANT | ✅ v7 |
| Opportunity Engine | Lead calificado (score ≥ 60) | Oportunidad con stage + valor | ✅ v8 |
| Sales Automation | Evento de oportunidad | Email + webhook CRM | ✅ v8 |
| Canales sociales | Webhook entrante (WhatsApp/Telegram) | Respuesta vía adapter (BufferedSink) | ✅ v9 |
| Revenue Attribution | Conversión confirmada | ROI por bot + costo por lead | 🔲 Fase 3 |

> **Transporte (Bloque A):** el pipeline de chat es transport-agnostic (`Chat\ChatPipeline` escribe en un `OutputSink`). Web usa `StreamingSink` (SSE) con un endpoint `?mode=full` que reusa `BufferedSink` (fallback anti-buffering, misma generación del LLM). Los canales usan `BufferedSink` vía `Channel\InboundDispatcher`. El widget detecta buffering por timeout al primer chunk y recuerda el modo por host.

### Contratos entre capas (hooks WP)

```
Lead Engine → Opportunity Engine:
  do_action( 'infouno_lead_captured', $leadId, $tenantId, $botId, $result )
  $result = [ 'score' => int, 'is_qualified' => bool, 'fields' => array ]

Opportunity Engine → Sales Automation:
  do_action( 'infouno_opportunity_created', $opportunityId, $tenantId, $stage, $value )
  do_action( 'infouno_opportunity_stage_changed', $opportunityId, $from, $to )

Sales Automation → Revenue Attribution:
  do_action( 'infouno_deal_won', $opportunityId, $confirmedValue, $tenantId )
```

---

## 3. Hoja de Ruta — Estado y Próximos Pasos

### Fase 1 — MVP completo [✅ v6 — 100%]

**Core chatbot:**
- ✅ Plugin WordPress multitenant con aislamiento por `tenant_id`
- ✅ Cumplimiento Ley 25.326 (consentimiento + supresión + minimización)
- ✅ Modelos económicos (Haiku/GPT-4o-mini por defecto, whitelist por plan)
- ✅ Cuota por tokens con precios en USD indexados

**Capa comercial — Conversation Layer + Lead Engine [v6]:**
- ✅ Lead Engine operativo: LeadScorer + LeadRepository + LeadService
- ✅ LeadScorer v2: patrones argentinos ampliados (voseo, WhatsApp, cuotas, formas de pago, urgencia)
- ✅ Trigger de consent inteligente: por keyword de intención O por engagement (≥5 mensajes)
- ✅ Fallback de consent: si el usuario no muestra intent en 5 mensajes, se activa igual
- ✅ Consentimiento granular por campo PII (nombre / teléfono / email)
- ✅ Score 0-100 por lead, qualified threshold ≥ 60
- ✅ Email de notificación enriquecido: nombre, email, teléfono, bot, prioridad (alta/media)
- ✅ Panel admin con stat cards, filtros, actualización inline de status
- ✅ Exportación CSV con BOM UTF-8 (Excel Windows compatible)
- ✅ REST API de leads (GET paginado + PUT status con timestamps)
- ✅ LeadConsentScreen correctamente conectada al árbol de componentes del widget
- ✅ CSS completo para LeadConsentScreen, botones inline y quick replies

**Widget comercial [v6]:**
- ✅ Quick Replies: botones de respuesta rápida configurables por bot (reducen fricción)
- ✅ Botón WhatsApp: escalación directa al negocio (crítico para mercado argentino)
- ✅ `data-quick-replies` y `data-whatsapp` como atributos del script tag
- ✅ `quick_replies` y `whatsapp_number` en `settings` JSON del bot

**Schema [v6]:**
- ✅ Status `interested` agregado al ENUM de `wp_infouno_leads` (new → contacted → interested → converted | lost)
- ✅ Columna `page_url` en `wp_infouno_leads` (tracking de URL de origen)

### Fase 2 — Sales Automation + Cobro (próximo sprint)

**Funnel comercial:**
- ✅ Opportunity Engine (v8): pipeline stages (new → contacted → interested → quoted → won/lost) + estimated_value + automation_logs
- 🔲 Sales Automation: secuencias de email + webhooks a CRM externo
- 🔲 Endpoint admin para activar `trial` manualmente
- 🔲 Tests unitarios PHPUnit (aislamiento tenant, quota, InputGuard, LeadScorer)

**Cobro y facturación argentina:**
- 🔲 MercadoPago Subscriptions API (cobro en ARS al tipo MEP)
- 🔲 AFIP WSFE (factura electrónica, IVA 21%)
- 🔲 Dashboard del tenant (Tema Astra hijo, endpoints existentes)
- 🔲 Email de bienvenida + alerta de cuota 90% (hook `infouno_quota_low`)

### Fase 3 — Revenue Attribution + Escala (cuando haya +50 tenants activos)

**Revenue Attribution:**
- 🔲 ROI por bot (revenue atribuido / costo tokens)
- 🔲 Costo por lead = tokens_usados × precio_modelo_promedio
- 🔲 Tasa de conversión: leads → oportunidades → won por bot y tenant
- 🔲 Dashboard de performance con API de reportes

**Infraestructura de escala:**
- 🔲 Redis object cache (WP drop-in) → transientes del rate limit en memoria
- 🔲 HMAC request signing en el widget
- 🔲 RAG: upload de documentos + embeddings + retrieval por tenant
- 🔲 FSM explícita en el widget (XState o reducer)
- 🔲 Observabilidad: logs estructurados JSON + correlation IDs
- 🔲 Encriptación at-rest de `content` en `wp_infouno_messages`

---

## 4. Modelo de Negocio — Pricing y Márgenes

> Actualizar tipo de cambio MEP mensualmente. Los valores USD son la referencia interna.

| Plan | Tokens/mes | Costo API (USD) | Precio cliente | Margen bruto |
|------|-----------|-----------------|----------------|-------------|
| Free | 50.000 | $0,10 | $0 | — |
| Trial | 200.000 | $0,42 | Solo por invitación | Costo adquisición |
| Premium | 2.000.000 | $4,16 | USD 15-18/mes | ~4x |
| Agency | 20.000.000 | $41,60 | USD 90-120/mes | ~2,5x |

Costo base calculado con Haiku (input $0,80/1M · output $4,00/1M, mix 60/40 = $2,08/1M tokens).

**Límite de bots por plan:**
```
free: 1 bot | trial: 2 bots | premium: 10 bots | agency: 50 bots
```

**Modelos de IA habilitados por plan:**
```
free / trial:   claude-haiku-4-5-20251001  │  gpt-4o-mini
premium:        + claude-sonnet-4-6        │  + gpt-4o
agency:         + claude-opus-4-8          │  + gpt-4o (todos)
```

---

# PARTE II — ARQUITECTURA TÉCNICA

---

## 5. Vista General del Sistema

```
┌──────────────────────────────────────────────────────────────┐
│                   SITIO WEB DEL CLIENTE PYME                 │
│                                                              │
│  <script data-bot-token="..." data-api-url="..." />          │
│         ↓ monta en Shadow DOM (aislado)                      │
│  ┌──────────────────────────────────────────────┐            │
│  │  WIDGET (Preact + TypeScript)                │            │
│  │  ConsentScreen → ChatWindow → DeleteFlow     │            │
│  │  useConsent / useChat / useSession           │            │
│  └──────────────────────────────────────────────┘            │
│         ↓ HTTPS POST + SSE (ReadableStream)                  │
└──────────────────────────────────────────────────────────────┘
                           │
                    INTERNET (CORS validado)
                           │
┌──────────────────────────────────────────────────────────────┐
│                 BACKEND — WORDPRESS PLUGIN                    │
│                                                              │
│  REST API (/wp-json/infouno/v1/)                             │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐        │
│  │  Chat    │ │ Session  │ │ Consent  │ │   Bot    │        │
│  │Controller│ │Controller│ │Controller│ │Controller│        │
│  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬─────┘        │
│       │             │             │             │             │
│  ┌────▼─────────────▼─────────────▼─────────────▼─────┐      │
│  │               ChatService (orquestador)             │      │
│  │  InputGuard → CORS → Tenant → RateLimit → LLM       │      │
│  │                          ↓ paso 10                  │      │
│  │                    LeadService (best-effort)         │      │
│  └────┬──────────────────────────────────────┬─────────┘      │
│       │                                      │               │
│  ┌────▼──────┐   ┌─────────────┐  ┌──────────▼────────┐      │
│  │Conversation│   │ Lead Engine  │  │    LLMRouter      │      │
│  │Repository │   │ Score + PII │  │ Anthropic/OpenAI  │      │
│  └────┬──────┘   └──────┬──────┘  └──────────┬────────┘      │
│       │                 │                    │               │
└───────│─────────────────│────────────────────│───────────────┘
        │                 │                    │
   ┌────▼────┐      ┌──────▼──────┐   ┌────────▼────────┐
   │  MySQL  │      │  wp_leads   │   │  APIs de IA     │
   │(tablas  │      │  wp_lead_   │   │ Anthropic/OpenAI│
   │ custom) │      │  consents   │   └─────────────────┘
   └─────────┘      └─────────────┘
```

---

## 6. Stack Tecnológico

### Backend
| Capa | Tecnología | Versión mínima |
|------|-----------|----------------|
| Runtime | PHP | 8.1+ (tipado estricto) |
| Framework | WordPress | 6.4+ |
| Base de datos | MySQL | 5.7+ (JSON columns) |
| API | WP REST API native | `register_rest_route` |
| HTTP client | cURL (via WP `wp_remote_post` + directo) | — |
| Autoloader | Composer PSR-4 | — |
| Namespace raíz | `Infouno\SaaS\` | — |

### Frontend (Widget)
| Capa | Tecnología | Notas |
|------|-----------|-------|
| UI framework | Preact | Equivalente a React, 3KB gzip |
| Lenguaje | TypeScript (strict) | Sin `any` |
| Bundler | Vite (IIFE) | Un solo archivo `widget.js` |
| Aislamiento | Shadow DOM (mode: open) | CSS y DOM completamente aislados |
| Target | ES2020 | Compatible con 98% de browsers actuales |
| Límite de peso | 50 KB gzip | Warning en build si se supera |

### IA
| Proveedor | Uso | Modelo por defecto |
|-----------|-----|-------------------|
| Anthropic | Primario | `claude-haiku-4-5-20251001` |
| OpenAI | Fallback automático | `gpt-4o-mini` |
| Estrategia | Exponential backoff (2 reintentos) + fallback cruzado | — |

---

## 7. Módulos PHP — Mapa de Responsabilidades

```
Infouno\SaaS\
│
├── Plugin.php              → Singleton. Boot, DI, hooks WP, cron handlers, email notifications.
│                              Cablea: Lead Engine → ChatService → RestRouter → LeadDashboard.
│                              hook infouno_lead_captured → onLeadCaptured() (wp_mail + anti-spam transient).
│
├── Core\
│   ├── Activator.php       → Migrator::run() + schedule cron jobs
│   ├── Deactivator.php     → wp_clear_scheduled_hook()
│   └── Migrator.php        → dbDelta() idempotente. v1→v5. Sin DROP. Sin TenantManager dep.
│
├── Admin\
│   ├── LeadDashboard.php   → Panel WP Admin: stat cards (total/calificados/convertidos),
│   │                          filtro por estado, actualización inline de status con timestamps,
│   │                          exportación CSV UTF-8 BOM (Excel compatible).
│   │                          admin-post.php pattern para descarga de archivos.
│   └── BotWizard.php       → [v7] Knowledge Builder: formulario de 6 pasos que genera
│                              automáticamente el system_prompt desde datos del negocio.
│                              Guarda wizard_data JSON + system_prompt en el bot.
│                              Submenú en InfoUno > Knowledge Builder.
│
├── API\
│   ├── RestRouter.php      → Único punto de registro de rutas bajo /infouno/v1/
│   ├── ChatController.php  → SSE streaming. preValidate() antes de initSSE().
│   ├── BotController.php   → CRUD bots. Límite por plan. Ownership verificado.
│   ├── SessionController.php → DELETE /session. Soft delete + anonimización.
│   ├── ConsentController.php → POST /consent general + POST /consent/lead (PII granular).
│   ├── LeadController.php  → GET /leads (paginado, ?status, orden score DESC).
│   │                          PUT /leads/{id}/status (contacted_at / converted_at timestamps).
│   │                          requireTenant(): WP login + tenant activo.
│   └── OpportunityController.php → [v8] GET/POST /opportunities. GET /metrics.
│                                   GET/PUT /opportunities/{id}/stage (terminales: won/lost).
│                                   PUT /opportunities/{id}/value.
│                                   Ownership verificado en toda operación (tenant_id del servidor).
│
├── Bot\
│   ├── BotManager.php      → CRUD SQL con tenant_id. validateOrigin(). countForTenant().
│   ├── QuotaService.php    → Rate limit dual (sesión SHA-256 + IP real). WP transients.
│   └── PromptBuilder.php   → [v7] Genera system_prompt comercial desde wizard_data estructurado.
│                              fromWizardData(array): string. validate(array): string[].
│                              Usado por BotWizard (admin) y BotController::wizard() (REST).
│
├── Chat\
│   ├── ChatService.php     → Orquestador del pipeline completo (12 pasos).
│   │                          Paso 10: LeadService::processMessage() — best-effort, silencioso.
│   └── ConversationRepository.php → getOrCreate() con race condition handling.
│                                    getRecentMessages(), saveExchange() ACID.
│                                    deleteSession() anonimiza, no borra.
│                                    purgeExpiredMessages() / purgeDeletedMessages().
│
├── Lead\                   [CAPA COMERCIAL — Lead Engine]
│   ├── LeadService.php     → Orquestador. Verifica consentimiento (1 query),
│   │                          delega scoring, filtra PII sin consent, persiste lead,
│   │                          dispara hook infouno_lead_captured si score ≥ 60.
│   ├── LeadScorer.php      → Extracción regex de nombre/teléfono/email.
│   │                          Score 0-100: datos capturados + intención de compra.
│   │                          [v7] Temperatura comercial: cold/warm/hot/ready.
│   │                          [v7] Señales BANT: budget, authority, timeline, industry, location, company.
│   └── LeadRepository.php  → Upsert en wp_infouno_leads por session_hash.
│                              Actualiza score y campos solo si mejoran el registro.
│                              [v7] Persiste temperature e intent_signals (JSON).
│
├── LLM\
│   ├── LLMRouter.php       → Whitelist modelos por plan. Backoff exponencial. Fallback.
│   ├── LLMProviderInterface.php → Contrato de proveedores.
│   ├── AnthropicProvider.php → SSE via cURL. Parse de eventos message_start/delta.
│   ├── OpenAIProvider.php  → SSE via cURL. Usage en último chunk.
│   └── StreamResult.php    → Value object: inputTokens, outputTokens, finishReason.
│
├── Tenant\
│   └── TenantManager.php   → PLAN_QUOTAS (tokens). SELF_SERVICE_PLANS=['free'].
│                              validateForChat() → retorna tenant (evita 2ª query).
│                              incrementQuota(tokens). resetExpiredQuotas(). Alertas 90%.
│
└── Security\
    └── InputGuard.php      → 14 patrones regex ES+EN anti-injection. Log sin PII. 1000 chars max.
```

---

## 8. Widget — Arquitectura Frontend

```
src/
├── index.ts              → Entry point IIFE. Lee data-*. Valida HTTPS. Deriva apiBase.
├── widget.tsx            → Shadow DOM host. ChatTree (consent/chat). ChatSession (resetKey FSM).
├── types.ts              → WidgetConfig, Message, ChatStatus (tipado estricto, sin any)
│
├── components/
│   ├── ConsentScreen.tsx       → Pantalla legal Ley 25.326. Referencia explícita. autofocus.
│   ├── LeadConsentScreen.tsx   → Consentimiento granular por campo PII (nombre/tel/email).
│   ├── ChatWindow.tsx          → Header (botName), MessageList, InputBar, footer + delete flow.
│   ├── MessageList.tsx         → auto-scroll, aria-live, cursor animado en pending.
│   ├── InputBar.tsx            → maxLength=1000, aviso visual si se supera, Enter/Shift+Enter.
│   └── ChatButton.tsx          → FAB flotante, SVG icons, aria-label.
│
├── hooks/
│   ├── useChat.ts        → State: messages[], status. AbortController por stream.
│   ├── useConsent.ts     → localStorage (UX) + POST /consent (evidencia legal).
│   └── useSession.ts     → crypto.randomUUID(). sessionStorage por tab. resetSessionId().
│
├── api/
│   └── client.ts         → streamChat() SSE via fetch/ReadableStream.
│                           recordConsent() POST silencioso.
│                           deleteSession() DELETE con apiBase correcto.
│
└── styles/
    └── widget.css        → Todo dentro de Shadow DOM. ConsentScreen, ChatWindow,
                            footer legal, diálogo confirmación delete, aviso límite chars.
```

### Flujo de estados del widget

```
BOOT → apiUrl válida (HTTPS) ?
         └─ NO → warn + exit
         └─ SÍ → montar en Shadow DOM

CHAT_CLOSED → click FAB
         └─ CONSENT_PENDING (primera vez)
              → acepta → POST /consent → READY
         └─ READY (ya consintió en localStorage)

READY → escribe + envía
         └─ LOADING → STREAMING → READY (onDone)
                    → ERROR (4s) → READY
                    → DELETING → confirm → DELETE /session → resetSessionId() → resetKey++ → READY
```

---

## 9. Flujo de Datos Completo — Una Consulta de Chat

```
WIDGET (browser del usuario final)
│
│  1. Primer uso: ConsentScreen
│     → POST /infouno/v1/consent (bot_token + session_id)
│     → Graba en wp_infouno_consents (hash sesión, hash IP, versión aviso)
│     → localStorage marca consentimiento para no repetir
│
│  2. Envío de mensaje
│     → POST /infouno/v1/chat { bot_token, session_id, message }
│
└─────────────────────────────────────────────────────────────►
                                                               │
                                              ChatController::stream()
                                              │
                                              │ A. preValidate() — ANTES de SSE
                                              │   ├─ getByPublicToken(bot_token) → bot array
                                              │   └─ validateOrigin(bot, HTTP_ORIGIN)
                                              │      Si falla → HTTP 404 o 403 real
                                              │
                                              │ B. initSSE() — HTTP 200, headers SSE
                                              │
                                              │ C. ChatService::handle(bot, ...)
                                              │   ├─ 1. InputGuard::validateMessage()
                                              │   │     (prompt injection + longitud 1000 chars)
                                              │   │
                                              │   ├─ 2. validateOrigin() segunda capa
                                              │   │
                                              │   ├─ 3. TenantManager::validateForChat()
                                              │   │     (estado tenant + cuota mensual tokens)
                                              │   │     → retorna array tenant (sin 2ª query)
                                              │   │
                                              │   ├─ 4. QuotaService::checkRateLimit()
                                              │   │     Capa 1: sesión (5 msg/min)
                                              │   │     Capa 2: IP real (30 msg/min)
                                              │   │
                                              │   ├─ 5. getOrCreate(tenant, bot, session)
                                              │   │     + manejo de DUPLICATE KEY concurrente
                                              │   │
                                              │   ├─ 6. totalTokensForConversation()
                                              │   │     Si >= max_conv_tokens → 402
                                              │   │
                                              │   ├─ 7. getRecentMessages() sliding window
                                              │   │     (filtro: deleted_at IS NULL)
                                              │   │
                                              │   ├─ 8. buildMessages()
                                              │   │     [system_prompt] + [historial] + [user]
                                              │   │
                                              │   ├─ 9. QuotaService::increment()
                                              │   │     (antes del LLM, evita retry flooding)
                                              │   │
                                              │   ├─ 10. LLMRouter::stream()
                                              │   │      resolveModel() → whitelist por plan
                                              │   │      Exponential backoff (2 reintentos)
                                              │   │      Fallback automático Anthropic ↔ OpenAI
                                              │   │      Timeout: 15 segundos
                                              │   │      connection_aborted() → corta stream
                                              │   │           ↓ SSE deltas → widget
                                              │   │
                                              │   ├─ 11. saveExchange() ACID transaction
                                              │   │      user msg: tokens_input
                                              │   │      assistant msg: tokens_output
                                              │   │      applyExpiry si plan free/trial
                                              │   │
                                              │   └─ 12. incrementQuota(tenantId, totalTokens)
                                              │          UPDATE quota_used += tokens
                                              │          Alerta do_action si uso >= 90%
                                              │          LeadService::processMessage() best-effort
                                              │
                                              └─ sendEvent('done')
                                                 exit()
```

---

## 10. Endpoints REST — Catálogo

| Método | Ruta | Auth | Propósito |
|--------|------|------|-----------|
| GET | `/infouno/v1/health` | Público | Estado del plugin |
| GET | `/infouno/v1/tenant` | WP login + tenant | Datos del tenant actual |
| POST | `/infouno/v1/tenant` | WP login | Crear tenant (plan: free únicamente) |
| GET | `/infouno/v1/bots` | WP login + tenant activo | Listar bots del tenant |
| POST | `/infouno/v1/bots` | WP login + tenant activo | Crear bot (límite por plan) |
| GET | `/infouno/v1/bots/{id}` | WP login + ownership | Ver bot |
| PUT | `/infouno/v1/bots/{id}` | WP login + ownership | Editar bot |
| DELETE | `/infouno/v1/bots/{id}` | WP login + ownership | Eliminar bot |
| POST | `/infouno/v1/bots/{id}/wizard` | WP login + tenant activo | Genera y guarda system_prompt desde wizard_data |
| POST | `/infouno/v1/chat` | bot_token + Origin | Stream SSE de chat |
| POST | `/infouno/v1/consent` | bot_token + Origin | Registrar consentimiento legal |
| POST | `/infouno/v1/consent/lead` | bot_token + Origin | Consentimiento granular PII (Lead Engine) |
| POST | `/infouno/v1/consent/revoke` | bot_token + Origin | Revocación completa: anonimiza mensajes + PII en leads + desactiva consent flags + audit trail |
| DELETE | `/infouno/v1/session` | bot_token + Origin | Supresión de mensajes (no cubre leads PII — usar /consent/revoke para supresión completa) |
| GET | `/infouno/v1/leads` | WP login + tenant activo | Listar leads paginados (?status, orden score) |
| PUT | `/infouno/v1/leads/{id}/status` | WP login + ownership | Actualizar status + timestamps |
| GET | `/infouno/v1/opportunities` | WP login + tenant activo | Listar oportunidades (?stage, paginado) |
| POST | `/infouno/v1/opportunities` | WP login + tenant activo | Crear oportunidad manual desde lead_id (score ≥ 60) |
| GET | `/infouno/v1/opportunities/metrics` | WP login + tenant activo | Métricas de pipeline: total, by_stage, pipeline_value |
| GET | `/infouno/v1/opportunities/{id}` | WP login + ownership | Ver oportunidad |
| PUT | `/infouno/v1/opportunities/{id}/stage` | WP login + ownership | Cambiar stage (won/lost son terminales) |
| PUT | `/infouno/v1/opportunities/{id}/value` | WP login + ownership | Actualizar estimated_value + currency |

---

# PARTE III — DATOS, SEGURIDAD Y OPERACIONES

---

## 11. Modelo de Datos Completo (v5)

### Diagrama relacional

```
wp_infouno_tenants (1) ──────< wp_infouno_bots (1) ──────< wp_infouno_conversations (1) ──────< wp_infouno_messages
                                        │                           │
                                        └───────────────────────────┘
                                                      │
                               wp_infouno_consents      (consentimiento general: hash sesión + IP)
                               wp_infouno_leads         (Lead Engine: PII calificada por score)
                               wp_infouno_lead_consents (consentimiento granular por campo PII)
```

### `wp_infouno_tenants`
```sql
id             INT UNSIGNED PK AUTO_INCREMENT
uuid           VARCHAR(36)  UNIQUE          -- Identificador público (interno, no en widget)
user_id        BIGINT       INDEX           -- Usuario WP propietario
status         VARCHAR(20)  DEFAULT 'active' -- active | suspended | trial | over_quota
plan           VARCHAR(50)  DEFAULT 'free'  -- free | trial | premium | agency
quota_limit    INT UNSIGNED DEFAULT 50000   -- Tokens máximos por período (ver PLAN_QUOTAS)
quota_used     INT UNSIGNED DEFAULT 0       -- Tokens consumidos este período
quota_reset_at DATETIME                     -- Próximo reset (+30 días desde creación/reset)
created_at     DATETIME
```

### `wp_infouno_bots`
```sql
id              INT UNSIGNED PK
tenant_id       INT UNSIGNED FK INDEX
bot_name        VARCHAR(100)
public_token    VARCHAR(64)  UNIQUE    -- 256 bits de entropía (bin2hex(random_bytes(32)))
system_prompt   TEXT
settings        JSON                  -- temperature, max_tokens, context_window,
                                      -- max_conv_tokens (20.000 default), welcome_message,
                                      -- quick_replies (array {label, value?}),
                                      -- whatsapp_number (ej: +5491112345678) [v6]
llm_provider    VARCHAR(50)  DEFAULT 'anthropic'
llm_model       VARCHAR(100) DEFAULT 'claude-haiku-4-5-20251001'
allowed_origins TEXT                  -- Dominios autorizados, uno por línea
is_active       TINYINT(1)   DEFAULT 1
created_at      DATETIME
```

### `wp_infouno_conversations`
```sql
id         BIGINT UNSIGNED PK
tenant_id  INT UNSIGNED FK  INDEX
bot_id     INT UNSIGNED FK  INDEX
session_id VARCHAR(64)      -- crypto.randomUUID() generado por el widget
metadata   JSON             -- reservado para futuro (tags, canal, etc.)
deleted_at DATETIME NULL    -- soft delete (Ley 25.326)
created_at DATETIME
UNIQUE KEY (tenant_id, bot_id, session_id)  -- previene duplicados por concurrencia
KEY (tenant_id, bot_id, created_at)
```

### `wp_infouno_messages`
```sql
id              BIGINT UNSIGNED PK
conversation_id BIGINT UNSIGNED FK
role            ENUM('system','user','assistant')
content         TEXT                -- ANONIMIZADO si deleted_at IS NOT NULL
tokens_input    INT UNSIGNED        -- Tokens del prompt enviado al LLM
tokens_output   INT UNSIGNED        -- Tokens de la respuesta del LLM
tokens_used     INT UNSIGNED        -- input + output (para queries rápidas)
deleted_at      DATETIME NULL       -- soft delete / anonimización
expires_at      DATETIME NULL       -- retención limitada (free/trial = 30 días)
created_at      DATETIME
KEY (conversation_id, created_at)   -- índice compuesto para sliding window
KEY (expires_at)
KEY (deleted_at)
```

> **REGLA ABSOLUTA:** Las filas con `tokens_used > 0` NUNCA se borran físicamente.
> Al "eliminar", el campo `content` se reemplaza por `'[Contenido eliminado — Ley 25.326]'`
> y se marca `deleted_at`. Los tokens se preservan para auditoría financiera permanente.

### `wp_infouno_consents`
```sql
id              BIGINT UNSIGNED PK
bot_id          INT UNSIGNED FK INDEX
tenant_id       INT UNSIGNED FK INDEX
session_hash    VARCHAR(64)    -- SHA-256 del session_id (no reversible)
consent_version VARCHAR(10)    DEFAULT '1.0'
ip_hash         VARCHAR(64)    -- SHA-256 de la IP real (CF > X-Real-IP > REMOTE_ADDR)
user_agent_hash VARCHAR(64)    -- SHA-256 del User-Agent
accepted_at     DATETIME INDEX
```

Evidencia legal server-side. Sin datos personales directos (minimización — Art. 4, Ley 25.326).

### `wp_infouno_leads` (Lead Engine — v7)
```sql
id              BIGINT UNSIGNED PK
tenant_id       INT UNSIGNED FK INDEX
bot_id          INT UNSIGNED FK INDEX
conversation_id BIGINT UNSIGNED NULL
session_hash    VARCHAR(64) INDEX   -- SHA-256 del session_id (clave de upsert)
name            VARCHAR(100) NULL   -- Solo si can_capture_name = 1
phone           VARCHAR(50)  NULL   -- Solo si can_capture_phone = 1
email           VARCHAR(255) NULL   -- Solo si can_capture_email = 1
interest        TEXT NULL           -- 'compra' | 'informacion' | 'consulta'
score           TINYINT UNSIGNED    -- 0-100. Umbral qualified: 60
temperature     ENUM('cold','warm','hot','ready') DEFAULT 'cold'  -- [v7] temperatura comercial
intent_signals  JSON NULL           -- [v7] señales BANT: {budget, authority, timeline, industry, location, company}
source          VARCHAR(50) DEFAULT 'chat'
page_url        VARCHAR(500) NULL   -- URL de la página donde ocurrió la conversación [v6]
status          ENUM('new','contacted','interested','converted','lost') DEFAULT 'new'
                                    -- Pipeline: new → contacted → interested → converted | lost
notes           TEXT NULL
assigned_to     BIGINT UNSIGNED NULL -- WP user ID del agente asignado
contacted_at    DATETIME NULL
converted_at    DATETIME NULL
created_at      DATETIME
updated_at      DATETIME
```

### `wp_infouno_lead_consents` (Lead Engine — v5)
```sql
id                BIGINT UNSIGNED PK
session_hash      VARCHAR(64) INDEX
bot_id            INT UNSIGNED FK
can_capture_name  TINYINT(1) DEFAULT 0
can_capture_phone TINYINT(1) DEFAULT 0
can_capture_email TINYINT(1) DEFAULT 0
accepted_at       DATETIME
```

Consentimiento granular por campo PII. La query de LeadService lee las 3 columnas en una sola consulta.

---

## 12. Modelo de Seguridad

### Capas de protección del endpoint `/chat`

```
Request → [1. WP REST arg validation] → [2. preValidate: bot_token + Origin]
       → [3. InputGuard: prompt injection 14 patrones ES+EN] → [4. CORS 2ª capa]
       → [5. Tenant status + quota] → [6. Rate limit sesión + IP]
       → [7. Conv token ceiling] → LLM → Response
```

### Autenticación del widget

El widget se autentica con `bot_token` (256 bits, `bin2hex(random_bytes(32))`). No es un JWT ni un secret — es el identificador público del bot. Su seguridad descansa en:

1. Validación de `Origin` contra `allowed_origins` del bot (whitelist por dominio)
2. Rate limiting dual que hace inviable el brute-force (30 req/min/IP)
3. Cuota por tenant que limita el daño si el token se filtra

### Protección financiera

| Amenaza | Defensa |
|---------|---------|
| Mensaje inflado (token exhaustion) | `max_message_chars = 1000` en 3 capas |
| Conversación infinita | `max_conv_tokens` por bot (default 20.000) |
| Cuota mensual agotada | `validateForChat()` pre-vuelo, corta con HTTP 402 |
| Modelo caro no autorizado | `resolveModel()` whitelist por plan, downgrade silencioso |
| Bot farming (muchos bots free) | `PLAN_BOT_LIMITS`: free=1, trial=2, premium=10, agency=50 |
| IP rotation para bypass de rate limit | CF-Connecting-IP > X-Real-IP > REMOTE_ADDR |
| Replay de requests | Rate limiting + session_id por tab (sessionStorage) |

### Cumplimiento Ley 25.326 (Argentina)

| Obligación | Implementación |
|------------|---------------|
| Consentimiento previo e informado | `ConsentScreen` antes del primer mensaje |
| Evidencia de consentimiento | `wp_infouno_consents` (hash sesión + IP, sin PII directo) |
| Consentimiento PII granular | `wp_infouno_lead_consents` por campo (nombre/tel/email) |
| Derecho de supresión (Art. 16) | `POST /infouno/v1/consent/revoke` → anonimiza mensajes + PII en leads + desactiva consent flags + audit trail scope='consent_revoked' |
| No borrado de auditoría financiera | Filas con `tokens_used > 0` NUNCA eliminadas físicamente |
| Minimización de datos | Solo `session_id` identifica al usuario final (anónimo) |
| Retención limitada free/trial | `expires_at` en mensajes, purga diaria via wp_cron |

---

## 13. Economía de Tokens — Controles Activos

```
Por mensaje (entrada):
  └─ max 1.000 chars → InputGuard → WP arg validator → widget maxLength

Por conversación:
  └─ max_conv_tokens (default 20.000, configurable por bot)
  └─ Sliding window de historial (default últimos 10 mensajes)

Por sesión (rate limiting):
  └─ 5 mensajes/min por session_id (SHA-256, no evasible desde servidor)
  └─ 30 mensajes/min por IP real (no evasible desde cliente)

Por mes (cuota tenant):
  └─ Verificación pre-vuelo (validateForChat)
  └─ Descuento en tokens reales (no en "mensajes")
  └─ Alerta hook infouno_quota_low al 90%
  └─ Reset automático cada 30 días

Por plan (modelos):
  └─ Whitelist en LLMRouter (downgrade automático si excede)
  └─ Límite de bots por plan (BotManager::PLAN_BOT_LIMITS)
```

---

## 14. Jobs de Mantenimiento (wp_cron)

| Evento | Frecuencia | Acción |
|--------|-----------|--------|
| `infouno_purge_expired_messages` | Diaria | Anonimiza msgs con tokens expirados. Borra msgs sin tokens expirados. Borra msgs soft-deleted sin tokens (> 1 día). |
| `infouno_reset_monthly_quotas` | Horaria | Resetea `quota_used = 0` en tenants con `quota_reset_at <= NOW()`. Avanza `quota_reset_at + 30 días`. Limpia alerta 90% del ciclo anterior. |

---

## 15. Restricciones y Reglas No Negociables

1. **Toda query SQL a tablas `wp_infouno_*` incluye filtro de `tenant_id`**. Sin excepciones salvo superadmin explícito.
2. **Filas con `tokens_used > 0` en `wp_infouno_messages` NUNCA se borran físicamente**. Solo anonimización de `content`.
3. **El `tenant_id` siempre desde la sesión del servidor, nunca del request body o query string**.
4. **Modelos caros solo si el plan los incluye** (whitelist en `LLMRouter`).
5. **Sin `window.location.reload()` en el widget** — usar mutación de estado Virtual DOM.
6. **Sin credenciales en código fuente**. API keys en `wp-config.php` como constantes PHP.
7. **Nuevos endpoints REST solo en `RestRouter.php`**, con `permission_callback` siempre presente.
8. **El `bot_token` nunca se registra en logs** en texto plano.
9. **Migraciones de BD solo en `Migrator.php`**, sin `DROP TABLE` automático, con `INFOUNO_DB_VERSION` incrementado.
10. **El widget solo funciona sobre HTTPS** (validación en `index.ts` al montar).
11. **Lead Engine es best-effort**: cualquier error en LeadService se captura con `catch(\Throwable)` — nunca interrumpe el pipeline de chat.
12. **PII solo se persiste si existe consentimiento explícito por campo**. `LeadService` verifica antes de llamar a `LeadScorer`.
