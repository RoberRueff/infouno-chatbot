/**
 * Genera y persiste un session_id único por tab via sessionStorage.
 * El ID sobrevive recargas de página pero no se comparte entre pestañas.
 */

const SESSION_KEY = 'infouno_sid'

function generateId(): string {
  if ( typeof crypto !== 'undefined' && crypto.randomUUID ) {
    return crypto.randomUUID()
  }
  // Fallback para browsers muy antiguos
  return Math.random().toString(36).slice(2) + Date.now().toString(36)
}

export function getSessionId(): string {
  try {
    const stored = sessionStorage.getItem( SESSION_KEY )
    if ( stored ) return stored

    const id = generateId()
    sessionStorage.setItem( SESSION_KEY, id )
    return id
  } catch {
    // sessionStorage bloqueado (modo incógnito estricto) — genera uno volátil
    return generateId()
  }
}

/**
 * Elimina el session_id almacenado.
 * El próximo getSessionId() generará uno nuevo, iniciando una sesión limpia.
 * Llamar después de confirmar que el servidor borró los datos (Ley 25.326).
 */
export function resetSessionId(): void {
  try {
    sessionStorage.removeItem( SESSION_KEY )
  } catch { /* silencioso si sessionStorage no está disponible */ }
}
