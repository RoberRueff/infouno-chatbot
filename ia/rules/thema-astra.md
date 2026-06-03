# Reglas de Interfaz: Tema Astra y Dashboard

El tema Astra se encarga de la presentación de la Landing Page del SaaS y de encapsular el Dashboard de administración de los tenants.

## 🎨 Estructura del Panel de Configuración
1. **Uso de Child Theme:** Cualquier alteración del comportamiento, plantillas de página o funciones estéticas debe hacerse en el tema hijo (`astra-child/`), dejando el núcleo de Astra intacto para recibir actualizaciones seguras.
2. **Separación de Vistas:** El panel donde el tenant gestiona sus bots, prompts e historiales debe crearse mediante plantillas de página personalizadas de WordPress (`page-templates/`) o consumiendo componentes SPA integrados.
3. **Consistencia Estética:** Utilizar las variables globales de color y tipografía configuradas en el Customizer de Astra para mantener la cohesión visual del producto.

## 🔌 Interacción con el Plugin Custom
1. **Sin lógica de datos en el tema:** El tema nunca procesa lógica de bases de datos custom de IA directamente en `functions.php`. Toda la data que el tema requiera mostrar debe solicitarse a los métodos públicos del plugin `infouno-custom`.
2. **Sin dependencias cruzadas:** El tema hijo no debe importar ni instanciar clases del namespace `Infouno\SaaS\` directamente. La comunicación se hace exclusivamente a través de hooks de WordPress o llamadas a la REST API del plugin.

## 💻 Reactividad y UX en el Dashboard
1. **Asincronismo Obligatorio (AJAX/Fetch):** Cualquier cambio de configuración del bot (actualizar el prompt base, cambiar de modelo, guardar credenciales) debe procesarse en segundo plano mediante Fetch API y mostrar estados de carga visuales (`spinners`). Queda prohibido recargar la página completa.
2. **Prevención de Doble Envío:** Los formularios críticos (como la compra de paquetes de tokens o actualización de planes) deben deshabilitar sus botones inmediatamente tras el primer clic para evitar cargos o registros duplicados.
3. **Encapsulamiento de Estilos:** Todos los estilos del Dashboard del SaaS deben estar bajo el contenedor raíz `.infouno-dashboard`. Queda prohibido aplicar estilos globales que puedan afectar páginas fuera del contexto del panel.

## ✅ Buenas Prácticas Obligatorias
- **Enqueue correcto de assets:** Los scripts y estilos del tema hijo deben encolarse siempre con `wp_enqueue_script()` y `wp_enqueue_style()`, nunca con etiquetas `<script>` o `<link>` hardcodeadas en plantillas.
- **Nonces en formularios AJAX:** Todo formulario del Dashboard que envíe datos al backend mediante Fetch debe incluir un nonce de WordPress verificado en el servidor.
- **Feedback visual siempre presente:** Toda acción asíncrona debe tener tres estados visuales definidos: cargando (spinner), éxito (confirmación) y error (mensaje descriptivo). Queda prohibido dejar al usuario sin retroalimentación tras una acción.
- **Sin lógica de negocio en plantillas:** Los archivos de plantilla (`.php` en `page-templates/`) solo deben contener HTML y llamadas a funciones de presentación. Toda lógica de validación o consulta debe delegarse al plugin.

## 🚫 Restricciones para la IA
- NO edites directamente ningún archivo de la carpeta raíz de `themes/astra/`.
- NO alteres las clases CSS estructurales del core de Astra de forma global.
- NO añadas lógica de base de datos, queries SQL ni llamadas directas a `$wpdb` en ningún archivo del tema hijo.
