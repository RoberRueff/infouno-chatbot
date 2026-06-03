# Auditoría del Lead Engine

Ejecutar este checklist completo antes de mergear cualquier cambio que afecte `src/Lead/`, `src/Admin/LeadDashboard.php`, `src/API/LeadController.php`, `src/API/ConsentController.php`, o `hooks/useChat.ts`.

> También ejecutar `ia/checks/pii-compliance-audit.md` si el cambio toca captura, almacenamiento o transmisión de PII.

---

## 1. Flujo de Orquestación (LeadService)

- [ ] `processMessage()` está envuelto en `try/catch(\Throwable)` en `ChatService` — un fallo no interrumpe el chat.
- [ ] La primera acción de `processMessage()` es consultar `getConsents()`. Si retorna vacío, se retorna inmediatamente sin scoring.
- [ ] Los campos PII en `$toSave` solo se incluyen si el flag correspondiente es `1` en los consents.
- [ ] El hook `infouno_lead_captured` solo se dispara si `score >= 60` Y `lead_id > 0`.
- [ ] El anti-spam transient está activo: `infouno_lead_notif_{leadId}` previene emails duplicados en 24h.

---

## 2. Scoring (LeadScorer)

- [ ] Los patrones regex cubren voseo argentino, formas de pago locales y canales de contacto (ver `docs/lead-scoring-rules.md`).
- [ ] El score siempre está entre 0 y 100 — se aplica `min(100, $score)` antes de retornar.
- [ ] La detección de phone verifica longitud mínima de 8 dígitos antes de asignar.
- [ ] El campo `interest` retorna uno de: `'compra'`, `'informacion'` o `'consulta'` — sin otros valores.
- [ ] `analyzeHistory()` filtra solo mensajes con `role === 'user'` — el asistente no genera señales de compra.
- [ ] El análisis de historial nunca puede sumar más de 25 puntos al score total.

---

## 3. Persistencia (LeadRepository)

- [ ] El upsert usa `session_hash` como clave — no email ni phone.
- [ ] El score solo se actualiza si el nuevo es mayor (no degrada información).
- [ ] Los campos PII solo se actualizan si el nuevo valor es no-nulo y el anterior era nulo (no sobreescribir).
- [ ] `session_hash` es `hash('sha256', $sessionId)` — nunca el `session_id` crudo.
- [ ] Toda query incluye `tenant_id` en el WHERE.
- [ ] `$wpdb->prepare()` en todas las queries — sin concatenación directa de variables.

---

## 4. Consentimiento (ConsentController + widget useChat)

- [ ] `POST /consent/lead` registra correctamente los 3 flags en `wp_infouno_lead_consents`.
- [ ] `ip_hash` y `user_agent_hash` se almacenan hasheados — nunca en texto plano.
- [ ] El widget dispara el consent screen si:
  - (a) el mensaje contiene una keyword de intención (hasLeadIntent), O
  - (b) el usuario llegó a `LEAD_CONSENT_FALLBACK_AFTER_MSGS` mensajes sin intent keyword.
- [ ] El `userMsgCount` ref se incrementa en `sendMessage()`, no en `doStream()`.
- [ ] `leadAsked.current` se setea a `true` tanto al aceptar como al rechazar — no vuelve a aparecer.
- [ ] El mensaje pendiente se despacha correctamente después de la decisión del usuario.

---

## 5. Panel Admin (LeadDashboard)

- [ ] `getCurrentTenantId()` se llama antes de cualquier query en `renderPage()`, `exportCsv()` y `updateLeadStatus()`.
- [ ] Los 5 status válidos están en whitelist: `['new', 'contacted', 'interested', 'converted', 'lost']`.
- [ ] `check_admin_referer()` verificado en `exportCsv()` y `updateLeadStatus()`.
- [ ] El status `interested` está disponible en los filtros y en el select inline.
- [ ] `contacted_at` se setea al pasar a `contacted` (solo si NULL).
- [ ] `converted_at` se setea al pasar a `converted` (solo si NULL).
- [ ] CSV exportado comienza con BOM UTF-8 (`\xEF\xBB\xBF`).
- [ ] El CSV incluye las columnas actualizadas: `page_url` si está disponible, status actualizado con 'interested'.

---

## 6. API REST (LeadController)

- [ ] `GET /leads` filtra por `tenant_id` del usuario logueado y ordena por `score DESC, created_at DESC`.
- [ ] `PUT /leads/{id}/status` verifica ownership del lead antes de actualizar.
- [ ] Respuestas HTTP semánticas: 200, 400, 401, 403, 404, 422 según el caso.
- [ ] `session_hash` no aparece en el payload de respuesta JSON.
- [ ] El endpoint soporta `?status=interested` como filtro válido.

---

## 7. Notificación por Email (Plugin::onLeadCaptured)

- [ ] El email incluye los datos de contacto disponibles (name, email, phone) cuando el usuario los consintió.
- [ ] El destinatario es el `user_email` del propietario del `tenant_id` del lead.
- [ ] El subject incluye el score y el tipo de interés.
- [ ] El cuerpo incluye la prioridad (ALTA/MEDIA) con el criterio de urgencia (5 minutos = 9x conversión).
- [ ] El link al panel apunta a `admin.php?page=infouno-leads`.

---

## 8. Integridad de Datos

- [ ] `tokens_used` no fue modificado en ningún cambio de esta sesión.
- [ ] `score` en ningún caso puede decrecer (ver `ia/guardrails/commercial-data-integrity.md`).
- [ ] PII no aparece en ningún log de PHP o JS.
- [ ] El soft delete de sesión (DELETE /session) anonimiza correctamente y no borra tokens.
