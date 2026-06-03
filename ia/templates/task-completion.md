# Template de Cierre de Tarea y QA Autónomo

Cuando termines una tarea, debes responder estructurando tu mensaje de cierre EXACTAMENTE con los siguientes apartados. No uses texto plano genérico.

---

## 🏁 Reporte de Cierre Técnico: [Nombre de la Tarea]

### 1. Resumen de Cambios
| Archivo | Tipo de cambio | Descripción breve |
|---|---|---|
| `ruta/al/archivo.php` | Nuevo / Modificado / Eliminado | Qué lógica se implementó o alteró |

### 2. Validación de Guardrails (QA)
* **Aislamiento Multitenant:** [Explica cómo se garantiza que esta función respete los límites de `tenant_id` y no filtre datos entre tenants. Si no aplica, indicar por qué.]
* **Sanitización y Seguridad:** [Detalla qué funciones de escape (`esc_html`, `sanitize_text_field`, `absint`, etc.) o validaciones TypeScript se implementaron contra XSS/SQLi.]
* **Calidad de Código:** [Confirma que no hay código de depuración (`var_dump`, `console.log`), que los tipos PHP y TypeScript son estrictos y que el linter pasa sin errores.]
* **Control de Costos y Tokens:** [Confirma si la tarea alteró o mantuvo las cuotas de tokens, el truncado de historial y los límites de rate limiting.]
* **Protección contra Prompt Injection:** [Indica si el cambio introduce nuevos inputs de usuario al flujo del LLM y, de ser así, cómo se validan antes de enviarse.]
* **Abuso de Recursos:** [Confirma que no se introdujeron bucles sin límite, queries sin `LIMIT`, conexiones sin timeout ni streams sin control de concurrencia.]

### 3. Resultados de la Auditoría (`perfomance-audit.md`)
* [Indica qué secciones del checklist aplican a este cambio (Servidor / Tokens / Widget / Seguridad) y cómo se satisfacen. Si algún punto no aplica, indicarlo explícitamente.]

### 4. Comandos de Verificación Ejecutados
```bash
# Ejemplo — adaptar según lo que aplique a la tarea
composer package-lint
npm run build   # dentro de plugins/infouno-custom/client-widget/
```
* [Indicar el resultado: ✅ Sin errores / ⚠️ Advertencias aceptadas / ❌ Pendiente con justificación]

### 5. Próximo Paso Recomendado
* [Sugerencia lógica e inmediata para continuar con el desarrollo del proyecto, referenciando el componente concreto o archivo al que corresponde.]
