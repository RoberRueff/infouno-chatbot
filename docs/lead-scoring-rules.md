# Lead Scoring Rules v2 — Motor de Calificación de Leads

> Sincronizado con `LeadScorer.php` v2. Actualizar siempre que se modifique el algoritmo de scoring.
> Reglas de desarrollo: `ia/rules/lead-engine.md`.

---

## Objetivo del Scoring

Convertir señales conversacionales en un número (0-100) que represente la probabilidad de que el usuario tenga intención real de compra o contratación.

**El score NO mide:** interés informativo, simpatía, o frecuencia de mensajes.
**El score SÍ mide:** intención de compra, urgencia, calidad del contacto, engagement acumulado.

---

## Escala de Calificación

| Score | Temperatura | Acción recomendada |
|-------|------------|-------------------|
| 0–29 | Frío | Solo registrar. Sin notificación. |
| 30–59 | Tibio | Registrar. Visible en panel. Sin email. |
| 60–79 | Caliente ✅ | **CALIFICADO** — notificación al tenant vía email. |
| 80–100 | Muy caliente 🔥 | **PRIORIDAD ALTA** — misma notificación, marcado en email como urgente. |

El threshold de calificación (`score >= 60`) dispara `do_action('infouno_lead_captured')`.

---

## Algoritmo de Scoring — `LeadScorer::analyze()`

### Factores del mensaje actual

| Factor | Condición | Puntos |
|--------|-----------|--------|
| **Intención de compra** | Regex detecta: `quiero`, `necesito`, `cuánto sale`, `presupuesto`, `contratar`, `lo tomo`, `hacen envíos`, `tienen stock`, `cuotas`, `delivery`, etc. | +40 |
| **Keyword de alta intención** | Dentro del grupo anterior: `presupuesto`, `cotizar`, `cuánto me saldría`, `tarjeta`, `mercadopago`, `turno`, `quiero comprar`, etc. | +10 (adicional) |
| **Intención informativa** | Regex detecta: `consultar`, `cómo funciona`, `horarios`, `dónde están`, `qué servicios tienen`, etc. | +20 |
| **Urgencia** | Regex detecta: `urgente`, `hoy`, `mañana`, `cuanto antes`, `ya mismo`, `esta semana` | +15 |
| **Señal de pago** | Regex detecta: `tarjeta`, `cuotas`, `mercadopago`, `transferencia`, `ahora 3/6/12/18`, `efectivo`, `QR` | +20 |
| **Calificación contextual** | Regex detecta: `zona`, `barrio`, `empresa`, `local`, `cantidad`, `metros cuadrados`, `domicilio` | +10 |
| **Email detectado** | Regex encuentra email válido en el mensaje | +15 |
| **Teléfono detectado** | Regex encuentra número ≥ 8 dígitos en el mensaje | +15 |
| **Nombre detectado** | Regex detecta "me llamo X", "soy X", "mi nombre es X" | +5 |
| **WhatsApp explícito** | Regex detecta "whatsapp:", "wsp:", "mi WA es" seguido de número | +20 |

**Techo:** `min(100, score)` — no puede superar 100.

---

### Bonus del Historial — `LeadScorer::analyzeHistory()`

Solo analiza mensajes del usuario (`role === 'user'`).

| Factor | Condición | Bonus |
|--------|-----------|-------|
| **Precio preguntado 2+ veces** | `precio|presupuesto|cuánto|cotiz` aparece ≥ 2 veces en el historial | +15 |
| **PII ya provista antes** | Email o teléfono detectado en historial anterior | +10 |
| **Alta entrega sobre envío/stock** | `envío|entrega|stock|disponible|delivery` en historial | +5 |
| **Alto engagement** | El usuario envió ≥ 4 mensajes | +5 |

**Techo del bonus:** `min(25, bonus_total)` — el historial no puede aportar más de 25 puntos.

---

## Patrones Regex — Cobertura Argentina

### Español rioplatense (voseo)
```
quiero, necesito, busco
cuánto sale / cuánto cuesta / cuánto me saldría / cuánto cobran
lo tomo / lo agarro / me lo llevo / cerramos / dale
mándame info / mándame precio
```

### Formas de contacto local
```
WhatsApp / wasap / wsp / wa
número de celular / teléfono argentino (8+ dígitos)
```

### Señales de pago argentinas
```
tarjeta / cuotas / mercadopago / mercado pago
ahora 3 / ahora 6 / ahora 12 / ahora 18
transferencia / efectivo / QR / débito / crédito
```

### Disponibilidad y logística
```
tienen stock / hay stock / disponible
hacen envíos / mandan / despachan / delivery
cuando llega / cuándo llega
```

### Urgencia argentina
```
urgente / hoy / mañana / lo antes posible
cuanto antes / sin falta / enseguida
```

---

## Extracción de PII

El `LeadScorer` extrae PII del texto del mensaje. La extracción es independiente del consentimiento — el filtro de consentimiento opera en `LeadService` antes de persistir.

| Campo | Método | Validación post-extracción |
|-------|--------|---------------------------|
| `email` | Regex estándar de email | `sanitize_email()` |
| `phone` | Regex teléfono AR permisivo | Longitud limpia ≥ 8 dígitos |
| `name` | Regex: "me llamo X / soy X / mi nombre es X" | `sanitize_text_field()` + trim |

---

## Campo `interest` — Valores Posibles

| Valor | Cuándo se asigna |
|-------|-----------------|
| `'compra'` | El regex de intención de compra matchea |
| `'informacion'` | El regex de intención informativa matchea (sin compra) |
| `'consulta'` | No matcheó ningún patrón de intención específico |

---

## Trigger de LeadConsentScreen en el Widget

El widget (`useChat.ts`) activa el consent screen en dos condiciones:

| Condición | Trigger |
|-----------|---------|
| El mensaje contiene una keyword de `LEAD_KEYWORDS` | Trigger inmediato |
| El usuario envió ≥ 5 mensajes sin keyword | Trigger por engagement (fallback) |

**LEAD_KEYWORDS del widget** (subset de los patrones backend para detección rápida en JS):
`presupuesto`, `cotización`, `precio`, `comprar`, `contratar`, `cuanto sale`, `cuánto cuesta`, `cuotas`, `tarjeta`, `mercadopago`, `delivery`, `turno`, `necesito`, `quiero`, `me interesa`, etc.

---

## Reglas de Evolución del Scoring

1. **Todo cambio en `LeadScorer.php` debe actualizar este archivo.**
2. **Los pesos de los factores son calibraciones empíricas** — ajustar según datos reales de conversión de tenants activos.
3. **No agregar patrones de verticales específicas al LeadScorer core.** En el futuro, implementar `LeadScorerInterface` + implementaciones por vertical.
4. **El threshold de 60 es configurable en el futuro** — mantenerlo en una constante, no hardcodeado en múltiples lugares.
5. **El techo de 100 es absoluto** — `min(100, $score)` siempre en `analyze()`.
6. **El bonus de historial nunca puede superar 25 puntos** — `min(25, $bonus)` en `analyzeHistory()`.
