import { useState } from 'preact/hooks'
import type { LeadScopes } from '../types'

interface Props {
  /** Muestra spinner en el botón mientras el padre procesa la llamada a la API. */
  isSubmitting?: boolean
  /** URL de la política de privacidad. Si no se provee, el enlace no se renderiza. */
  privacyUrl?: string
  onAccept: ( scopes: LeadScopes ) => void
  /** El usuario puede negarse — se continúa al chat sin captura de datos. */
  onSkip: () => void
}

/**
 * Pantalla de consentimiento granular para captura de datos PII (Lead Engine).
 * Se muestra solo si el tenant tiene el Lead Engine habilitado y el usuario
 * ya aceptó el consentimiento general de chat (ConsentScreen).
 *
 * El estado de carga (isSubmitting) es responsabilidad del padre,
 * que maneja la llamada a POST /infouno/v1/consent/lead.
 *
 * Cumple Ley 25.326 Art. 6 — consentimiento libre, expreso e informado
 * por cada tipo de dato personal a capturar.
 */
export function LeadConsentScreen( { isSubmitting = false, privacyUrl, onAccept, onSkip }: Props ) {
  const [ scopes, setScopes ] = useState<LeadScopes>( {
    name:  false,
    phone: false,
    email: false,
  } )

  const hasAnyConsent = scopes.name || scopes.phone || scopes.email

  const handleAccept = () => {
    if ( ! hasAnyConsent || isSubmitting ) return
    onAccept( scopes )
  }

  const toggle = ( field: keyof LeadScopes ) =>
    ( e: Event ) =>
      setScopes( ( prev: LeadScopes ) => ( { ...prev, [ field ]: ( e.target as HTMLInputElement ).checked } ) )

  return (
    <div
      class="iw-lead-consent"
      role="dialog"
      aria-modal="true"
      aria-labelledby="iw-lead-consent-title"
    >
      <h4 class="iw-lead-consent__title" id="iw-lead-consent-title">
        ¿Podemos contactarte?
      </h4>

      <p class="iw-lead-consent__description">
        Para brindarte una mejor atención y enviarte información personalizada,
        necesitamos tu consentimiento para cada tipo de dato:
      </p>

      <fieldset class="iw-lead-consent__options">
        <legend class="iw-sr-only">Datos que autorizás a capturar</legend>

        <label class="iw-lead-consent__option">
          <input
            type="checkbox"
            checked={ scopes.name }
            onChange={ toggle( 'name' ) }
            disabled={ isSubmitting }
          />
          <span>Mi nombre (para dirigirme a mí correctamente)</span>
        </label>

        <label class="iw-lead-consent__option">
          <input
            type="checkbox"
            checked={ scopes.phone }
            onChange={ toggle( 'phone' ) }
            disabled={ isSubmitting }
          />
          <span>Mi número de teléfono</span>
        </label>

        <label class="iw-lead-consent__option">
          <input
            type="checkbox"
            checked={ scopes.email }
            onChange={ toggle( 'email' ) }
            disabled={ isSubmitting }
          />
          <span>Mi correo electrónico</span>
        </label>
      </fieldset>

      <p class="iw-lead-consent__legal">
        Tus datos están protegidos por la{' '}
        <strong>Ley 25.326 de Protección de Datos Personales</strong>.
        No los compartiremos con terceros sin tu autorización explícita.
        { privacyUrl && (
          <>
            { ' ' }
            <a
              href={ privacyUrl }
              target="_blank"
              rel="noopener noreferrer"
              class="iw-lead-consent__link"
            >
              Política de Privacidad
            </a>
          </>
        ) }
      </p>

      <div class="iw-lead-consent__actions">
        <button
          type="button"
          class="iw-btn iw-btn--secondary"
          onClick={ onSkip }
          disabled={ isSubmitting }
        >
          No, solo quiero chatear
        </button>

        <button
          type="button"
          class="iw-btn iw-btn--primary"
          onClick={ handleAccept }
          disabled={ ! hasAnyConsent || isSubmitting }
          aria-busy={ isSubmitting }
        >
          { isSubmitting ? 'Guardando…' : 'Aceptar y continuar' }
        </button>
      </div>
    </div>
  )
}
