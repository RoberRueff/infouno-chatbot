# Template: Registro de Nuevo Componente SaaS

> Completar este template ANTES de escribir cualquier línea de código.
> El objetivo es alinear el diseño con la arquitectura existente y prevenir decisiones que rompen la coherencia del sistema.

---

## 1. Identificación del Componente

```
Nombre del componente:  [ej: OpportunityEngine]
Namespace PHP:          [ej: Infouno\SaaS\Opportunity]
Carpeta PHP:            [ej: src/Opportunity/]
Rule file:              [ej: ia/rules/opportunity-engine.md]
Branch de desarrollo:   [ej: feature/opportunity-engine]
DB migration required:  [Sí / No → versión: v7]
```

---

## 2. Propósito Comercial

**¿Qué problema comercial resuelve?**
```
[Describir en 1-2 líneas el impacto directo en leads, ventas o retención de tenants]
```

**¿En qué capa del funnel vive?**
```
[ ] Conversation Layer  (ChatService, LLM, widget)
[ ] Lead Engine         (scoring, PII, dashboard)
[ ] Opportunity Engine  (pipeline, stages, valor estimado)
[ ] Sales Automation    (email, webhooks, recordatorios)
[ ] Revenue Attribution (ROI, costo por lead, tasa conversión)
```

**Filtro estratégico (toda nueva feature debe responder SÍ a al menos una):**
```
[ ] ¿Genera más leads calificados?
[ ] ¿Mejora la calidad/precisión del scoring?
[ ] ¿Aumenta la tasa de conversión de los tenants?
[ ] ¿Reduce el costo por lead o por conversación?
[ ] ¿Demuestra ROI medible al tenant?
```

---

## 3. Arquitectura Técnica

**Clases a crear:**
```
Infouno\SaaS\[Namespace]\
├── [NombreService].php       → Orquestador de lógica de negocio
├── [NombreRepository].php    → Acceso a datos (queries SQL)
└── [NombreInterface].php     → Contrato si hay múltiples implementaciones
```

**Endpoints REST nuevos (si aplica):**
```
[MÉTODO] /infouno/v1/[ruta]   → [propósito] | Auth: [WP login + tenant]
```
> Todos los endpoints se registran en `RestRouter.php` con `permission_callback`.

**Hooks de WordPress (si aplica):**
```
do_action('infouno_[evento]', $param1, $param2) → [descripción del contrato]
```

**Nuevas tablas de BD (si aplica):**
```sql
-- wp_infouno_[nombre]
id         BIGINT UNSIGNED PK
tenant_id  INT UNSIGNED FK INDEX   -- AISLAMIENTO OBLIGATORIO
...
```

**Widget changes (si aplica):**
```
Componentes nuevos:  [lista]
Nuevos data-* attrs: [lista]
Nuevos types en types.ts: [lista]
```

---

## 4. Dependencias e Integraciones

**¿De qué componentes depende este nuevo componente?**
```
[ ] TenantManager
[ ] BotManager
[ ] LeadService / LeadRepository
[ ] ConversationRepository
[ ] LLMRouter
[ ] Plugin.php (hooks)
[ ] Otro: [especificar]
```

**¿Qué componentes existentes necesitan modificarse?**
```
[ ] Plugin.php  → agregar: [inicialización + hook]
[ ] RestRouter.php  → agregar: [endpoint]
[ ] Migrator.php  → incrementar DB_VERSION a v[N]
[ ] ia/taxonomy.md  → agregar namespace y componente
[ ] ia/branch-registry.md  → actualizar DB version planeada
```

---

## 5. Guardrails Aplicables

**Cargar antes de codificar:**
```
[ ] ia/guardrails/tenant-isolation.md       → toda query con tenant_id
[ ] ia/guardrails/lead-pii-protection.md    → si maneja PII
[ ] ia/guardrails/commercial-data-integrity.md → si maneja score/revenue/tokens
[ ] ia/guardrails/legal-copliance.md        → si maneja consentimiento o PII
[ ] ia/guardrails/api-protection.md         → si agrega endpoints o cuotas
[ ] ia/guardrails/code-quality.md           → siempre
```

---

## 6. Checks de Entrega

**Ejecutar antes del merge:**
```
[ ] ia/checks/perfomance-audit.md             → siempre
[ ] ia/checks/lead-engine-audit.md            → si toca Lead Engine
[ ] ia/checks/pii-compliance-audit.md         → si toca PII o consent
[ ] ia/checks/commercial-pipeline-audit.md    → si toca más de una capa del funnel
```

---

## 7. Documentación a Crear o Actualizar

```
[ ] ia/rules/[componente].md      → NUEVO — crear antes de codificar
[ ] ia/taxonomy.md                → agregar namespace y componente
[ ] ia/branch-registry.md         → actualizar DB version y branch planeado
[ ] docs/[componente].md          → especificación de producto
[ ] ia/context-loader.md          → agregar al mapa de cuándo cargar el rule file
```

---

## Aprobación Pre-Código

> Antes de iniciar el desarrollo, confirmar con el humano que:
> 1. El propósito comercial está alineado con el roadmap.
> 2. La arquitectura técnica no rompe restricciones existentes.
> 3. Los guardrails y checks relevantes fueron identificados.

**Estado:** `[ ] Pendiente confirmación` / `[ ] Aprobado para desarrollo`
