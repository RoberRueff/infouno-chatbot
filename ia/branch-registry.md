# Registro de Branches — infouno-Chatbot

> Fuente de verdad del estado de branches. Actualizar antes de cada merge a `main`.
> La IA debe consultar este archivo antes de crear una rama nueva o planificar una migración.

---

## Estado Actual del Proyecto

| Campo | Valor |
|-------|-------|
| Branch principal | `main` |
| DB Version activa | `v11` |
| Fase de producto | **Fase 2 en curso** (Opportunity + Automation + Canales + transporte web + WhatsApp hardening) |
| Última actualización | 2026-06-08 |

---

## Branches Activos

| Branch | Estado | Propósito | Última actividad |
|--------|--------|-----------|-----------------|
| `main` | ✅ Estable | Branch principal — **v11**: Lead + Opportunity + Automation + Canales + transporte SSE→Full + WhatsApp hardening + Bloque D (guard total) + **MercadoPago Suscripciones premium** | 2026-06-08 |

> **Bloque D** (incs 3-5) y **MercadoPago Suscripciones** (v11) mergeados y cerrados en `main` vía merges `--no-ff`. Sus ramas (`feature/tenant-isolation-*`, `feature/mercadopago-subscriptions`) se integraron y se borraron (local + remoto). `main` sincronizado con `origin/main`.
> `feature/tenant-billing-panel` (panel de suscripción del tenant) **en PR**, off `main`.

> Las ramas `feature/financial-core-fixes`, `feature/runtime-verification` y `feature/social-channels` ya están integradas en `main` (no borradas localmente).
> `main` está sincronizado con `origin/main` (todo el Bloque D mergeado).

---

## Capas completadas (en `main`)

| Capa / Feature | Versión | Estado |
|----------------|---------|--------|
| Conversation Layer + Lead Engine | v6 | ✅ |
| Lead Engine v2: temperatura BANT + intent_signals | v7 | ✅ |
| Opportunity Engine (pipeline stages, estimated_value) | v8 | ✅ |
| Sales Automation (email + webhook, automation_logs) | v8 | ✅ |
| Canales sociales (WhatsApp Cloud API + Telegram) | v9 | ✅ |
| Transporte web SSE→Full (Bloque A) + spec §A.3 | v9 (sin cambio de schema) | ✅ |
| WhatsApp hardening (Bloque B): recibos de estado, clasificación de errores Graph, ventana 24h, templates, channel_deliveries | v10 | ✅ |
| Aislamiento de tenant fail-closed (Bloque D, incrementos 1+2): `Persistence\TenantScopedRepository` + guard estático + dominio Leads migrado a repo | v10 (sin cambio de schema) | ✅ |
| Aislamiento de tenant fail-closed (Bloque D, incremento 3): dominio Consents migrado a `Persistence\ConsentRepository` (ConsentController + ChannelConsentService sin `$wpdb`); allowlist del guard 7→5 | v10 (sin cambio de schema) | ✅ (en `main`) |
| Aislamiento de tenant fail-closed (Bloque D, incremento 4): dominio Opportunities — `OpportunityRepository` extiende la base (guardScope en 9 métodos + 2 métodos nuevos); OpportunityController + OpportunityDashboard sin `$wpdb`; allowlist del guard 5→3 | v10 (sin cambio de schema) | ✅ (en `main`) |
| Aislamiento de tenant fail-closed (Bloque D, incremento 5 — **CIERRE**): dominio Bots — `BotManager` extiende la base (guardScope; `getByPublicToken` sin scope) + saveWizardResult/leadCountsForBots; BotController/BotWizard/BotDashboard sin `$wpdb`; **allowlist del guard 3→0 (guard total)** | v10 (sin cambio de schema) | ✅ (en `main`) |
| MercadoPago Suscripciones premium: webhook firmado (HMAC + anti-replay) + fetch autoritativo (Enfoque A), `SubscriptionRepository` + `SubscriptionService` (máquina de estados), `BillingController` + 4 rutas REST, `BillingSettings` (WP Admin), `TenantManager::applyPlanChange` | v11 | ✅ (en `main`) |
| Panel de billing del tenant: `Admin\TenantBillingPanel` (WP Admin, suscribir/cancelar premium) + `Billing\BillingServiceFactory` (wiring único, compartido con RestRouter) | v11 (sin cambio de schema) | ✅ (en PR, pendiente merge) |

---

## Branches Planificados (siguiente)

| Branch | Tipo | Prioridad | Objetivo |
|--------|------|-----------|----------|
| `feature/tenant-dashboard-astra` | feature | 🟡 Media | Dashboard tenant con Astra child theme |
| `feature/crm-webhook` | feature | 🟡 Media | Webhook saliente a CRM externo (HubSpot / Zoho / Pipedrive) |
| — | feature | 🟢 Baja | Mensajería proactiva con templates (infra Bloque B lista; disparo llega con Sales Automation) |

---

## Convención de Nombres de Branches

```
feature/[componente]-[descripcion-corta]   → nueva funcionalidad
fix/[componente]-[descripcion-corta]       → corrección de bug
security/[descripcion-corta]               → parche de seguridad
migration/v[N]-[descripcion]               → migración de BD
docs/[area]-[descripcion]                  → solo documentación
```

---

## Historial de Versiones de BD

| Versión | Cambios principales |
|---------|---------------------|
| v1 | Esquema inicial: tenants, bots, conversations, messages |
| v2 | tokens_input / tokens_output / quota_reset_at / composite indexes |
| v3 | Cuota basada en tokens. PLAN_QUOTAS. quota_limit DEFAULT 50.000 |
| v4 | Tabla consents (Ley 25.326). Soft delete en conversations y messages (deleted_at) |
| v5 | scope en consents. Tabla leads (Lead Engine). Tabla lead_consents (PII granular) |
| v6 | status 'interested' en leads. page_url en leads. quick_replies + whatsapp_number en bot settings |
| v7 | temperature ENUM + intent_signals JSON (BANT) en wp_infouno_leads |
| v8 | wp_infouno_opportunities + wp_infouno_automation_logs (Opportunity Engine + Sales Automation) |
| v9 | wp_infouno_channels + wp_infouno_channel_events + columnas channel/external_user en conversations y channel en consents (Canales sociales) |
| v10 | wp_infouno_channel_templates + wp_infouno_channel_deliveries (Bloque B WhatsApp hardening) |
| v11 | wp_infouno_subscriptions + wp_infouno_payment_events (MercadoPago Suscripciones premium) |

---

## Reglas de Merge a `main`

- [ ] `composer package-lint` sin errores
- [ ] `npm run build` sin errores ni warnings de tamaño (< 50 KB gzip)
- [ ] Toda query SQL nueva incluye `WHERE tenant_id = %d`
- [ ] Si hay nueva tabla/columna: `INFOUNO_DB_VERSION` incrementado y `migrateTo[N]()` implementado
- [ ] Nuevos endpoints REST registrados en `RestRouter.php` con `permission_callback`
- [ ] Si se toca Lead Engine: `ia/checks/lead-engine-audit.md` completo
- [ ] Si se toca PII o consentimiento: `ia/checks/pii-compliance-audit.md` completo
- [ ] Si se toca el pipeline comercial completo: `ia/checks/commercial-pipeline-audit.md` completo
- [ ] Este archivo actualizado con la nueva DB version si corresponde
