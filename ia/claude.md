# Guía de Desarrollo y Arranque — infouno-base

Este archivo es el punto de entrada exclusivo para la IA. Define el comportamiento esperado, comandos rápidos y el flujo estricto de desarrollo para este SaaS Multitenant de Chatbots.

## 📋 Protocolo de Inicio Obligatorio
Antes de sugerir o escribir cualquier línea de código, debes:
1. Leer de inmediato `ia/taxonomy.md` para entender el mapa de componentes actual.
2. Leer `ia/architecture.md` si la tarea toca base de datos, flujo de datos o aislamiento de tenants.
3. Identificar el componente en el que vas a trabajar y cargar su archivo de reglas correspondiente en `ia/rules/`.
4. Validar los límites operativos cargando los archivos relevantes en `ia/guardrails/`.

## 🛠️ Comandos de Desarrollo del Proyecto
* **Backend Linting:** `composer package-lint`
* **Backend Format:** `composer package-format`
* **Widget Build:** `cd plugins/infouno-custom/client-widget && npm run build`
* **Widget Dev:** `cd plugins/infouno-custom/client-widget && npm run dev`
* **Tests:** `composer test` (si aplica)

## 🔄 Flujo de Trabajo para la IA
1. **Fase de Contexto:** Lee `ia/architecture.md` si la tarea toca base de datos, aislamiento de datos o el flujo de streaming. Pide confirmación al humano si hay ambigüedad sobre el alcance.
2. **Fase de Desarrollo:** Escribe código modular aplicando las reglas de `ia/rules/` para el componente afectado.
3. **Fase de Auditoría:** Ejecuta el checklist de `ia/checks/perfomance-audit.md` antes de dar la tarea por buena.
4. **Fase de Entrega:** Responde utilizando el formato estricto de `ia/templates/task-completion.md`.

## 🗂️ Mapa de Archivos de Contexto
| Archivo | Cuándo cargarlo |
|---|---|
| `ia/taxonomy.md` | Siempre, antes de cualquier tarea |
| `ia/architecture.md` | Tareas que tocan BD, flujo SSE o estructura del plugin |
| `ia/rules/plugin-core.md` | Cambios en el núcleo PHP, hooks, endpoints REST |
| `ia/rules/db-schema.md` | Migraciones, nuevas tablas o queries SQL |
| `ia/rules/llm-integration.md` | Cambios en el flujo de streaming o proveedores de IA |
| `ia/rules/token-economy.md` | Cambios en conteo de tokens, cuotas o modelos usados |
| `ia/rules/chatbot-widget.md` | Cambios en el widget React/TypeScript |
| `ia/rules/thema-astra.md` | Cambios en el tema hijo o el dashboard del tenant |
| `ia/guardrails/tenant-isolation.md` | Siempre que se escriba una query SQL o endpoint REST |
| `ia/guardrails/code-quality.md` | Siempre, antes de entregar cualquier código |
| `ia/guardrails/api-protection.md` | Cambios en el flujo de chat o rate limiting |
| `ia/guardrails/llm-safety-output.md` | Cambios en la construcción del prompt o el output del LLM |
| `ia/guardrails/resource-abuse.md` | Cambios en concurrencia, timeouts o sesiones |
