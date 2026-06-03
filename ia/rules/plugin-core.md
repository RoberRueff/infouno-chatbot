# Reglas de Desarrollo: Core del SaaS

Este componente maneja la autenticación de usuarios, la validación de planes/suscripciones y el aislamiento de las operaciones de cada cliente.

## 🔑 Reglas de Oro de Seguridad
1. **Tenant Validation Obligatoria:** Cualquier acción ejecutada en el backend debe validar primero el `tenant_id` del usuario actual en la sesión o en las cabeceras REST (vía JWT).
2. **Capacidad y Cuotas:** Antes de procesar cualquier interacción de chat o creación de bot, verifica el estado de la suscripción del tenant (`active`, `paused`, `over_quota`).
3. **No Hardcodear Credenciales:** Las llaves de API maestras o del sistema deben guardarse en constantes de `wp-config.php` o recuperarse encriptadas desde las opciones de la red.

## 🛠️ Estructura de Código y Patrones (PHP)
1. **Namespaces por módulo:** El namespace raíz del plugin es `Infouno\SaaS\`. Cada subcarpeta tiene su propio namespace: `Infouno\SaaS\Core`, `Infouno\SaaS\API`, `Infouno\SaaS\Bot`, `Infouno\SaaS\Chat`, `Infouno\SaaS\LLM`, `Infouno\SaaS\Tenant`, `Infouno\SaaS\Security`. Queda prohibido crear clases fuera de esta estructura.
2. **Separación de responsabilidades:** Los hooks de WordPress solo deben llamar a métodos de clases controladoras. Queda prohibido escribir lógica de negocio directamente dentro de un closure de `add_action` o `add_filter`.
3. **Endpoints REST:** Todos los endpoints personalizados deben registrarse bajo la ruta `/infouno/v1/` vía `RestRouter.php` y usar obligatoriamente el parámetro `permission_callback` para validar que el usuario tiene permiso de acceso al tenant específico.

## 🔄 Ciclo de Vida y Ejecución
1. **Optimización de Carga:** Para el endpoint de chat (`/infouno/v1/chat`), evalúa si es posible minimizar la carga de plugins no esenciales usando el hook `plugins_loaded` con prioridad alta para responder en menos de 100 ms.
2. **Sanitización Estricta de Webhooks:** Si se reciben webhooks de pasarelas de pago (Stripe, MercadoPago) para activar o desactivar tenants, la firma del webhook debe verificarse antes de modificar el estado en la base de datos.
3. **Manejo de Errores HTTP:** Todos los fallos de lógica de negocio (ej. tenant sin saldo) deben retornar códigos de estado HTTP semánticos (`402 Payment Required`, `403 Forbidden`) en formato `WP_Error`, nunca un código 200 con un string de error.

## ✅ Buenas Prácticas Obligatorias
- **Activación y desactivación controladas:** Toda lógica de instalación de tablas o configuración inicial debe ejecutarse en `Activator.php`. Toda lógica de limpieza debe ejecutarse en `Deactivator.php`. Nunca en el archivo raíz del plugin.
- **Migraciones de esquema versionadas:** Cualquier cambio de base de datos debe pasar por `Migrator.php` con número de versión incremental, nunca ejecutarse directamente desde un hook.
- **Roles custom del SaaS:** No asumir que el usuario logueado es un administrador global de WordPress. Manejar siempre los roles custom del SaaS (`tenant_admin`, `tenant_agent`) para evaluar permisos.
- **Nonce y sanitización obligatorios:** Nunca eliminar ni saltear `wp_verify_nonce()`, `sanitize_text_field()` o `absint()` en ningún flujo de entrada de datos.

## 🚫 Restricciones para la IA
- NO registres endpoints REST fuera de `RestRouter.php`.
- NO escribas lógica de negocio en el archivo raíz `infouno-custom.php`; ese archivo solo debe instanciar `Plugin.php`.
- NO crees clases fuera del namespace `Infouno\SaaS\` ni fuera de la carpeta `src/`.
