# Auditoría del Pipeline Comercial Completo

Ejecutar cuando el cambio afecte más de una capa del funnel (ej: Lead Engine + Opportunity Engine, o Lead Engine + notificaciones externas).

> Esta auditoría es complementaria a `ia/checks/lead-engine-audit.md`.
> No reemplaza los checks individuales por componente.

---

## 1. Integridad del Funnel de Conversión

- [ ] Los hooks entre capas están declarados correctamente en `Plugin.php`:
  - `infouno_lead_captured` → listeners en `Plugin::onLeadCaptured()` (y `OpportunityService` en v7).
  - `infouno_opportunity_created` → listeners en `AutomationEngine` (v7).
  - `infouno_deal_won` → listeners en `AttributionService` (Fase 3).
- [ ] Cada hook es best-effort: un fallo en un listener no interrumpe el pipeline principal.
- [ ] Los parámetros de cada hook son tipos primitivos o arrays serializables (no objetos PHP).

---

## 2. Consistencia de Status Across Layers

- [ ] El status de `wp_infouno_leads` y el `stage` de `wp_infouno_opportunities` (v7) están sincronizados donde corresponde.
- [ ] Un lead marcado como `converted` en el Lead Dashboard tiene (o debería tener) una oportunidad en `won`.
- [ ] Los timestamps de transición (`contacted_at`, `converted_at`, `won_at`) están poblados correctamente y en orden cronológico.

---

## 3. Notificaciones y Comunicaciones

- [ ] El email de notificación de lead calificado:
  - Solo se envía una vez por `lead_id` (anti-spam transient 24h).
  - Incluye datos de contacto disponibles (no campos NULL).
  - El destinatario es el owner del tenant (no hardcodeado).
- [ ] En v7 — automatizaciones:
  - Logs en `wp_infouno_automation_logs` para toda acción ejecutada.
  - Timeout de 5s en webhooks salientes.
  - Retry logic para emails fallidos (máx 3 intentos con backoff).

---

## 4. Métricas y Atribución

- [ ] Los `tokens_used` en mensajes son la fuente de verdad del costo de IA — no están modificados.
- [ ] El `score` de leads no decreció en ningún cambio de esta sesión.
- [ ] Los contadores del panel admin (total, calificados, convertidos) se calculan en tiempo real desde la BD.
- [ ] El CSV de export incluye todos los campos necesarios para análisis externo del tenant.

---

## 5. Aislamiento Multi-tenant en el Pipeline

- [ ] Un lead del tenant A no es visible para el tenant B en ningún endpoint REST.
- [ ] Las notificaciones (email) van al tenant propietario del lead, no a un email hardcodeado.
- [ ] En v7 — oportunidades: toda query incluye `WHERE tenant_id = %d`.

---

## 6. Escalabilidad del Pipeline

- [ ] El Lead Engine no bloquea el chat (best-effort asíncrono).
- [ ] Las notificaciones de email usan `wp_mail()` (asíncrono via WP) — no bloquean el request.
- [ ] Los webhooks en v7 son fire-and-forget con timeout corto — no bloquean el pipeline.
- [ ] La consulta de leads paginada soporta `?page=N` — no carga todos los leads en memoria.

---

## 7. Documentación Actualizada

- [ ] `ia/architecture.md` refleja el estado actual del funnel (qué capas están en ✅ y cuáles en 🔲).
- [ ] `docs/lead-scoring-rules.md` está sincronizado con `LeadScorer.php` si hubo cambios al algoritmo.
- [ ] `docs/lead-data-model.md` está sincronizado con el schema actual si hubo cambios de BD.
- [ ] `ia/branch-registry.md` tiene la DB version actualizada si se creó una nueva migración.
- [ ] `ia/taxonomy.md` tiene todos los componentes nuevos registrados.
