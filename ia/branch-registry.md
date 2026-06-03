# Registro de Branches — infouno-Chatbot

> Fuente de verdad del estado de branches. Actualizar antes de cada merge a `main`.
> La IA debe consultar este archivo antes de crear una rama nueva o planificar una migración.

---

## Estado Actual del Proyecto

| Campo | Valor |
|-------|-------|
| Branch principal | `main` |
| DB Version activa | `v6` |
| Fase de producto | **Fase 1 completada → Inicio Fase 2** |
| Última actualización | 2026-06-02 |

---

## Branches Activos

| Branch | Estado | Propósito | Última actividad |
|--------|--------|-----------|-----------------|
| `main` | ✅ Estable | Branch principal — v6 Lead Engine completo | 2026-06-02 |

---

## Branches Planificados (Fase 2)

| Branch | Tipo | Prioridad | Objetivo |
|--------|------|-----------|----------|
| `feature/opportunity-engine` | feature | 🔴 Alta | Opportunity Engine: tablas v7 + PHP + API REST + panel admin |
| `migration/v7-opportunities` | migration | 🔴 Alta | `wp_infouno_opportunities` + `wp_infouno_automation_logs` |
| `feature/mercadopago-subscriptions` | feature | 🔴 Alta | Webhook MP + activación/suspensión de plan en ARS |
| `feature/tenant-dashboard-astra` | feature | 🟡 Media | Dashboard tenant con Astra child theme |
| `feature/sales-automation-email` | feature | 🟡 Media | Secuencias de email (nurturing post-lead por stage) |
| `feature/crm-webhook` | feature | 🟡 Media | Webhook saliente a CRM externo (HubSpot / Zoho / Pipedrive) |

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
| **v7 (planeado)** | **wp_infouno_opportunities + wp_infouno_automation_logs (Fase 2)** |

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
