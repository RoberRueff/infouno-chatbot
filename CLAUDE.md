# Guía de Desarrollo y Arranque — infouno-base

Este archivo es el punto de entrada exclusivo para la IA. Define el comportamiento esperado, comandos rápidos y el flujo estricto de desarrollo para este SaaS Multitenant de Chatbots.

> **Contexto completo:** Lee `ia/claude.md` para el mapa detallado de archivos de contexto y guardrails a cargar según el tipo de tarea.

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
