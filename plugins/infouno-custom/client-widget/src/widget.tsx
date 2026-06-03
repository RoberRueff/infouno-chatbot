/**
 * Host del Shadow DOM. Todo el HTML y CSS del widget viven aquí — nunca
 * salen al documento principal del cliente (guardrail de aislamiento de estilos).
 */
import { h, render } from 'preact'
import { useState, useEffect, useRef } from 'preact/hooks'
import { ChatButton }          from './components/ChatButton'
import { ChatWindow }          from './components/ChatWindow'
import { ConsentScreen }       from './components/ConsentScreen'
import { LeadConsentScreen }   from './components/LeadConsentScreen'
import { useChat }             from './hooks/useChat'
import { useConsent }          from './hooks/useConsent'
import { getSessionId, resetSessionId } from './hooks/useSession'
import { revokeConsent }       from './api/client'
import type { WidgetConfig }   from './types'

import widgetCss from './styles/widget.css?inline'

interface Props {
  config: WidgetConfig
}

export function Widget( { config }: Props ) {
  const hostRef   = useRef<HTMLDivElement>( null )
  const shadowRef = useRef<ShadowRoot | null>( null )
  useEffect( () => {
    const el = hostRef.current
    if ( ! el || shadowRef.current ) return

    const shadow = el.attachShadow( { mode: 'open' } )
    shadowRef.current = shadow

    const style = document.createElement( 'style' )
    style.textContent = widgetCss
    shadow.appendChild( style )

    const container = document.createElement( 'div' )
    shadow.appendChild( container )

    render( h( ChatTree, { config } ), container )

    return () => {
      render( null, container )
    }
  }, [] )

  return <div ref={ hostRef } />
}

/** Árbol real del chat — vive dentro del Shadow DOM */
function ChatTree( { config }: Props ) {
  const [ isOpen,   setIsOpen   ] = useState( false )
  const [ resetKey, setResetKey ] = useState( 0 )
  const { consented, accept }     = useConsent( config.botToken, config.apiBase )

  return (
    <>
      { isOpen && (
        consented
          ? (
            <ChatSession
              key={ resetKey }
              config={ config }
              onDeleted={ () => setResetKey( k => k + 1 ) }
            />
          )
          : (
            <ConsentScreen
              botName={ config.botName }
              onAccept={ accept }
            />
          )
      ) }
      <ChatButton
        isOpen={ isOpen }
        onClick={ () => setIsOpen( v => ! v ) }
      />
    </>
  )
}

/**
 * Sesión de chat aislada. Montar con una key diferente la destruye y recrea,
 * generando un nuevo session_id sin necesidad de recargar la página.
 */
interface SessionProps {
  config:    WidgetConfig
  onDeleted: () => void
}

function ChatSession( { config, onDeleted }: SessionProps ) {
  const {
    messages,
    status,
    sendMessage,
    destroy,
    showLeadConsent,
    isSubmittingConsent,
    handleLeadAccept,
    handleLeadSkip,
  } = useChat( config )

  useEffect( () => () => destroy(), [] )

  const handleDelete = async (): Promise<void> => {
    const sessionId = getSessionId()
    const ok = await revokeConsent( config.apiBase, config.botToken, sessionId )

    if ( ! ok ) {
      console.warn( '[infouno] No se pudo revocar el consentimiento. Intentá más tarde.' )
      return
    }

    resetSessionId()
    onDeleted()
  }

  if ( showLeadConsent ) {
    return (
      <div class="iw-window">
        <LeadConsentScreen
          privacyUrl={ config.privacyUrl }
          isSubmitting={ isSubmittingConsent }
          onAccept={ handleLeadAccept }
          onSkip={ handleLeadSkip }
        />
      </div>
    )
  }

  return (
    <ChatWindow
      botName={ config.botName }
      messages={ messages }
      status={ status }
      welcome={ config.welcome }
      quickReplies={ config.quickReplies }
      whatsapp={ config.whatsapp }
      onSend={ sendMessage }
      onDelete={ handleDelete }
    />
  )
}
