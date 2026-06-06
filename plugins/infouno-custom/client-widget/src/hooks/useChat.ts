import { useState, useCallback, useRef } from 'preact/hooks'
import { recordLeadConsent } from '../api/client'
import { deliverChat } from '../api/deliver'
import { getSessionId } from './useSession'
import type { Message, ChatStatus, WidgetConfig, LeadScopes } from '../types'

/**
 * Keywords de alta intención de compra — español argentino coloquial.
 * Deben estar en minúsculas (se compara con text.toLowerCase()).
 */
const LEAD_KEYWORDS = [
  // Intención directa de compra
  'presupuesto', 'cotización', 'cotizar', 'precio', 'precios', 'costo', 'costos',
  'comprar', 'contratar', 'adquirir', 'lo tomo', 'me lo llevo', 'cerramos',
  'quiero uno', 'quiero una', 'dame uno', 'dame una',
  // Español argentino coloquial (voseo)
  'cuanto sale', 'cuánto sale', 'cuanto cuesta', 'cuánto cuesta',
  'cuanto me saldría', 'cuánto me saldría', 'cuanto me cobran', 'cuánto me cobran',
  'mándame info', 'mandame info', 'mándame precio', 'mandame precio',
  'me interesa', 'me interesan',
  // Disponibilidad y entrega
  'tienen stock', 'hay stock', 'disponible', 'hacen envíos', 'hacen envios',
  'mandan', 'delivery', 'despachan', 'cuando llega', 'cuándo llega',
  // Formas de pago — muy alta intención
  'cuotas', 'tarjeta', 'mercadopago', 'mercado pago', 'transferencia', 'efectivo',
  'ahora 3', 'ahora 6', 'ahora 12', 'ahora 18',
  // Turnos / servicios
  'turno', 'agenda', 'reservar', 'sacar turno', 'cuando pueden venir',
  'cuándo pueden venir', 'pueden venir',
  // Contacto
  'necesito', 'necesito urgente', 'quiero hablar', 'hablar con alguien',
] as const

/**
 * Fallback: si el usuario llegó a N mensajes sin trigger de keyword,
 * se muestra el consent de todos modos (usuario engaged = potencial lead).
 */
const LEAD_CONSENT_FALLBACK_AFTER_MSGS = 5

function uid(): string {
  return Math.random().toString( 36 ).slice( 2 )
}

function hasLeadIntent( text: string ): boolean {
  const lower = text.toLowerCase()
  return LEAD_KEYWORDS.some( kw => lower.includes( kw ) )
}

export function useChat( config: WidgetConfig ) {
  const [ messages,            setMessages            ] = useState<Message[]>( [] )
  const [ status,              setStatus              ] = useState<ChatStatus>( 'idle' )
  const [ showLeadConsent,     setShowLeadConsent     ] = useState( false )
  const [ isSubmittingConsent, setIsSubmittingConsent ] = useState( false )

  const abortRef       = useRef<AbortController | null>( null )
  const leadAsked      = useRef( false )
  const pendingMessage = useRef( '' )
  const userMsgCount   = useRef( 0 )

  /**
   * Lógica de streaming pura, sin gate de status ni detección de leads.
   * Llamada desde sendMessage y desde handleLeadAccept/Skip.
   */
  const doStream = useCallback( async ( text: string ) => {
    const userMsg: Message = { id: uid(), role: 'user',      content: text, pending: false }
    const botMsg:  Message = { id: uid(), role: 'assistant', content: '',   pending: true  }

    setMessages( ( prev: Message[] ) => [ ...prev, userMsg, botMsg ] )
    setStatus( 'loading' )

    abortRef.current?.abort()
    const controller = new AbortController()
    abortRef.current = controller

    let started = false

    await deliverChat( {
      apiUrl:    config.apiUrl,
      botToken:  config.botToken,
      sessionId: getSessionId(),
      message:   text,
      signal:    controller.signal,
      onDelta( delta ) {
        if ( ! started ) {
          started = true
          setStatus( 'streaming' )
        }
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id
              ? { ...m, content: m.content + delta, pending: true }
              : m
          )
        )
      },
      onDone() {
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id ? { ...m, pending: false } : m
          )
        )
        setStatus( 'idle' )
      },
      onError( message ) {
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id
              ? { ...m, role: 'error', content: message, pending: false }
              : m
          )
        )
        setStatus( 'error' )
        setTimeout( () => setStatus( 'idle' ), 4000 )
      },
      onRetry() {
        // El bot placeholder sigue visible (pending: true) mientras se reintenta.
        setStatus( 'retrying' )
      },
    } )
  }, [ config ] )

  /**
   * Envía un mensaje. Dispara LeadConsentScreen si:
   *   a) el mensaje contiene keywords de intención de compra, O
   *   b) el usuario ya envió N mensajes (alta probabilidad de ser un lead engaged).
   * En ambos casos el mensaje queda en pendingMessage y se despacha tras la decisión.
   */
  const sendMessage = useCallback( async ( text: string ) => {
    if ( status === 'streaming' || status === 'loading' ) return
    if ( ! text.trim() ) return

    if ( ! leadAsked.current ) {
      userMsgCount.current += 1
      const shouldAsk =
        hasLeadIntent( text ) ||
        userMsgCount.current >= LEAD_CONSENT_FALLBACK_AFTER_MSGS

      if ( shouldAsk ) {
        pendingMessage.current = text
        setShowLeadConsent( true )
        return
      }
    }

    await doStream( text )
  }, [ status, doStream ] )

  /**
   * El usuario acepta el consentimiento de lead:
   * registra server-side y despacha el mensaje pendiente.
   * Fallo silencioso — el chat continúa siempre.
   */
  const handleLeadAccept = useCallback( async ( scopes: LeadScopes ) => {
    setIsSubmittingConsent( true )
    try {
      await recordLeadConsent( config.apiBase, config.botToken, getSessionId(), scopes )
    } catch { /* silencioso — el chat continúa sin lead capture */ }

    leadAsked.current = true
    setIsSubmittingConsent( false )
    setShowLeadConsent( false )

    const pending = pendingMessage.current
    pendingMessage.current = ''
    if ( pending ) void doStream( pending )
  }, [ config.apiBase, config.botToken, doStream ] )

  /**
   * El usuario rechaza el consentimiento de lead.
   * Se marca como preguntado (no vuelve a aparecer) y el mensaje pendiente se envía igual.
   */
  const handleLeadSkip = useCallback( () => {
    leadAsked.current = true
    setShowLeadConsent( false )

    const pending = pendingMessage.current
    pendingMessage.current = ''
    if ( pending ) void doStream( pending )
  }, [ doStream ] )

  const destroy = useCallback( () => {
    abortRef.current?.abort()
  }, [] )

  return {
    messages,
    status,
    sendMessage,
    showLeadConsent,
    isSubmittingConsent,
    handleLeadAccept,
    handleLeadSkip,
    destroy,
  }
}
