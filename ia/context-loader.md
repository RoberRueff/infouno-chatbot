# Protocolo de Carga de Contexto

Define los pasos obligatorios que la IA debe seguir para recolectar el contexto del proyecto antes de ejecutar cualquier tarea técnica. No inventes configuraciones — léelas del entorno.

---

## Protocolo Pre-Tarea (Paso a Paso)

### Paso 1: Taxonomía y Arquitectura

Leer `ia/taxonomy.md` para identificar el componente afectado y la capa del funnel comercial.
Si la tarea toca base de datos, flujo de streaming o aislamiento de tenants, leer también `ia/architecture.md`.

**Pregunta de control:** ¿Sé exactamente qué componente voy a modificar y qué otros componentes pueden verse afectados en el funnel comercial (Conversation → Lead → Opportunity → Automation → Revenue)?

---

### Paso 2: Estado del Esquema de Base de Datos

Si la tarea implica modificar o consultar datos, leer `ia/rules/db-schema.md` y verificar la versión activa en `plugins/infouno-custom/src/Core/Migrator.php`.

**Pregunta de control:** ¿Conozco los tipos de datos, índices y campos de las tablas involucradas? ¿La versión del schema es la esperada?

---

### Paso 3: Identificar Guardrails Comerciales

Si la tarea toca el Lead Engine o datos PII, cargar:
- `ia/guardrails/lead-pii-protection.md` — reglas de manejo de PII en el Lead Engine.
- `ia/guardrails/commercial-data-integrity.md` — reglas de integridad de scores, revenue y tokens.
- `ia/guardrails/legal-copliance.md` — cumplimiento Ley 25.326.

Si la tarea toca queries SQL o endpoints REST, cargar siempre:
- `ia/guardrails/tenant-isolation.md`

Antes de cualquier entrega de código:
- `ia/guardrails/code-quality.md`

---

### Paso 4: Cargar el Rule File del Componente

Según el componente identificado en el Paso 1, cargar el rule file correspondiente:

| Componente | Rule File |
|-----------|-----------|
| Lead Engine (LeadScorer, LeadService, LeadRepo, Dashboard) | `ia/rules/lead-engine.md` |
| Opportunity Engine (Fase 2) | `ia/rules/opportunity-engine.md` |
| Chat / ChatService / LLM | `ia/rules/llm-integration.md` + `ia/rules/token-economy.md` |
| Plugin core / endpoints REST | `ia/rules/plugin-core.md` |
| Schema / migraciones | `ia/rules/db-schema.md` |
| Widget Preact/TypeScript | `ia/rules/chatbot-widget.md` |
| Tema / Dashboard Astra | `ia/rules/thema-astra.md` |

---

### Paso 5: Análisis de Dependencias del Widget

Si la tarea afecta al frontend, verificar:
- `plugins/infouno-custom/client-widget/package.json` — librerías disponibles.
- `plugins/infouno-custom/client-widget/tsconfig.json` — configuración TypeScript.
- `plugins/infouno-custom/client-widget/vite.config.ts` — bundler y tamaño.
- `plugins/infouno-custom/client-widget/src/types.ts` — tipos disponibles.

**Línea roja:** Prohibido sugerir nuevas dependencias NPM sin justificar su peso gzipped y su necesidad real.

---

### Paso 6: Mapeo de Endpoints Activos

Si la tarea conecta widget con backend, verificar `plugins/infouno-custom/src/API/RestRouter.php`.
Todos los endpoints bajo `infouno/v1/` con su `permission_callback`.

**Pregunta de control:** ¿El endpoint existe o debo crearlo? Si existe, ¿valida correctamente `tenant_id`?

---

### Paso 7: Carga del Estado del Tenant (Debugging)

Para tareas de debugging, requerir al desarrollador:
1. Plan del tenant simulado (`free`, `trial`, `premium`, `agency`).
2. Proveedor LLM configurado para el bot (Anthropic u OpenAI).
3. Saldo de tokens y estado de cuota.
4. Si es relevante: score del lead, status, campos PII disponibles.

---

### Paso 8: Seleccionar Check de Auditoría

Antes de entregar, identificar qué checklist ejecutar:

| Cambio afecta | Check a ejecutar |
|--------------|-----------------|
| Lead Engine (cualquier parte) | `ia/checks/lead-engine-audit.md` |
| PII / consentimiento | `ia/checks/pii-compliance-audit.md` |
| Pipeline completo (> 1 capa) | `ia/checks/commercial-pipeline-audit.md` |
| Todo cambio de código | `ia/checks/perfomance-audit.md` |

---

## Condiciones de Aborto

La IA se detiene y solicita aclaración antes de escribir código en estos casos:

| Condición | Acción |
|-----------|--------|
| El schema real no coincide con `ia/rules/db-schema.md` | Solicitar inspección del schema real |
| Query SQL global sin filtro de `tenant_id` | `[GUARDRAIL TRIGGERED: ACCESO GLOBAL DE DATOS]` |
| PII almacenada sin verificar consent en `wp_infouno_lead_consents` | `[GUARDRAIL TRIGGERED: VIOLACIÓN PII LEAD ENGINE]` |
| Score o `tokens_used` decrementados | `[GUARDRAIL TRIGGERED: VIOLACIÓN DE INTEGRIDAD COMERCIAL]` |
| Control de cuotas o rate limiting eliminado | `[GUARDRAIL TRIGGERED: PROTECCIÓN FINANCIERA DESACTIVADA]` |
| Input de usuario al LLM sin pasar por `InputGuard` | `[GUARDRAIL TRIGGERED: VULNERABILIDAD PROMPT INJECTION]` |
| Nueva tabla sin `tenant_id` en una query sin aislamiento explícito | `[GUARDRAIL TRIGGERED: AISLAMIENTO MULTITENANT VIOLADO]` |
| Contexto del tenant no provisto en tarea de debugging | Solicitar datos del Paso 7 antes de continuar |

---

## Mapa Rápido: Tarea → Archivos a Leer

| Tipo de tarea | Archivos mínimos a cargar |
|---------------|--------------------------|
| Cambio en LeadScorer / scoring | `ia/rules/lead-engine.md` + `ia/guardrails/commercial-data-integrity.md` + `docs/lead-scoring-rules.md` |
| Cambio en captura de PII | `ia/rules/lead-engine.md` + `ia/guardrails/lead-pii-protection.md` + `ia/guardrails/legal-copliance.md` |
| Nueva migración de BD | `ia/rules/db-schema.md` + `ia/templates/migration-template.md` + `ia/branch-registry.md` |
| Nuevo componente | `ia/templates/component-registration.md` + `ia/taxonomy.md` |
| Cambio en ChatService | `ia/rules/llm-integration.md` + `ia/rules/token-economy.md` + `ia/guardrails/api-protection.md` |
| Cambio en widget | `ia/rules/chatbot-widget.md` + `ia/guardrails/lead-pii-protection.md` (si toca consent) |
| Opportunity Engine (v7) | `ia/rules/opportunity-engine.md` + `docs/opportunity-engine.md` + `ia/guardrails/commercial-data-integrity.md` |
| Cambio en sistema prompt del bot | `docs/commercial-prompts.md` + `ia/templates/lead-prompt-builder.md` |
