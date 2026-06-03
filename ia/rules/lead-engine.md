# Reglas de Desarrollo — Lead Engine

El Lead Engine es la **capa comercial central** del producto. Transforma conversaciones en leads calificados, activa notificaciones y alimenta el pipeline de ventas. Cualquier error aquí impacta directamente en los ingresos de los tenants.

> **Cargar también:** `ia/guardrails/lead-pii-protection.md` + `ia/guardrails/commercial-data-integrity.md` antes de cualquier cambio.

---

## Componentes del Lead Engine

```
LeadService.php      → Orquestador: verifica consent, delega scoring, filtra PII, persiste, dispara hook.
LeadScorer.php       → Análisis: regex patterns argentinos, score 0-100, extracción PII.
LeadRepository.php   → Persistencia: upsert en wp_infouno_leads por session_hash.
LeadDashboard.php    → Admin panel: stat cards, filtros, export CSV, update inline de status.
ConsentController.php → POST /consent/lead — registra consentimiento PII granular.
LeadController.php   → GET /leads (paginado) + PUT /leads/{id}/status.
```

---

## Reglas de Orquestación (LeadService)

1. **Best-effort obligatorio:** `LeadService::processMessage()` siempre se llama dentro de `try/catch(\Throwable)` en `ChatService`. Un fallo en el Lead Engine NUNCA interrumpe el chat del usuario.

2. **Consentimiento primero:** La primera acción de `processMessage()` es consultar `wp_infouno_lead_consents`. Si no hay registro o todos los campos son `0`, retornar inmediatamente sin scoring ni persistencia.

3. **Filtrado de PII por consentimiento:** Solo se incluyen en `$toSave` los campos para los cuales `can_capture_X = 1`. Un campo PII detectado sin consent explícito se descarta silenciosamente.

4. **Threshold de persistencia:** No guardar si `score < 1` Y `hasPii === false`. Un lead con score 0 y sin datos de contacto no tiene valor comercial.

5. **Hook solo en leads calificados:** `do_action('infouno_lead_captured', ...)` solo se dispara cuando `score >= 60`. Los leads con score menor se persisten pero no notifican.

---

## Reglas de Scoring (LeadScorer)

6. **Solo mensajes del usuario en historial:** Al analizar `$conversationHistory`, filtrar exclusivamente `role === 'user'`. Los mensajes del asistente no generan señales de compra.

7. **Patrones calibrados para Argentina:** Los patrones regex deben cubrir voseo argentino (`quiero`, `necesito`, `cuánto sale`, `lo tomo`), formas de pago locales (`cuotas`, `mercadopago`, `ahora 12`), y canales de contacto (`WhatsApp`, `wsp`). Ver `docs/lead-scoring-rules.md` para la matriz de puntajes.

8. **Score siempre 0-100:** Usar `min(100, $score)` antes de retornar. Nunca retornar un score negativo.

9. **Phone: validar longitud mínima:** Antes de asignar un phone extraído por regex, verificar que el número limpio tenga al menos 8 dígitos. Los regex de teléfono argentino son permisivos por diseño — la validación de longitud previene falsos positivos como "3 de mayo".

10. **El campo `interest` es categórico:** Solo puede ser `'compra'`, `'informacion'` o `'consulta'`. No inventar otros valores.

11. **`temperature` se deriva siempre del scorer:** No asignar temperatura manualmente en `LeadService`. La temperatura la calcula `LeadScorer::calculateTemperature()` en base al score final y las señales BANT. Escala: `cold` (< 25), `warm` (25-59), `hot` (≥ 60), `ready` (≥ 85 o ≥ 60 con budget + timeline inmediato).

12. **`intent_signals` es JSON estructurado:** Contiene los campos `budget` (bool), `authority` (bool), `timeline` (string|null: `'hoy'|'urgente'|'esta_semana'|'proximo_mes'`), `industry` (string|null), `location` (string|null), `company` (string|null). Se reemplaza en cada actualización del lead — siempre refleja el análisis más reciente del historial completo.

---

## Reglas de Persistencia (LeadRepository)

11. **Upsert por `session_hash`:** El lead de una sesión es único. Usar `INSERT ... ON DUPLICATE KEY UPDATE` o lógica equivalente. No crear duplicados.

12. **Solo actualizar si mejora:** El `score` y los campos PII solo se sobreescriben si el nuevo valor es mejor (score mayor, o campo antes nulo y ahora con dato). Un mensaje posterior no debe degradar información ya capturada.

13. **`session_hash` es SHA-256 del `session_id`:** Nunca almacenar el `session_id` crudo en la tabla de leads.

14. **`conversation_id` es FK nullable:** Puede ser `NULL` si el lead proviene de fuentes futuras (WhatsApp, formulario). El chat siempre lo provee.

15. **`tenant_id` en toda query:** Sin excepción. Ver `ia/guardrails/tenant-isolation.md`.

---

## Reglas del Panel Admin (LeadDashboard)

16. **`getCurrentTenantId()` antes de cualquier query:** El panel muestra exclusivamente leads del tenant del usuario logueado. Nunca omitir esta verificación.

17. **Nonce en formularios:** `check_admin_referer()` en `exportCsv()` y `updateLeadStatus()`. Sin nonce válido → `wp_die(403)`.

18. **Status válidos en whitelist:** Solo `['new', 'contacted', 'interested', 'converted', 'lost']` son aceptados en `updateLeadStatus()`. Cualquier otro valor → ignorar y redirigir.

19. **Timestamps automáticos por status:** Al pasar a `contacted` → setear `contacted_at`. Al pasar a `converted` → setear `converted_at`. Solo setear si aún es `NULL` (no sobrescribir).

20. **CSV con BOM UTF-8:** El archivo exportado debe iniciar con `\xEF\xBB\xBF` para compatibilidad con Excel en Windows (obligatorio para el mercado argentino).

---

## Reglas de la API REST (LeadController)

21. **GET /leads:** Siempre filtrar por `tenant_id` del usuario logueado. Soportar `?status=` y `?page=`. Ordenar por `score DESC, created_at DESC`. Límite configurable, default 50.

22. **PUT /leads/{id}/status:** Verificar ownership (`tenant_id` del lead == `tenant_id` del usuario) antes de actualizar. Retornar `404` si el lead no pertenece al tenant — no `403` (no revelar que existe).

23. **Respuestas HTTP semánticas:** `200` OK, `400` datos inválidos, `401` sin auth, `403` sin permiso, `404` no encontrado, `422` status inválido.

---

## Flujo de Datos del Lead Engine

```
ChatService::handle()  (paso 12)
    └── LeadService::processMessage($tenantId, $botId, $sessionId, $convId, $userMessage, $history)
            │
            ├── getConsents($sessionId, $botId)  → 1 query a wp_infouno_lead_consents
            │       └── si vacío → return (sin procesamiento)
            │
            ├── LeadScorer::analyze($userMessage, $history)
            │       └── extracción PII + score 0-100
            │
            ├── Filtro: descarta PII sin consent explícito
            │
            ├── LeadRepository::save($toSave)  → upsert en wp_infouno_leads
            │       └── retorna lead_id
            │
            └── si score >= 60 && lead_id > 0:
                    do_action('infouno_lead_captured', $leadId, $tenantId, $botId, $result)
                            └── Plugin::onLeadCaptured() → wp_mail() con datos de contacto
```

---

## Restricciones para la IA

- NO interrumpas el flujo de chat por fallos del Lead Engine. Siempre `try/catch(\Throwable)`.
- NO almacenes PII sin verificar previamente los flags de consentimiento en `wp_infouno_lead_consents`.
- NO dispares `infouno_lead_captured` para leads con score < 60.
- NO uses `DELETE` físico en `wp_infouno_leads`. Si un lead debe eliminarse, marcarlo con un status especial o anonymizar en coordinación con el derecho de supresión (Ley 25.326).
- NO crees una nueva clase en el namespace `Lead\` sin registrarla en `Plugin.php` y en `ia/taxonomy.md`.
- NO modifiques el algoritmo de scoring sin actualizar `docs/lead-scoring-rules.md`.
