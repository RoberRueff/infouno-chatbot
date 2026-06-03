# Guardrails de Seguridad: Aislamiento de Tenants

ESTE ARCHIVO CONTIENE REGLAS ABSOLUTAS DE SEGURIDAD. LA IA NO PUEDE VIOLAR ESTOS LÍMITES BAJO NINGUNA CIRCUNSTANCIA.

## 🚨 Líneas Rojas (Bloqueos Automáticos)
1. **Filtro de Inquilino Mandatorio:** Queda ESTRICTAMENTE PROHIBIDO generar cualquier consulta SQL (`SELECT`, `UPDATE`, `DELETE`) a las tablas custom (`wp_infouno_...`) que no incluya una cláusula explícita de validación de `tenant_id` o `bot_id` previamente verificado.
2. **Validación de Propiedad Cruzada:** Antes de permitir que un usuario modifique un Bot, vea un historial o altere un setting, el sistema debe comprobar que el `tenant_id` del recurso coincide exactamente con el `tenant_id` de la sesión del usuario. La IA tiene prohibido asumir que "si el ID existe, el usuario tiene acceso".
3. **Aislamiento de Llaves de API:** Las claves de API (OpenAI, Anthropic, etc.) configuradas por un tenant específico jamás deben ser legibles por endpoints públicos ni por otros tenants. Su desencriptación solo ocurre en memoria del servidor durante la ejecución del streaming al LLM.
4. **Prohibición de Consultas Globales sin Autorización:** Queda prohibido generar consultas que recorran todas las filas de una tabla cross-tenant (sin filtro de `tenant_id`) salvo que la solicitud provenga explícitamente del super-administrador y haya sido confirmada por el humano.

## ✅ Buenas Prácticas Obligatorias
- **El `tenant_id` siempre desde la sesión, nunca del input:** El `tenant_id` usado en cualquier consulta debe obtenerse exclusivamente de la sesión autenticada del servidor. Queda prohibido leerlo del body de la petición, de un parámetro GET o de cualquier dato controlable por el usuario final.
- **Capas de verificación independientes:** La validación de `tenant_id` debe ocurrir en dos niveles: en el Controller (antes de llamar al Service) y en el Repository (en la propia query SQL). Una sola capa no es suficiente.
- **Tests de aislamiento obligatorios:** Cualquier nueva funcionalidad que acceda a datos de tenant debe incluir al menos un test que verifique que un tenant B no puede leer ni modificar los datos de un tenant A.
- **Logs de acceso cross-tenant:** Todo intento de acceso a un recurso cuyo `tenant_id` no coincida con la sesión activa debe registrarse como un evento de seguridad, nunca ignorarse silenciosamente.

## 🛑 Acción Requerida si se cruza un límite
Si la tarea asignada requiere modificar la estructura de aislamiento o realizar una consulta global (ej. para analíticas del super-administrador), **la IA debe detenerse inmediatamente** y solicitar confirmación humana con el mensaje:
`[GUARDRAIL TRIGGERED: SOLICITUD DE ACCESO GLOBAL DE DATOS]`
