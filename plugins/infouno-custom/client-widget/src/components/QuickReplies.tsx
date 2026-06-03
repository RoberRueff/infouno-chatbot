import type { QuickReply } from '../types'

interface Props {
  replies:   QuickReply[]
  disabled?: boolean
  onSelect:  ( value: string ) => void
}

/**
 * Fila de botones de respuesta rápida.
 * Se muestran debajo del mensaje de bienvenida para reducir la fricción de escritura.
 * Muy efectivos en el mercado argentino donde el chatbot compite con WhatsApp.
 * Se ocultan automáticamente una vez que el usuario envía su primer mensaje.
 */
export function QuickReplies( { replies, disabled = false, onSelect }: Props ) {
  if ( ! replies.length ) return null

  return (
    <div class="iw-quick-replies" role="group" aria-label="Respuestas rápidas">
      { replies.map( r => (
        <button
          key={ r.label }
          type="button"
          class="iw-quick-reply"
          disabled={ disabled }
          onClick={ () => onSelect( r.value ?? r.label ) }
        >
          { r.label }
        </button>
      ) ) }
    </div>
  )
}
