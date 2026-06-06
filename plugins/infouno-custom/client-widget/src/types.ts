export interface WidgetConfig {
  botToken:     string
  /** URL completa del endpoint de chat: .../infouno/v1/chat */
  apiUrl:       string
  /** URL base de la API: .../infouno/v1  (sin slash final) */
  apiBase:      string
  /** Nombre visible del bot en el header del chat */
  botName:      string
  /** Mensaje de bienvenida visible antes de la primera interacción */
  welcome:      string
  /** URL de la política de privacidad del tenant (opcional, se muestra en LeadConsentScreen) */
  privacyUrl?:  string
  /** Botones de respuesta rápida para reducir fricción de escritura (opcional) */
  quickReplies?: QuickReply[]
  /** Número de WhatsApp del negocio para escalación directa (ej: +5491112345678) */
  whatsapp?:    string
  /** Timeout (ms) al primer chunk SSE antes de caer a entrega completa (default 4000) */
  firstChunkTimeoutMs?: number
}

/** Botón de respuesta rápida que el usuario puede clickear en lugar de escribir. */
export interface QuickReply {
  /** Texto visible en el botón */
  label: string
  /** Texto que se envía como mensaje (si no se define, se usa label) */
  value?: string
}

export type MessageRole = 'user' | 'assistant' | 'error'

export interface Message {
  id:      string
  role:    MessageRole
  content: string
  /** true mientras el asistente está escribiendo */
  pending: boolean
}

export type ChatStatus = 'idle' | 'loading' | 'streaming' | 'error' | 'retrying'

/** Campos PII para los que el usuario puede dar consentimiento granular (Lead Engine). */
export interface LeadScopes {
  name:  boolean
  phone: boolean
  email: boolean
}
