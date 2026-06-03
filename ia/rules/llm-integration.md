# Reglas de Integración: API de Inteligencia Artificial

Este módulo expone el endpoint que consume el widget para comunicarse con los modelos de lenguaje y procesa las respuestas en streaming.

## 🚀 Requerimientos de Streaming
1. **Server-Sent Events (SSE):** Las respuestas hacia el frontend del chatbot deben enviarse en tiempo real utilizando streaming de PHP (`ob_flush()`, `flush()`), respondiendo con la cabecera `Content-Type: text/event-stream`.
2. **Ejecución sin Bloqueo:** El backend debe configurar `set_time_limit(0)` para procesos largos de streaming. No se debe bloquear la ejecución PHP esperando a que la API remota responda completa.
3. **Inyección Dinámica del Contexto:** Al armar la petición para el LLM, el prompt del sistema debe componerse dinámicamente juntando:
   - El prompt base del bot (guardado en base de datos).
   - El historial de los últimos N mensajes de esa sesión específica.
   - Datos opcionales del contexto actual del tenant (ej. información del negocio).

## 📈 Conteo y Facturación de Tokens
1. **Registro de Uso:** Al finalizar el flujo de streaming, se debe calcular o recuperar el uso exacto de tokens (input y output) y registrarlo inmediatamente en `wp_infouno_messages` asignándolo al consumo del tenant.
2. **Descuento de Cuota:** Tras registrar el uso, el sistema debe descontar los tokens consumidos del saldo del plan activo del tenant en la misma transacción. Queda prohibido registrar el uso sin actualizar la cuota disponible.
3. **Detección de Desconexión:** Si el usuario final cierra la pestaña del navegador a mitad del streaming, el script PHP debe detectar la pérdida de conexión (`connection_aborted()`) y detener la llamada a la API para no consumir tokens innecesariamente.

## 🛡️ Resiliencia, Timeouts y Fallbacks
1. **Estrategia de Reintentos (Exponential Backoff):** Si la API del LLM retorna un error de Rate Limit (429) o Timeout, el código debe implementar hasta 2 reintentos con un delay exponencial antes de declarar una falla.
2. **Proveedor de Respaldo (Fallback Gateway):** El sistema debe estar diseñado para conmutar dinámicamente de proveedor. Si Anthropic falla repetidamente, la clase debe redirigir el tráfico a OpenAI o un modelo alternativo automáticamente según la configuración global.
3. **Timeout Estricto:** Toda llamada a una API externa de LLM debe tener un timeout máximo de 15 segundos configurado explícitamente. Nunca dejar conexiones abiertas indefinidamente.

## ✅ Buenas Prácticas Obligatorias
- **Interfaz de proveedor única:** Todos los proveedores de LLM (Anthropic, OpenAI, etc.) deben implementar `LLMProviderInterface.php`. Queda prohibido llamar directamente a la API de un proveedor sin pasar por el router `LLMRouter.php`.
- **Nunca exponer API Keys:** Las claves de API de los proveedores jamás deben aparecer en las respuestas JSON o de streaming enviadas al navegador del cliente final.
- **Streaming encapsulado:** La lógica de SSE (headers, flush, formato de eventos) debe estar centralizada en una clase o función reutilizable, no duplicada en cada controller.
- **Validación de respuesta del LLM:** Antes de enviar cada chunk al cliente, validar que el fragmento recibido del proveedor no esté vacío ni sea un error enmascarado como contenido.

## 🚫 Restricciones para la IA
- NO llames directamente a `AnthropicProvider.php` u `OpenAIProvider.php` desde un Controller. Toda integración pasa por `LLMRouter.php`.
- NO introduzcas un proveedor nuevo sin crear su clase que implemente `LLMProviderInterface.php`.
