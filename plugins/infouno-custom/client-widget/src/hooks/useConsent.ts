import { useState } from 'preact/hooks'
import { recordConsent } from '../api/client'
import { getSessionId }  from './useSession'

const CONSENT_PREFIX = 'infouno_consent_'

/**
 * Gestiona el consentimiento del usuario final (Ley 25.326 Argentina).
 *
 * Dos capas:
 *   1. localStorage: persistencia de UX (no pedir en cada visita)
 *   2. POST /consent:  evidencia legal server-side con hash de sesión + IP
 *
 * Si localStorage está bloqueado (modo incógnito estricto) el consentimiento
 * es volátil y se pide en cada apertura — comportamiento correcto legalmente.
 */
export function useConsent( botToken: string, apiBase: string ) {
  const key = CONSENT_PREFIX + botToken.slice( 0, 8 )

  const readStored = (): boolean => {
    try {
      return localStorage.getItem( key ) === '1'
    } catch {
      return false
    }
  }

  const [ consented, setConsented ] = useState<boolean>( readStored )

  const accept = (): void => {
    // Capa 1: persiste localmente para no pedir de nuevo
    try {
      localStorage.setItem( key, '1' )
    } catch { /* silencioso */ }

    setConsented( true )

    // Capa 2: registra server-side para evidencia legal
    const sessionId = getSessionId()
    recordConsent( apiBase, botToken, sessionId )
  }

  return { consented, accept }
}
