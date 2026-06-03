import { useState, useCallback } from 'preact/hooks'
import type { ChatStatus } from '../types'

interface Props {
  status:   ChatStatus
  onSend:   ( text: string ) => void
}

const MAX_CHARS = 1000

export function InputBar( { status, onSend }: Props ) {
  const [ text, setText ] = useState( '' )
  const busy      = status === 'streaming' || status === 'loading' || status === 'retrying'
  const overLimit = text.length > MAX_CHARS

  const handleSubmit = useCallback( () => {
    const trimmed = text.trim()
    if ( ! trimmed || busy || overLimit ) return
    onSend( trimmed )
    setText( '' )
  }, [ text, busy, overLimit, onSend ] )

  const handleKey = useCallback( ( e: KeyboardEvent ) => {
    if ( e.key === 'Enter' && ! e.shiftKey ) {
      e.preventDefault()
      handleSubmit()
    }
  }, [ handleSubmit ] )

  return (
    <div class="iw-input-bar">
      <textarea
        class={ `iw-input${ overLimit ? ' iw-input--over-limit' : '' }` }
        rows={ 1 }
        placeholder={
          status === 'retrying'  ? 'Reconectando…'  :
          busy                   ? 'Escribiendo...' :
                                   'Escribe un mensaje…'
        }
        value={ text }
        maxLength={ MAX_CHARS }
        disabled={ busy }
        onInput={ ( e ) => setText( ( e.target as HTMLTextAreaElement ).value ) }
        onKeyDown={ handleKey }
        aria-label="Mensaje"
      />
      { overLimit && (
        <span class="iw-char-limit" role="alert">
          Límite de { MAX_CHARS } caracteres alcanzado
        </span>
      ) }
      <button
        class="iw-send"
        onClick={ handleSubmit }
        disabled={ busy || ! text.trim() || overLimit }
        aria-label="Enviar mensaje"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" width="20" height="20" aria-hidden="true">
          <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
        </svg>
      </button>
    </div>
  )
}
