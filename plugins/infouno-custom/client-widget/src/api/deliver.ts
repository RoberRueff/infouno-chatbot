import { streamChat, fetchFull } from './client'
import { getPreferredMode, setPreferredMode } from './deliveryMode'
import type { DeliveryMode } from './deliveryMode'

/**
 * Orquesta la entrega de una respuesta de chat con degradación automática:
 *   1. Si el host ya demostró que bufferea (modo recordado = 'full') → full directo.
 *   2. Si no, intenta SSE con timeout al primer chunk. Si el primer fragmento no
 *      llega a tiempo (proxy que bufferea), aborta y cae a ?mode=full.
 *   3. Persiste el modo ganador por host para no re-pagar el timeout cada vez.
 * El servidor genera UNA sola vez por request — full no re-ejecuta el LLM.
 */
export interface DeliverOptions {
  apiUrl:               string
  botToken:             string
  sessionId:            string
  message:              string
  signal:               AbortSignal
  onDelta:              ( text: string ) => void
  onDone:               () => void
  onError:              ( message: string ) => void
  onRetry?:             ( attempt: number, maxAttempts: number ) => void
  firstChunkTimeoutMs?: number
  // Inyectables para test; default a las implementaciones reales.
  streamImpl?:          typeof streamChat
  fullImpl?:            typeof fetchFull
  getMode?:             ( apiUrl: string ) => DeliveryMode | null
  setMode?:             ( apiUrl: string, mode: DeliveryMode ) => void
}

export async function deliverChat( opts: DeliverOptions ): Promise<void> {
  const timeoutMs  = opts.firstChunkTimeoutMs ?? 4000
  const streamImpl = opts.streamImpl ?? streamChat
  const fullImpl   = opts.fullImpl   ?? fetchFull
  const getMode    = opts.getMode    ?? getPreferredMode
  const setMode    = opts.setMode    ?? setPreferredMode

  const runFull = async (): Promise<void> => {
    try {
      const reply = await fullImpl( opts.apiUrl, opts.botToken, opts.sessionId, opts.message, opts.signal )
      setMode( opts.apiUrl, 'full' )
      if ( reply ) opts.onDelta( reply )
      opts.onDone()
    } catch ( err ) {
      if ( ( err as Error ).name === 'AbortError' ) return
      opts.onError( ( err as Error ).message || 'No pudimos obtener la respuesta.' )
    }
  }

  // 1. Host conocido como buffering → full directo.
  if ( getMode( opts.apiUrl ) === 'full' ) {
    await runFull()
    return
  }

  // 2. Intentar SSE con timeout al primer chunk.
  let firstChunk = false
  let timedOut   = false

  const internal = new AbortController()
  const onExternalAbort = () => internal.abort()
  opts.signal.addEventListener( 'abort', onExternalAbort, { once: true } )

  const timer = setTimeout( () => {
    if ( ! firstChunk ) {
      timedOut = true
      internal.abort()
    }
  }, timeoutMs )

  try {
    await streamImpl(
      opts.apiUrl,
      opts.botToken,
      opts.sessionId,
      opts.message,
      {
        onDelta( delta: string ) {
          if ( ! firstChunk ) {
            firstChunk = true
            clearTimeout( timer )
          }
          opts.onDelta( delta )
        },
        onDone() {
          if ( firstChunk ) setMode( opts.apiUrl, 'sse' )
          opts.onDone()
        },
        onError( message: string ) {
          opts.onError( message )
        },
        onRetry: opts.onRetry,
      },
      internal.signal
    )
  } finally {
    clearTimeout( timer )
    opts.signal.removeEventListener( 'abort', onExternalAbort )
  }

  // 3. Si abortamos por timeout (y no por el usuario), caer a full.
  if ( timedOut && ! opts.signal.aborted ) {
    await runFull()
  }
}
