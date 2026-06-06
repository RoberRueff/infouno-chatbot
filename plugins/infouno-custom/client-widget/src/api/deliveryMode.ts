/**
 * Memoria del modo de entrega que funciona para cada host de API, en localStorage.
 * El buffering de SSE depende del hosting que sirve la API (no de la página que
 * embebe el widget), por eso la clave es el host del apiUrl.
 */

export type DeliveryMode = 'sse' | 'full'

const PREFIX = 'infouno_delivery_mode:'

export function hostKey( apiUrl: string ): string {
  try {
    return new URL( apiUrl ).host
  } catch {
    return apiUrl
  }
}

export function getPreferredMode( apiUrl: string ): DeliveryMode | null {
  try {
    const v = localStorage.getItem( PREFIX + hostKey( apiUrl ) )
    return v === 'full' ? 'full' : v === 'sse' ? 'sse' : null
  } catch {
    return null
  }
}

export function setPreferredMode( apiUrl: string, mode: DeliveryMode ): void {
  try {
    localStorage.setItem( PREFIX + hostKey( apiUrl ), mode )
  } catch {
    // Storage no disponible (modo privado, cuota) — degradar silencioso.
  }
}
