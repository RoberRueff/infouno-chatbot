/**
 * Entry point del widget. Se ejecuta como IIFE en cuanto el script carga.
 * Lee los atributos data-* del propio tag <script> y monta el widget en el DOM.
 * No toca window, body ni estilos globales — todo vive en Shadow DOM.
 *
 * Atributos data-* disponibles:
 *   data-bot-token    (requerido) — token público del bot
 *   data-api-url      (requerido) — URL completa del endpoint /infouno/v1/chat
 *   data-bot-name     — nombre visible del bot (default: 'Asistente')
 *   data-welcome      — mensaje de bienvenida (default: '¡Hola! ¿En qué puedo ayudarte?')
 *   data-privacy-url  — URL de la política de privacidad del tenant
 *   data-whatsapp     — número de WhatsApp del negocio (ej: +5491112345678)
 *   data-quick-replies — JSON array de quick replies (ej: '[{"label":"Ver precios"}]')
 */
import { render, h } from 'preact'
import { Widget }    from './widget'
import type { WidgetConfig, QuickReply } from './types'

;(function () {
  const script = document.currentScript as HTMLScriptElement | null

  const rawApiUrl = script?.dataset.apiUrl ?? ''
  const apiBase   = rawApiUrl.replace( /\/[^/]+\/?$/, '' ).replace( /\/$/, '' )

  // Parsea el JSON de quick replies de forma segura
  let quickReplies: QuickReply[] | undefined
  const rawReplies = script?.dataset.quickReplies ?? ''
  if ( rawReplies ) {
    try {
      const parsed = JSON.parse( rawReplies )
      if ( Array.isArray( parsed ) ) {
        quickReplies = parsed
      }
    } catch {
      console.warn( '[infouno] data-quick-replies no es un JSON válido — se ignorará.' )
    }
  }

  const config: WidgetConfig = {
    botToken:     script?.dataset.botToken  ?? '',
    apiUrl:       rawApiUrl,
    apiBase,
    botName:      script?.dataset.botName   ?? 'Asistente',
    welcome:      script?.dataset.welcome   ?? '¡Hola! ¿En qué puedo ayudarte?',
    privacyUrl:   script?.dataset.privacyUrl  || undefined,
    whatsapp:     script?.dataset.whatsapp    || undefined,
    quickReplies: quickReplies,
  }

  if ( ! config.botToken || ! config.apiUrl ) {
    console.warn( '[infouno] data-bot-token y data-api-url son requeridos.' )
    return
  }

  if ( ! config.apiUrl.startsWith( 'https://' ) ) {
    console.warn( '[infouno] data-api-url debe usar HTTPS para proteger el bot_token.' )
    return
  }

  const host = document.createElement( 'div' )
  host.setAttribute( 'id', 'infouno-widget-host' )
  host.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:2147483647;'
  document.body.appendChild( host )

  render( h( Widget, { config } ), host )
})()
