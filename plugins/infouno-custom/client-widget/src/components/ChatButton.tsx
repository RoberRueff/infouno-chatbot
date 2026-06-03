interface Props {
  isOpen:   boolean
  onClick:  () => void
}

export function ChatButton( { isOpen, onClick }: Props ) {
  return (
    <button
      class={ `iw-btn ${ isOpen ? 'iw-btn--open' : '' }` }
      onClick={ onClick }
      aria-label={ isOpen ? 'Cerrar chat' : 'Abrir chat' }
    >
      { isOpen ? <IconClose /> : <IconChat /> }
    </button>
  )
}

function IconChat() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
      <path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2z"/>
    </svg>
  )
}

function IconClose() {
  return (
    <svg viewBox="0 0 24 24" fill="currentColor" width="24" height="24" aria-hidden="true">
      <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
    </svg>
  )
}
