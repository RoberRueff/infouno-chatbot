interface Props {
  botName:  string
  onAccept: () => void
}

/**
 * Pantalla de consentimiento informado — Ley 25.326 de Protección de Datos Personales.
 * Se muestra una sola vez antes de la primera interacción con el chatbot.
 */
export function ConsentScreen( { botName, onAccept }: Props ) {
  return (
    <div class="iw-consent" role="dialog" aria-modal="true" aria-labelledby="iw-consent-title">

      <div class="iw-consent__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="32" height="32">
          <path stroke-linecap="round" stroke-linejoin="round"
            d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
        </svg>
      </div>

      <h2 class="iw-consent__title" id="iw-consent-title">
        Antes de comenzar
      </h2>

      <p class="iw-consent__body">
        <strong>{ botName }</strong> es un asistente con inteligencia artificial.
        Para funcionar, guarda temporalmente el historial de esta conversación.
      </p>

      <p class="iw-consent__legal">
        Al continuar aceptás el tratamiento de tus datos conforme a la{' '}
        <strong>Ley 25.326 de Protección de Datos Personales</strong> de Argentina.
        Podés solicitar la eliminación de tu historial en cualquier momento desde
        el chat usando la opción <em>"Eliminar mis datos"</em>.
      </p>

      <button class="iw-consent__btn" onClick={ onAccept } autofocus>
        Entendido, comenzar
      </button>

    </div>
  )
}
