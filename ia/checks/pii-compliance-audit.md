# Auditoría de Cumplimiento PII — Ley 25.326

Ejecutar cuando el cambio involucre: captura, almacenamiento, transmisión o eliminación de datos personales del usuario final del chatbot.

> **Base legal:** Ley 25.326 de Protección de los Datos Personales, Argentina.
> **Alcance:** Aplica a todos los datos del usuario final del chatbot (visitante del sitio del cliente PyME), no al tenant (cliente de InfoUno).

---

## 1. Consentimiento Previo (Art. 5)

- [ ] Ningún dato personal se persiste antes de obtener el consentimiento registrado server-side.
- [ ] El consentimiento general de chat (`scope='chat'`) está registrado en `wp_infouno_consents` antes del primer mensaje del usuario.
- [ ] El consentimiento de lead capture (`scope='lead_capture'`) está registrado en `wp_infouno_lead_consents` antes de persistir cualquier PII.
- [ ] El widget muestra la `ConsentScreen` en el primer uso y no permite enviar mensajes hasta que el usuario acepte.
- [ ] El botón "Eliminar mis datos" siempre es visible en el footer del chat — no puede ocultarse.

---

## 2. Consentimiento Granular (Art. 6)

- [ ] Los tres campos PII (nombre, teléfono, email) tienen consentimientos independientes.
- [ ] El flag `can_capture_name = 0` implica que el nombre detectado se descarta — no se almacena.
- [ ] El flag `can_capture_phone = 0` implica que el teléfono detectado se descarta — no se almacena.
- [ ] El flag `can_capture_email = 0` implica que el email detectado se descarta — no se almacena.
- [ ] El usuario puede rechazar todos los campos y el chat continúa sin limitaciones de funcionalidad (solo sin lead capture).

---

## 3. Finalidad y Minimización (Art. 4)

- [ ] Los datos almacenados son proporcionales al propósito declarado (mejorar la atención y contactar al lead).
- [ ] `wp_infouno_consents` y `wp_infouno_lead_consents` almacenan solo hashes (SHA-256) — no datos personales directos.
- [ ] `session_id` crudo nunca se almacena en la BD — solo `session_hash`.
- [ ] `ip_hash` es SHA-256 de la IP real — no almacenable en texto plano.
- [ ] No se colectan datos que no fueron declarados en el aviso legal mostrado al usuario.

---

## 4. Evidencia de Consentimiento

- [ ] Cada fila de `wp_infouno_consents` incluye: `session_hash`, `ip_hash`, `user_agent_hash`, `consent_version`, `scope`, `accepted_at`.
- [ ] Cada fila de `wp_infouno_lead_consents` incluye: `session_hash`, `tenant_id`, `bot_id`, los tres flags, `consent_version`, `ip_hash`, `user_agent_hash`, `accepted_at`.
- [ ] La `consent_version` corresponde a la versión real del texto legal que se mostró al usuario.
- [ ] No se modifica ni elimina la evidencia de consentimiento una vez registrada.

---

## 5. Derecho de Supresión (Art. 16)

- [ ] `DELETE /infouno/v1/session` está disponible y funcional.
- [ ] La eliminación anonimiza `content` en `wp_infouno_messages` (no borra físicamente).
- [ ] Los `tokens_used` se preservan después de la eliminación (auditoría financiera).
- [ ] El `deleted_at` se setea en la conversación y en los mensajes correspondientes.
- [ ] El `session_id` del widget se resetea en `sessionStorage` después de la eliminación exitosa.
- [ ] Si la eliminación falla en el servidor, el widget advierte al usuario sin resetear la sesión.

---

## 6. Transmisión de PII

- [ ] Los datos de leads (name, email, phone) solo se transmiten al tenant propietario (email de notificación y panel admin).
- [ ] Los datos de leads nunca aparecen en las respuestas SSE del chat endpoint.
- [ ] Los datos de leads nunca aparecen en logs de PHP o de JavaScript.
- [ ] La respuesta de `GET /leads` no incluye `session_hash` ni `ip_hash`.
- [ ] El `bot_token` no aparece en logs en texto plano.

---

## 7. Retención Limitada (Art. 4, inc. e)

- [ ] Los mensajes de usuarios en planes `free` y `trial` tienen `expires_at` seteado (+30 días).
- [ ] Los mensajes de usuarios en planes `premium` y `agency` tienen `expires_at = NULL`.
- [ ] El cron `infouno_purge_expired_messages` está programado y funcional.
- [ ] Los leads en `wp_infouno_leads` tienen una política de retención documentada para el tenant.

---

## 8. Aviso Legal en el Widget

- [ ] La `ConsentScreen` menciona explícitamente la "Ley 25.326 de Protección de Datos Personales".
- [ ] La `LeadConsentScreen` menciona explícitamente la Ley 25.326.
- [ ] Si el tenant configuró `privacyUrl`, el enlace a la Política de Privacidad está presente y funcional.
- [ ] El footer del chat muestra "Ley 25.326" como referencia legal permanente.
- [ ] El aviso de la `ConsentScreen` tiene un número de versión (`consent_version`) para trackear cambios en el texto.

---

## 9. Seguridad de los Datos Almacenados

- [ ] Las columnas `name`, `phone`, `email` en `wp_infouno_leads` no tienen índices (reduce riesgo de queries no autorizadas).
- [ ] El acceso a `wp_infouno_leads` siempre requiere autenticación WP + validación de `tenant_id`.
- [ ] No hay endpoints públicos que expongan PII de leads sin autenticación.
- [ ] En la fase de Opportunity Engine (v7): los datos de PII del lead no se duplican en `wp_infouno_opportunities` — se referencian por `lead_id`.
