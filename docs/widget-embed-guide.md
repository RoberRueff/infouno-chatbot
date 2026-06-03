# Guía de Integración del Widget — InfoUno Chatbot

Cómo instalar el widget de InfoUno en cualquier sitio web del cliente. Una vez configurado, el chatbot aparece como un botón flotante y funciona de forma completamente independiente del sitio host.

---

## Instalación Básica

Pegar este código antes del cierre de `</body>` en el sitio del cliente:

```html
<script
  src="https://[TU-DOMINIO]/wp-content/plugins/infouno-custom/client-widget/dist/widget.js"
  data-bot-token="[TOKEN-DEL-BOT]"
  data-api-url="https://[TU-DOMINIO]/wp-json/infouno/v1/chat"
></script>
```

El bot ya funciona. Los atributos adicionales son opcionales pero muy recomendados para maximizar conversiones.

---

## Referencia Completa de Atributos

### `data-bot-token` (Obligatorio)
Token público único del bot. Se obtiene desde el panel de InfoUno al crear el bot.
```html
data-bot-token="a1b2c3d4e5f6..."
```
⚠️ **No es un secret** — es el identificador público. Su seguridad depende de la validación de Origin configurada en el bot.

---

### `data-api-url` (Obligatorio)
URL completa del endpoint de chat. Siempre con HTTPS — el widget no funciona en HTTP.
```html
data-api-url="https://plataforma.infouno.com/wp-json/infouno/v1/chat"
```

---

### `data-bot-name` (Recomendado)
Nombre visible del asistente en el header del chat. Default: `"Asistente"`.
```html
data-bot-name="Lucas de TechHogar"
```

---

### `data-welcome` (Recomendado)
Mensaje de bienvenida que aparece al abrir el chat por primera vez. Default: `"¡Hola! ¿En qué puedo ayudarte?"`.
```html
data-welcome="¡Hola! Soy Lucas, asesor de ventas de TechHogar 👋 ¿En qué te puedo ayudar hoy?"
```

💡 **Tip de conversión:** El mensaje de bienvenida con nombre del asesor + emoji genera hasta 2x más clics que el mensaje genérico.

---

### `data-quick-replies` (Recomendado)
Botones de respuesta rápida que aparecen al abrir el chat. Desaparecen después del primer mensaje del usuario. Se pasa como JSON.
```html
data-quick-replies='[
  {"label": "Quiero un presupuesto"},
  {"label": "¿Hacen envíos?"},
  {"label": "Ver productos", "value": "¿Qué productos tienen disponibles?"},
  {"label": "Hablar con alguien"}
]'
```

- `label`: texto visible en el botón.
- `value` (opcional): texto que se envía como mensaje. Si no se define, se usa `label`.

💡 **Tip:** Los quick replies más efectivos para PyMEs argentinas son: `"Quiero un presupuesto"`, `"¿Cuánto cuesta?"`, `"¿Tienen envío?"`, `"Hablar con ventas"`.

---

### `data-whatsapp` (Recomendado)
Número de WhatsApp del negocio. Si se configura, aparece un botón "WhatsApp" en el footer del chat. Incluir código de país (+54 para Argentina).
```html
data-whatsapp="+5491112345678"
```

💡 **Tip:** En el mercado argentino, el botón de WhatsApp es crítico. Muchos usuarios prefieren continuar la conversación por WA antes de confirmar la compra.

---

### `data-privacy-url` (Recomendado para cumplimiento legal)
URL de la Política de Privacidad del tenant. Si se configura, aparece como enlace en la pantalla de consentimiento de lead (LeadConsentScreen). Obligatorio para cumplir la Ley 25.326.
```html
data-privacy-url="https://techogar.com.ar/privacidad"
```

---

## Ejemplo Completo — Máxima Conversión

```html
<!-- InfoUno Chatbot Widget — TechHogar -->
<script
  src="https://plataforma.infouno.com/wp-content/plugins/infouno-custom/client-widget/dist/widget.js"
  data-bot-token="a1b2c3d4e5f6g7h8i9j0..."
  data-api-url="https://plataforma.infouno.com/wp-json/infouno/v1/chat"
  data-bot-name="Lucas de TechHogar"
  data-welcome="¡Hola! Soy Lucas 👋 ¿En qué te puedo ayudar con tu compra hoy?"
  data-whatsapp="+5491112345678"
  data-privacy-url="https://techogar.com.ar/privacidad"
  data-quick-replies='[
    {"label":"Quiero un presupuesto"},
    {"label":"¿Hacen envíos?"},
    {"label":"¿Cuánto cuesta?","value":"¿Cuáles son los precios?"},
    {"label":"Hablar con ventas"}
  ]'
></script>
```

---

## Configuración de Dominios Permitidos (CORS)

En el panel de InfoUno, cada bot tiene un campo **"Dominios permitidos"**. Solo los dominios listados pueden usar el `bot_token`. Uno por línea, sin trailing slash:

```
https://techogar.com.ar
https://www.techogar.com.ar
https://tienda.techogar.com.ar
```

⚠️ Si el campo está vacío, el bot rechaza **todas** las requests por seguridad.

Para desarrollo local:
```
http://localhost:3000
http://localhost:8080
```

---

## Flujo del Usuario

```
1. Usuario entra al sitio del cliente
2. Ve el botón FAB (botón flotante) en la esquina inferior derecha
3. Hace clic → aparece la pantalla de consentimiento (Ley 25.326)
4. Acepta → el chat se abre con el mensaje de bienvenida y los quick replies
5. Hace clic en un quick reply O escribe su mensaje
6. (Si el mensaje tiene keywords de intención) → aparece LeadConsentScreen
   → el usuario elige qué datos puede compartir (nombre, teléfono, email)
7. El chat continúa y el Lead Engine detecta y califica el lead
8. Si el score ≥ 60 → el tenant recibe email de notificación con los datos de contacto
```

---

## Personalización de Colores (Próxima versión)

En la versión actual el color primario del widget es `#4F46E5` (indigo). En una versión futura se podrá personalizar:

```html
data-primary-color="#E53E3E"   <!-- Rojo para rubros de urgencia -->
data-primary-color="#38A169"   <!-- Verde para salud/bienestar -->
data-primary-color="#2B6CB0"   <!-- Azul para corporativo -->
```

---

## WordPress: Shortcode de Embed (Próxima versión)

Para facilitar la integración en sitios WordPress de clientes, se planea un shortcode:

```php
[infouno_widget bot_token="abc..." bot_name="Lucas" whatsapp="+5491112345678"]
```

---

## Troubleshooting

### El chat no aparece
1. Verificar que `data-bot-token` y `data-api-url` están presentes y correctos.
2. Verificar que el dominio del sitio está en la lista de dominios permitidos del bot.
3. Verificar que la URL usa HTTPS (el widget no carga en HTTP).
4. Abrir la consola del navegador — errores de `[infouno]` aparecerán ahí.

### El chat aparece pero no responde
1. Verificar que el tenant tiene cuota disponible (no está en `over_quota`).
2. Verificar que el bot está activo (`is_active = 1`).
3. Verificar que las APIs de IA (Anthropic/OpenAI) están configuradas en el servidor.

### Los quick replies no aparecen
1. Verificar que el JSON de `data-quick-replies` es válido (usar un JSON validator online).
2. Los quick replies solo se muestran antes del primer mensaje del usuario.

### El botón de WhatsApp no aparece
1. Verificar que `data-whatsapp` incluye el código de país: `+5491112345678` (no solo `1112345678`).
