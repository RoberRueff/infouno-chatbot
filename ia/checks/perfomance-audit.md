# Auditoría de Rendimiento, Seguridad y Calidad Comercial

Checklist obligatorio antes de dar cualquier tarea por finalizada. El objetivo es mantener el SaaS rápido, seguro, económicamente viable y comercialmente efectivo.

> **Nota:** El nombre de este archivo contiene un error tipográfico histórico (`perfomance`). No renombrar hasta coordinar la actualización de todas las referencias en `CLAUDE.md` e `ia/claude.md`.

---

## ⚡ Rendimiento en Servidor (PHP / WordPress)

- [ ] **Optimización SQL:** Las queries usan `$wpdb->prepare()` y filtran por campos indexados (`tenant_id`, `session_id`, `conversation_id`). Incluyen `LIMIT` para evitar colapsos de memoria.
- [ ] **Bootstrapping mínimo:** El endpoint `/chat` carga solo lo estrictamente necesario de WordPress. No inicializa plugins pesados de terceros.
- [ ] **Manejo de memoria:** Se limpian buffers y se cierran conexiones abiertas si el streaming supera el timeout (15 s). Usar `connection_aborted()` para detectar cliente desconectado.
- [ ] **Recursos liberados explícitamente:** Conexiones al LLM, queries y streams se cierran en bloques `finally`.
- [ ] **Paginación en historial:** Toda query de historial incluye `LIMIT` + `OFFSET`. No se carga el historial completo en una sola query.

---

## 💸 Economía de Tokens (Integración de IA)

- [ ] **Ventana de contexto truncada:** El historial enviado al LLM está limitado a los últimos N mensajes en `ChatService::buildMessages()`.
- [ ] **Historial desde servidor:** El contexto del LLM se reconstruye desde `wp_infouno_messages`, nunca desde el payload del widget.
- [ ] **Modelo correcto por defecto:** Se usa `claude-haiku-4-5-20251001` o `gpt-4o-mini` para el flujo común. Modelos caros solo si el plan los permite.
- [ ] **Tokens registrados:** `tokens_input` y `tokens_output` se guardan en `wp_infouno_messages` tras cada respuesta, incluso si llega incompleta.
- [ ] **Cuota verificada pre-vuelo:** `TenantManager::validateForChat()` se ejecuta antes de abrir la conexión con el LLM.
- [ ] **Estrategia de fallback:** Timeout y error 429 del LLM manejados con exponential backoff + fallback cruzado Anthropic ↔ OpenAI.

---

## 📦 Eficiencia en Cliente (Widget Frontend)

- [ ] **Shadow DOM activo:** El widget encapsula todo HTML y CSS dentro de un Shadow Root.
- [ ] **Sin memory leaks:** Se limpian listeners, MutationObservers y timers cuando el chat se desmonta o cierra.
- [ ] **Bundle bajo 50 KB:** El script compilado pesa menos de 50 KB gzipped. Verificar con `npm run build`.
- [ ] **Sin `any` en TypeScript:** Todos los tipos están definidos explícitamente en `src/types.ts`.
- [ ] **Payload limpio:** El JSON del backend al widget omite metadatos innecesarios.
- [ ] **Quick Replies visibles solo antes del primer mensaje:** `hasUserMessages` controla la visibilidad.

---

## 🔒 Seguridad

- [ ] **Aislamiento de tenant:** Toda query a tablas `wp_infouno_*` incluye `WHERE tenant_id = %d`.
- [ ] **Sanitización de inputs:** Datos del widget y dashboard pasan por `sanitize_text_field()`, `absint()` o `esc_sql()`.
- [ ] **Sin credenciales expuestas:** Ninguna API Key, token ni contraseña en código fuente, respuestas JSON ni logs.
- [ ] **Nonce verificado:** Formularios AJAX del dashboard incluyen y verifican nonce de WordPress.
- [ ] **Prompt injection bloqueado:** Input del usuario pasa por `InputGuard::validateMessage()` antes del LLM.
- [ ] **Bot token no logueado:** `public_token` nunca aparece en texto plano en logs.

---

## 🎯 Calidad Comercial — Lead Engine

- [ ] **Consent gate activo:** `LeadService::getConsents()` verifica `wp_infouno_lead_consents` antes de cualquier scoring o persistencia de PII.
- [ ] **PII filtrada por consent:** Cada campo PII detectado se persiste solo si el flag correspondiente es `1`.
- [ ] **Score calculado por LeadScorer únicamente:** No se asignan scores manualmente desde endpoints o panel.
- [ ] **Score no decrece:** El upsert en `LeadRepository` usa `GREATEST(score, nuevo_score)` o lógica PHP equivalente.
- [ ] **Hook `infouno_lead_captured` solo en score ≥ 60:** El threshold de calificación está respetado.
- [ ] **Email de notificación incluye datos de contacto disponibles:** El tenant puede actuar desde el email sin abrir el panel.
- [ ] **`session_hash` es SHA-256 del `session_id`:** Nunca el `session_id` crudo en la BD de leads.
- [ ] **PII no aparece en logs:** Ningún `error_log()` o debug incluye name/email/phone.
- [ ] **`page_url` no contiene query strings con datos sensibles:** Sanitizar antes de persistir.

---

## 📊 Calidad del Pipeline Comercial

- [ ] **Status transitions válidas:** Los cambios de status en leads siguen el flujo `new → contacted → interested → converted | lost`.
- [ ] **Timestamps de status seteados correctamente:** `contacted_at` al pasar a `contacted`; `converted_at` al pasar a `converted`. Solo si aún son `NULL`.
- [ ] **Soft delete respetado:** No se hacen queries sobre leads con `deleted_at IS NOT NULL` (si se implementa en el futuro).
- [ ] **Datos numéricos de negocio intactos:** `tokens_used`, `quota_used`, `score` no decrementados.
- [ ] **Export CSV con BOM UTF-8:** Verificar que el CSV comienza con `\xEF\xBB\xBF` para compatibilidad Excel Argentina.

---

## 📋 Checklist de Entrega Final

Antes de reportar la tarea como completa:

1. ✅ Todos los ítems relevantes de este checklist verificados
2. ✅ `composer package-lint` sin errores
3. ✅ `npm run build` sin errores (si se tocó el widget)
4. ✅ Respuesta en formato `ia/templates/task-completion.md`
