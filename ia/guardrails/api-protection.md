# Guardrails Financieros: Protección de APIs y Costos (`api-protection.md`)

El consumo de LLMs genera costos directos. Este archivo previene la quiebra financiera por código defectuoso o ataques de denegación de servicio (DoS/Prompt Flooding).

## 🚨 Líneas Rojas (Bloqueos Automáticos)
1. **Validación de Saldo/Cuota Pre-Vuelo:** El endpoint de chat (`/infouno/v1/chat`) debe ejecutar una verificación de cuota disponible en el plan del tenant *antes* de abrir la conexión con el proveedor de IA. Si está en cero, debe cortar la petición inmediatamente.
2. **Rate Limiting por IP/Sesión:** Queda prohibido programar respuestas del bot que no estén protegidas por un límite de velocidad (ej. máximo 5 mensajes por minuto por usuario final).
3. **Timeout Forzado:** Cualquier llamada hacia APIs externas de IA debe configurar un timeout estricto en PHP (máximo 15 segundos). La IA no puede dejar conexiones abiertas indefinidamente esperando al proveedor.
4. **Límite de Contexto (Tokens Máximos):** La IA no debe enviar historiales de conversación infinitos al LLM. El código debe truncar obligatoriamente el contexto (ej. máximo los últimos 10-15 mensajes) para evitar spikes de consumo de tokens.

5. **Validación de Dominio:** Queda prohibido responder peticiones de streaming si el dominio de origen (`HTTP_REFERER` u `Origin`) no coincide con la lista blanca del Tenant.

## 🛑 Acción Requerida si se cruza un límite
Si la IA detecta que un cambio de código elimina un control de cuotas o un límite de velocidad, debe abortar el cambio y emitir la alerta:
`[GUARDRAIL TRIGGERED: PROTECCIÓN FINANCIERA DESACTIVADA]`.