# Guardrails de Infraestructura: Prevención de Abuso de Recursos

Manejar conexiones de streaming abiertas en PHP es costoso para el servidor. Este archivo evita que un ataque volumétrico sature los hilos de ejecución de WordPress/Nginx.

## 🚨 Líneas Rojas (Bloqueos Automáticos)
1. **Límite de Longitud de Entrada:** El widget y el backend deben rechazar de inmediato cualquier mensaje de usuario final que supere un límite razonable de caracteres (ej. máximo 1.000 caracteres por mensaje). Esto evita ataques de desbordamiento de tokens (*Token Exhaustion Attacks*).
2. **Control de Conexiones Concurrentes por Tenant:** El sistema debe limitar la cantidad de streams de chat activos simultáneamente para un mismo token de cliente (ej. máximo 50 chats concurrentes en el plan básico). Si se supera, las nuevas peticiones deben encolarse o devolver un mensaje de "Servidor ocupado".
3. **Cierre Forzado de Sesiones Inactivas:** Queda prohibido mantener registros de sesión activos en memoria para usuarios del widget que lleven más de 15 minutos sin interactuar. El script debe forzar la expiración de la sesión para liberar recursos de la base de datos custom.
4. **Validación del Tamaño del Payload HTTP:** El backend debe rechazar peticiones cuyo `Content-Length` supere el umbral configurado antes de leer el body completo. Queda prohibido procesar payloads sin validar su tamaño a nivel de endpoint.

## ✅ Buenas Prácticas Obligatorias
- **Liberar recursos explícitamente:** Todo bloque de código PHP que abra una conexión al LLM, una consulta a la BD o un stream debe cerrarlo explícitamente en un bloque `finally`, nunca depender del garbage collector.
- **Monitorización de tiempos de respuesta:** Los endpoints de streaming deben registrar su tiempo de inicio y fin. Si un stream supera el timeout configurado (15 s), debe cerrarse por fuerza y loguearse como anomalía.
- **No bloquear el hilo principal con operaciones pesadas:** Tareas como la regeneración de embeddings o el procesamiento batch del Knowledge Base no deben ejecutarse en el mismo hilo de la petición HTTP. Deben delegarse a un proceso en segundo plano (ej. WP-Cron o una cola de trabajos).
- **Paginación obligatoria en consultas de historial:** Queda prohibido cargar el historial completo de conversaciones en una sola consulta SQL. Toda consulta de historial debe incluir `LIMIT` y `OFFSET` para evitar saturar la memoria del servidor.

## 🛑 Acción Requerida si se cruza un límite
Si un cambio de código elimina las restricciones de tamaño de payload o permite que PHP corra hilos infinitos sin control de concurrencia, la IA debe detenerse:
`[GUARDRAIL TRIGGERED: RIESGO DE AGOTAMIENTO DE RECURSOS DE SERVIDOR]`
