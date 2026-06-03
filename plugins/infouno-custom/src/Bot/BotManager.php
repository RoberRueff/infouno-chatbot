<?php

declare(strict_types=1);

namespace Infouno\SaaS\Bot;

/**
 * CRUD de bots con scope estricto por tenant_id.
 * Toda lectura/escritura incluye WHERE tenant_id — guardrail de aislamiento.
 */
final class BotManager {

    /**
     * Máximo de bots por plan.
     * Un bot extra = más superficie de ataque + más recursos. Limitamos por plan.
     *
     * @var array<string, int>
     */
    public const PLAN_BOT_LIMITS = [
        'free'    =>  1,
        'trial'   =>  2,
        'premium' => 10,
        'agency'  => 50,
    ];

    /**
     * Settings por defecto de un bot.
     *
     * Parámetros LLM:
     *   temperature      — Creatividad del modelo (0.0–1.0). 0.7 = balance respuesta-creatividad.
     *   max_tokens       — Tokens máximos por respuesta.
     *   context_window   — Cantidad de mensajes anteriores a incluir en el contexto.
     *   max_conv_tokens  — Techo de tokens por conversación (guardrail token-economy.md).
     *
     * Parámetros comerciales (widget):
     *   welcome_message  — Texto de bienvenida visible antes del primer mensaje.
     *   quick_replies    — Array de objetos {label, value?} para botones de conversión.
     *                      Ejemplo: [{"label":"Ver precios"},{"label":"Hablar con ventas"}]
     *   whatsapp_number  — Número de WhatsApp del negocio (con código de país, ej: +5491112345678).
     *                      Si se configura, aparece un botón "WhatsApp" en el footer del widget.
     */
    private const DEFAULT_SETTINGS = [
        'temperature'      => 0.7,
        'max_tokens'       => 1024,
        'context_window'   => 10,
        'max_conv_tokens'  => 20_000,
        'welcome_message'  => '¡Hola! ¿En qué puedo ayudarte?',
        'quick_replies'    => [],
        'whatsapp_number'  => '',
        'webhook_url'      => '',
    ];

    public function create( int $tenantId, array $data ): int {
        global $wpdb;

        $table  = $wpdb->prefix . 'infouno_bots';
        $token  = $this->generatePublicToken();
        $merged = array_merge( self::DEFAULT_SETTINGS, $data['settings'] ?? [] );

        $inserted = $wpdb->insert(
            $table,
            [
                'tenant_id'      => $tenantId,
                'bot_name'       => sanitize_text_field( $data['bot_name'] ),
                'public_token'   => $token,
                'system_prompt'  => sanitize_textarea_field( $data['system_prompt'] ?? '' ),
                'settings'       => wp_json_encode( $merged ),
                'llm_provider'   => sanitize_text_field( $data['llm_provider'] ?? 'anthropic' ),
                'llm_model'      => sanitize_text_field( $data['llm_model'] ?? 'claude-haiku-4-5-20251001' ),
                'allowed_origins' => sanitize_textarea_field( $data['allowed_origins'] ?? '' ),
                'is_active'      => 1,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );

        if ( ! $inserted ) {
            throw new \RuntimeException( 'No se pudo crear el bot.', 500 );
        }

        return (int) $wpdb->insert_id;
    }

    /** Retorna la cantidad de bots activos del tenant — query ligera sin cargar datos. */
    public function countForTenant( int $tenantId ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_bots';
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE tenant_id = %d",
                $tenantId
            )
        );
    }

    /** Siempre filtra por tenant_id para cumplir el guardrail de aislamiento. */
    public function getById( int $botId, int $tenantId ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_bots';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $botId,
                $tenantId
            ),
            ARRAY_A
        );

        return $row ? $this->decodeSettings( $row ) : null;
    }

    public function getAllForTenant( int $tenantId ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_bots';
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE tenant_id = %d ORDER BY created_at DESC",
                $tenantId
            ),
            ARRAY_A
        );

        return array_map( [ $this, 'decodeSettings' ], $rows ?: [] );
    }

    public function update( int $botId, int $tenantId, array $data ): bool {
        global $wpdb;

        $table  = $wpdb->prefix . 'infouno_bots';
        $fields = [];
        $types  = [];

        if ( isset( $data['bot_name'] ) ) {
            $fields['bot_name'] = sanitize_text_field( $data['bot_name'] );
            $types[]            = '%s';
        }
        if ( isset( $data['system_prompt'] ) ) {
            $fields['system_prompt'] = sanitize_textarea_field( $data['system_prompt'] );
            $types[]                 = '%s';
        }
        if ( isset( $data['settings'] ) ) {
            $existing = $this->getById( $botId, $tenantId );
            $merged   = array_merge(
                $existing['settings'] ?? self::DEFAULT_SETTINGS,
                $data['settings']
            );
            $fields['settings'] = wp_json_encode( $merged );
            $types[]            = '%s';
        }
        if ( isset( $data['allowed_origins'] ) ) {
            $fields['allowed_origins'] = sanitize_textarea_field( $data['allowed_origins'] );
            $types[]                   = '%s';
        }
        if ( isset( $data['llm_provider'] ) ) {
            $fields['llm_provider'] = sanitize_text_field( $data['llm_provider'] );
            $types[]                = '%s';
        }
        if ( isset( $data['llm_model'] ) ) {
            $fields['llm_model'] = sanitize_text_field( $data['llm_model'] );
            $types[]             = '%s';
        }
        if ( isset( $data['is_active'] ) ) {
            $fields['is_active'] = (int) $data['is_active'];
            $types[]             = '%d';
        }

        if ( empty( $fields ) ) {
            return false;
        }

        $updated = $wpdb->update(
            $table,
            $fields,
            [ 'id' => $botId, 'tenant_id' => $tenantId ],
            $types,
            [ '%d', '%d' ]
        );

        return $updated !== false;
    }

    public function delete( int $botId, int $tenantId ): bool {
        global $wpdb;

        $table   = $wpdb->prefix . 'infouno_bots';
        $deleted = $wpdb->delete(
            $table,
            [ 'id' => $botId, 'tenant_id' => $tenantId ],
            [ '%d', '%d' ]
        );

        return (bool) $deleted;
    }

    /**
     * Busca un bot por su token público. Uso exclusivo del widget para autenticarse.
     * No requiere tenant_id porque el token identifica unívocamente al bot.
     */
    public function getByPublicToken( string $token ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_bots';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE public_token = %s AND is_active = 1 LIMIT 1",
                $token
            ),
            ARRAY_A
        );

        return $row ? $this->decodeSettings( $row ) : null;
    }

    /**
     * Valida que el origen HTTP del widget esté en la lista de dominios permitidos del bot.
     * La comparación es case-insensitive y tolera trailing slashes.
     * Retorna false si allowed_origins está vacío (bloquea por defecto).
     */
    public function validateOrigin( array $bot, string $origin ): bool {
        $raw = trim( $bot['allowed_origins'] ?? '' );

        if ( '' === $raw ) {
            return false;
        }

        // Normaliza el origin recibido: minúsculas + sin trailing slash
        $normalizedOrigin = strtolower( rtrim( $origin, '/' ) );

        // trim() elimina espacios y \r\n en todos sus extremos — correcto con CRLF de Windows
        $allowed = array_filter(
            array_map(
                static fn( string $d ) => strtolower( rtrim( trim( $d ), '/' ) ),
                explode( "\n", $raw )
            ),
            static fn( string $d ) => '' !== $d
        );

        return in_array( $normalizedOrigin, $allowed, true );
    }

    /** Decodifica la columna JSON de settings con fallback al esquema por defecto. */
    private function decodeSettings( array $row ): array {
        $decoded = json_decode( $row['settings'] ?? '{}', true );
        $row['settings'] = is_array( $decoded )
            ? array_merge( self::DEFAULT_SETTINGS, $decoded )
            : self::DEFAULT_SETTINGS;

        return $row;
    }

    private function generatePublicToken(): string {
        return bin2hex( random_bytes( 32 ) );
    }
}
