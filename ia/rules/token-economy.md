# Estrategia de Economía de Tokens y Optimización de Costos

Reglas obligatorias para minimizar el gasto en APIs externas de Inteligencia Artificial sin degradar la experiencia del usuario final.

## 📉 Directrices de Diseño Económico
1. **Estrategia de Ventana Deslizante (Sliding Window):** El historial de conversación enviado al LLM no debe acumularse indefinidamente. Implementa un límite estricto de los últimos N mensajes (ej. últimos 10 mensajes) para mantener el coste por petición plano.
2. **Resúmenes Asíncronos (Chat Summarization):** Si la conversación es muy larga y el cliente requiere memoria de largo plazo, el sistema debe generar un resumen de máximo 3 líneas cada 15 mensajes y enviar solo ese resumen más los 3 últimos mensajes, reduciendo el consumo de tokens en hasta un 60%.
3. **Modelos Híbridos (Smart Routing):** El backend debe usar un modelo económico (ej. `claude-haiku-4-5-20251001` o `gpt-4o-mini`) para el flujo de chat común de soporte, y reservar modelos potentes solo si el tenant activa explícitamente funciones complejas como análisis de documentos o integraciones avanzadas.
4. **Historial reconstruido desde el servidor:** El backend debe reconstruir el historial de conversación activo leyendo desde la base de datos (`wp_infouno_messages`), nunca desde el payload enviado por el Widget. Confiar en el historial del cliente es una vulnerabilidad de seguridad que permite manipular el contexto del LLM.

## 📊 Métricas de Control de Costos
1. **Registro de tokens por mensaje:** Cada mensaje procesado debe registrar `tokens_used` (input + output) en `wp_infouno_messages` para permitir auditorías de consumo por tenant.
2. **Alertas de cuota:** El sistema debe evaluar el saldo disponible del tenant antes de cada petición al LLM. Si el saldo cae por debajo del 10% del plan contratado, debe notificarse al `tenant_admin` de forma proactiva.
3. **Techo de gasto por bot:** Cada bot debe tener un límite máximo de tokens por conversación configurable. Si se alcanza, el flujo debe cerrarse con un mensaje amigable en lugar de continuar consumiendo cuota.

## ✅ Buenas Prácticas Obligatorias
- **Truncar antes de enviar:** El truncado de historial debe aplicarse en `ChatService.php` antes de construir el payload para el LLM, nunca dentro del proveedor (`AnthropicProvider.php`, `OpenAIProvider.php`).
- **Caché de system prompt:** El prompt base del bot, que no cambia entre mensajes, debe cachearse en memoria durante la ejecución del streaming para no releerlo de la base de datos en cada chunk.
- **Separar input de output en el registro:** Al guardar `tokens_used`, registrar el desglose `tokens_input` y `tokens_output` por separado para facilitar el análisis de costos y la detección de prompts excesivamente largos.
- **Evaluar coste antes de activar funciones avanzadas:** Antes de enrutar una petición a un modelo caro, el `LLMRouter.php` debe verificar que el plan del tenant incluye ese nivel de servicio.

## 🚫 Restricciones para la IA
- NO confíes en el array de historial enviado por el Widget para construir el contexto del LLM. Siempre reconstruye desde `wp_infouno_messages`.
- NO uses modelos de alta capacidad como valores por defecto. El modelo económico es siempre el fallback base.
- NO omitas el registro de `tokens_used` aunque la respuesta del LLM llegue incompleta o con error.
