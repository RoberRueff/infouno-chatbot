# Reglas del Frontend: Chatbot Widget

El widget debe ser ligero, no intrusivo y completamente aislado del entorno donde se ejecute. Vive en la subcarpeta `plugins/infouno-custom/client-widget/`.

## 📦 Arquitectura del Widget (TypeScript / React)
1. **Shadow DOM Obligatorio:** Todo el HTML y el CSS del widget de chat debe renderizarse dentro de un nodo *Shadow Root*. Esto evita de forma absoluta que el CSS de la web del cliente rompa el diseño del chat o viceversa.
2. **Empaquetado Limpio:** Debe compilarse con Vite en un único archivo JavaScript auto-ejecutable (`widget.js`). No debe requerir la carga externa de dependencias adicionales (como CDNs de fuentes o iconos).
3. **Conexión Eficiente:** Debe consumir el endpoint de WordPress vía `fetch()` nativo, procesando la lectura del buffer línea por línea para renderizar el efecto "máquina de escribir" del streaming recibido.

## 🔒 Seguridad en Cliente
1. **Autenticación del Widget:** El widget debe validar su identidad enviando el token público del bot asignado al inicializarse.
2. **Gestión de Errores de Red:** Si el servidor de WordPress no responde, el widget debe mostrar un mensaje de "Servicio momentáneamente desconectado" amigable, sin lanzar errores no controlados a la consola del navegador del cliente.
3. **CORS Estricto por Tenant:** El endpoint de WordPress debe validar que el dominio de origen de la petición (`Origin`) coincida con los dominios autorizados registrados por el Tenant en su panel de control.

## 🌐 Aislamiento de Entorno y Rendimiento
1. **Prevenir Fugas de Memoria:** El widget debe limpiar listeners globales (`window.addEventListener`), observadores de mutación (`MutationObserver`) y temporizadores (`setInterval`) cuando el chat se minimice o se destruya.
2. **Lazy Loading:** El script principal debe pesar menos de 50 KB gzipped y debe posponer la carga de recursos pesados (como librerías de Markdown o resaltado de código) hasta que el usuario abra el chat por primera vez.
3. **Sin Dependencias Masivas:** Queda prohibido usar jQuery, Bootstrap o cualquier framework de gran tamaño. El código debe ser React/Preact ultra-optimizado o Vanilla JS nativo.

## ✅ Buenas Prácticas Obligatorias
- **Tipado estricto:** Queda prohibido usar `any` en TypeScript. Todos los tipos del widget deben estar definidos en `src/types.ts`.
- **Componentes pequeños y enfocados:** Cada componente React debe tener una única responsabilidad visual. La lógica de negocio debe residir en los hooks de `src/hooks/`, no en los componentes.
- **Sin estilos globales:** No aplicar estilos directamente al elemento `body` ni a elementos del objeto global `window`. Todos los estilos deben vivir dentro del Shadow DOM en `src/styles/widget.css`.
- **Build antes de commit:** El widget debe compilar sin errores (`npm run build`) antes de confirmar cualquier cambio. No se deben subir archivos de `dist/` al repositorio si están en `.gitignore`.

## 🚫 Restricciones para la IA
- NO modifiques `vite.config.ts` sin revisar el impacto en el tamaño final del bundle.
- NO introduzcas dependencias nuevas en `package.json` sin evaluar su peso gzipped y su necesidad real.
