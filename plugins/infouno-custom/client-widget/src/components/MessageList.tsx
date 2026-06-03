import { h, Fragment } from 'preact'
import { useEffect, useRef } from 'preact/hooks'
import type { Message } from '../types'

interface Props {
  messages: Message[]
  welcome:  string
}

export function MessageList( { messages, welcome }: Props ) {
  const bottomRef = useRef<HTMLDivElement>( null )

  // Auto-scroll al último mensaje
  useEffect( () => {
    bottomRef.current?.scrollIntoView( { behavior: 'smooth' } )
  }, [ messages ] )

  return (
    <div class="iw-messages" role="log" aria-live="polite" aria-label="Mensajes del chat">
      { messages.length === 0 && (
        <div class="iw-welcome">{ welcome }</div>
      ) }

      { messages.map( msg => (
        <div
          key={ msg.id }
          class={ `iw-msg iw-msg--${ msg.role }${ msg.pending ? ' iw-msg--pending' : '' }` }
        >
          <span class="iw-msg__content">
            { msg.content }
            { msg.pending && <span class="iw-cursor" aria-hidden="true" /> }
          </span>
        </div>
      ) ) }

      <div ref={ bottomRef } />
    </div>
  )
}
