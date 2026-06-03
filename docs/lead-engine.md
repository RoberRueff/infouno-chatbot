# Estrategia comercial.
# Lead Engine Architecture

## Plataforma de Automatización Comercial para PyMEs Argentinas

Version: 1.0

Status: Draft

---

# 1. Propósito

## Visión

InfoUno no es un chatbot.

InfoUno es una plataforma de generación, calificación y gestión de oportunidades comerciales para Pymes Argentinas impulsadas por IA.

El chatbot es uno de los canales de captura.

El verdadero producto es el Lead Engine.

---

## Objetivo

Transformar visitantes anónimos en oportunidades comerciales calificadas y medibles.

---

## North Star Metric

Qualified Opportunities Generated (QOG)

Cantidad de oportunidades calificadas generadas por mes.

---

# 2. Principios del Lead Engine

## Principio 1

Toda conversación debe intentar generar valor comercial.

---

## Principio 2

Las conversaciones son un medio.

Los leads son el activo.

---

## Principio 3

Toda interacción debe ser medible.

---

## Principio 4

Todo resultado debe poder atribuirse económicamente.

---

## Principio 5

El sistema debe demostrar ROI al cliente.

---

# 3. Arquitectura Comercial

VISITOR
↓
CONVERSATION
↓
LEAD DETECTION
↓
LEAD QUALIFICATION
↓
PIPELINE
↓
AUTOMATION
↓
OPPORTUNITY
↓
SALE

---

# 4. Lead Lifecycle

## Estados

NEW

QUALIFIED

CONTACTED

OPPORTUNITY

WON

LOST

# Lead Score
## Escala:

0-100

## Temperatura

Cold

Warm

Hot

Ready To Buy
---

## Flujo

Visitante
↓
Conversación
↓
Lead Detectado
↓
Lead Calificado
↓
Contacto Comercial
↓
Propuesta
↓
Ganado / Perdido

---

# 5. Lead Model

## Datos Universales

Nombre

Email

Teléfono

Interés

Urgencia

Resumen

Lead Score

Estado

Canal

Fecha de creación

---

## Datos Específicos

Los campos específicos de cada vertical se almacenan como estructura flexible.

Ejemplos:

* Inmobiliaria
* Industria
* Agencia
* Jurídico

---

# 6. Lead Detection Engine

## Objetivo

Detectar oportunidades comerciales automáticamente.

---

## Señales de detección

Solicitud de presupuesto

Solicitud de contacto

Solicitud de llamada

Entrega de teléfono

Entrega de email

Necesidad explícita

Urgencia declarada

---

## Resultado

lead_detected

true / false

---

# 7. Lead Qualification Engine

## Objetivo

Determinar la calidad comercial de cada lead.

---

## Categorías

Information Seeker

Potential Buyer

Qualified Opportunity

Hot Opportunity

---

## Factores

Datos de contacto

Nivel de intención

Urgencia

Nivel de detalle

Necesidad explícita

---

# 8. Lead Scoring

Rango 0 a 100.

---

0-30

Frío

---

31-60

Tibio

---

61-80

Caliente

---

81-100

Prioritario

---

# 9. Business Events

Todos los eventos deben registrarse.

---

Eventos mínimos

conversation_started

conversation_finished

lead_detected

lead_updated

phone_provided

email_provided

quote_requested

appointment_requested

human_requested

proposal_sent

lead_won

lead_lost

---

# 10. CRM Liviano

Objetivo:

Seguimiento comercial simple.

---

Pipeline

NEW

CONTACTED

QUALIFIED

PROPOSAL

WON

LOST

---

Funciones mínimas

Notas

Historial

Filtros

Búsqueda

Cambio de estado

---

# 11. Commercial Automations

Acciones ejecutadas por eventos.

---

Email

Webhook

Google Sheets

WhatsApp

CRM Externo

---

# 12. ROI Engine

## Métricas Operativas

Conversaciones

Mensajes

Tiempo promedio

---

## Métricas Comerciales

Leads

Leads Calificados

Oportunidades

Ventas

---

## Métricas Financieras

Costo IA

Costo por Lead

Costo por Oportunidad

ROI Estimado

---

# 13. Revenue Attribution

Objetivo:

Atribuir ingresos al Lead Engine.

---

Métricas

Ventas generadas

Monto atribuido

Win Rate

Revenue per Lead

---

# 14. Vertical Framework

El núcleo debe ser independiente del vertical.

---

Verticales iniciales

Agencias

Industria

Inmobiliarias

Jurídico

---

# 15. Multi Channel Strategy

Canales soportados

Web

WhatsApp

Instagram

Facebook

Telegram

Email

---

Todos los canales alimentan el mismo Lead Engine.

---

# 16. Customer Success Metrics

Métricas por tenant.

---

Leads generados

Conversión

Costo IA

Actividad

Uso

Health Score

---

# 17. Commercial AI Insights

La IA debe generar recomendaciones.

Ejemplos:

* Detectar cuellos de botella.
* Detectar pérdida de oportunidades.
* Detectar fuentes de mayor conversión.
* Detectar patrones de cierre.

---

# 18. Roadmap Comercial

Fase 1

Lead Engine Core

---

Fase 2

CRM Liviano

---

Fase 3

Automatizaciones

---

Fase 4

WhatsApp

---

Fase 5

ROI y Revenue Attribution

---

Fase 6

Verticales

---

# 19. Regla Estratégica

Toda nueva funcionalidad debe responder al menos una pregunta:

¿Genera más leads?

¿Mejora la calidad de los leads?

¿Aumenta la conversión?

¿Reduce el costo comercial?

¿Demuestra ROI?

Si la respuesta es no, la funcionalidad no tiene prioridad estratégica.