import type { LeadScopes } from '../types'

/**
 * Cliente HTTP del widget — SSE de chat + consentimiento + eliminación de sesión (Ley 25.326).
 */

export interface StreamCallbacks {
  onDelta:   ( text: string ) => void
  onDone:    () => void
  onError:   ( message: string ) => void
  /** Llamado antes de cada reintento. attempt va de 2 a MAX_ATTEMPTS. */
  onRetry?:  ( attempt: number, maxAttempts: number ) => void
}

/** Máximo de intentos de conexión antes de reportar error definitivo. */
const MAX_ATTEMPTS = 3
/** Delays en ms entre reintentos: 1s → 2s → 4s (exponential backoff). */
const RETRY_DELAYS = [ 1000, 2000, 4000 ] as const

/**
 * Espera ms milisegundos respetando el AbortSignal.
 * Resuelve inmediatamente si se aborta — el caller verifica signal.aborted.
 */
function sleep( ms: number, signal: AbortSignal ): Promise<void> {
  return new Promise( ( resolve ) => {
    const timer = setTimeout( resolve, ms )
    signal.addEventListener( 'abort', () => { clearTimeout( timer ); resolve() }, { once: true } )
  } )
}

export async function streamChat(
  apiUrl:    string,
  botToken:  string,
  sessionId: string,
  message:   string,
  callbacks: StreamCallbacks,
  signal:    AbortSignal
): Promise<void> {

  for ( let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++ ) {
    if ( signal.aborted ) return

    // Espera antes de reintentar (no antes del primer intento).
    if ( attempt > 1 ) {
      callbacks.onRetry?.( attempt, MAX_ATTEMPTS )
      await sleep( RETRY_DELAYS[ attempt - 2 ], signal )
      if ( signal.aborted ) return
    }

    let response: Response

    try {
      response = await fetch( apiUrl, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify( { bot_token: botToken, session_id: sessionId, message } ),
        signal,
      } )
    } catch ( err ) {
      if ( ( err as Error ).name === 'AbortError' ) return
      // Error de red: reintentar si quedan intentos.
      if ( attempt < MAX_ATTEMPTS ) continue
      callbacks.onError( 'Sin conexión. Revisá tu red e intentá de nuevo.' )
      return
    }

    // Error HTTP: no reintentamos — es un error del servidor, no de conectividad.
    if ( ! response.ok || ! response.body ) {
      callbacks.onError( 'El servidor no pudo procesar la solicitud.' )
      return
    }

    // Conexión establecida — leer el stream SSE sin más reintentos.
    const reader  = response.body.getReader()
    const decoder = new TextDecoder()
    let   buffer  = ''

    try {
      while ( true ) {
        const { done, value } = await reader.read()
        if ( done ) break

        buffer += decoder.decode( value, { stream: true } )

        // Procesa líneas SSE completas (terminan en \n\n)
        let boundary: number
        while ( ( boundary = buffer.indexOf( '\n\n' ) ) !== -1 ) {
          const block = buffer.slice( 0, boundary )
          buffer      = buffer.slice( boundary + 2 )
          parseBlock( block, callbacks )
        }
      }
    } catch ( err ) {
      if ( ( err as Error ).name !== 'AbortError' ) {
        callbacks.onError( 'Conexión interrumpida.' )
      }
    } finally {
      reader.releaseLock()
    }

    return // stream completado correctamente
  }
}

/**
 * Registra el consentimiento server-side para evidencia legal (Ley 25.326).
 * Fallo silencioso: si la red falla, el widget igual abre el chat —
 * el consentimiento en localStorage sigue siendo válido como primera capa.
 */
export async function recordConsent(
  apiBase:   string,
  botToken:  string,
  sessionId: string,
): Promise<void> {
  try {
    await fetch( `${ apiBase }/consent`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify( { bot_token: botToken, session_id: sessionId } ),
    } )
  } catch {
    // Silencioso — localStorage ya registró el consentimiento como primer fallback
  }
}

/**
 * Registra el consentimiento granular para captura de datos PII (Lead Engine).
 * POST /infouno/v1/consent/lead — Ley 25.326 Art. 6.
 * Fallo silencioso: el chat continúa sin lead capture si la red falla.
 */
export async function recordLeadConsent(
  apiBase:   string,
  botToken:  string,
  sessionId: string,
  scopes:    LeadScopes,
): Promise<void> {
  try {
    await fetch( `${ apiBase }/consent/lead`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify( { bot_token: botToken, session_id: sessionId, scopes } ),
    } )
  } catch {
    // Silencioso — el chat continúa sin lead capture
  }
}

/**
 * Revocación completa de consentimiento — Art. 16, Ley 25.326.
 *
 * Llama a POST /consent/revoke, que en el servidor:
 *   1. Anonimiza mensajes y conversaciones (deleteSession interno)
 *   2. Elimina PII del lead (name, phone, email → NULL)
 *   3. Deshabilita flags de captura futura en lead_consents
 *   4. Registra audit trail scope='consent_revoked'
 *
 * Reemplaza a deleteSession() como acción de "Eliminar mis datos" en el widget.
 * Retorna true si la revocación fue exitosa.
 */
export async function revokeConsent(
  apiBase:   string,
  botToken:  string,
  sessionId: string,
): Promise<boolean> {
  try {
    const res = await fetch( `${ apiBase }/consent/revoke`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify( { bot_token: botToken, session_id: sessionId } ),
    } )
    return res.ok
  } catch {
    return false
  }
}

/**
 * Entrega completa no-streaming: POST a ?mode=full, devuelve la respuesta entera.
 * Fallback cuando el SSE no streamea (hosting que bufferea). Reusa la misma
 * generación server-side — no re-ejecuta el LLM.
 */
export async function fetchFull(
  apiUrl:    string,
  botToken:  string,
  sessionId: string,
  message:   string,
  signal:    AbortSignal
): Promise<string> {
  const url = apiUrl + ( apiUrl.includes( '?' ) ? '&' : '?' ) + 'mode=full'

  const res = await fetch( url, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify( { bot_token: botToken, session_id: sessionId, message } ),
    signal,
  } )

  const data = await res.json().catch( () => ( {} ) ) as Record<string, unknown>

  if ( ! res.ok || data['code'] ) {
    throw new Error( ( data['message'] as string ) ?? 'El servidor no pudo procesar la solicitud.' )
  }

  return ( data['reply'] as string ) ?? ''
}

/**
 * @deprecated Usar revokeConsent() — cubre mensajes + leads PII + consent flags.
 * Mantenida para compatibilidad con integraciones externas que llamen DELETE /session directamente.
 */
export async function deleteSession(
  apiBase:   string,
  botToken:  string,
  sessionId: string,
): Promise<boolean> {
  try {
    const res = await fetch( `${ apiBase }/session`, {
      method:  'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify( { bot_token: botToken, session_id: sessionId } ),
    } )
    return res.ok
  } catch {
    return false
  }
}

function parseBlock( block: string, cb: StreamCallbacks ): void {
  let eventType = ''
  let dataLine  = ''

  for ( const line of block.split( '\n' ) ) {
    if ( line.startsWith( 'event: ' ) ) {
      eventType = line.slice( 7 ).trim()
    } else if ( line.startsWith( 'data: ' ) ) {
      dataLine = line.slice( 6 ).trim()
    }
  }

  if ( ! dataLine || dataLine === '' ) return

  try {
    const payload = JSON.parse( dataLine ) as Record<string, unknown>

    if ( eventType === 'error' || payload['code'] ) {
      cb.onError( ( payload['message'] as string ) ?? 'Error del servidor.' )
      return
    }

    if ( payload['delta'] ) {
      cb.onDelta( payload['delta'] as string )
    }

    if ( eventType === 'done' || payload['status'] === 'complete' ) {
      cb.onDone()
    }
  } catch {
    // Fragmento SSE no parseable (ej: comentario ": stream-start") — ignorar
  }
}
