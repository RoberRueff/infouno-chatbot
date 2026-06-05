<?php

declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Acceso a las tablas wp_infouno_conversations y wp_infouno_messages.
 * Toda consulta incluye tenant_id — guardrail de aislamiento de datos.
 */
final class ConversationRepository {

    /** Planes que aplican retención limitada de mensajes. */
    private const LIMITED_RETENTION_PLANS = [ 'free', 'trial' ];
    /** Días de retención para planes con límite. */
    private const RETENTION_DAYS = 30;
    /** Máximo de mensajes a procesar por ejecución de cron (evita timeouts). */
    private const PURGE_BATCH = 500;
    /**
     * Texto de reemplazo para anonimización — guardrail legal-copliance.md.
     * Las filas con tokens_used > 0 NUNCA se borran físicamente:
     * el conteo de tokens permanece para auditoría financiera.
     */
    private const ANON_CONTENT = '[Contenido eliminado a solicitud del usuario — Ley 25.326]';

    /**
     * Devuelve la conversación activa de la sesión o crea una nueva.
     * Maneja la condición de carrera: si INSERT falla por clave duplicada
     * (dos requests concurrentes), hace SELECT para devolver la fila existente.
     */
    public function getOrCreate(
        int     $tenantId,
        int     $botId,
        string  $sessionId,
        string  $channel      = 'web',
        ?string $externalUser = null
    ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_conversations';

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE tenant_id = %d AND bot_id = %d AND session_id = %s LIMIT 1",
                $tenantId,
                $botId,
                $sessionId
            ),
            ARRAY_A
        );

        if ( $row ) {
            return $row;
        }

        $wpdb->insert(
            $table,
            [
                'tenant_id'     => $tenantId,
                'bot_id'        => $botId,
                'session_id'    => $sessionId,
                'metadata'      => null,
                'channel'       => $channel,
                'external_user' => $externalUser,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        $insertId = (int) $wpdb->insert_id;

        if ( $insertId ) {
            $created = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE id = %d AND tenant_id = %d LIMIT 1",
                    $insertId,
                    $tenantId
                ),
                ARRAY_A
            );

            if ( $created ) {
                return $created;
            }
        }

        // INSERT falló (probable DUPLICATE KEY por request concurrente) — recuperar la fila existente
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE tenant_id = %d AND bot_id = %d AND session_id = %s LIMIT 1",
                $tenantId,
                $botId,
                $sessionId
            ),
            ARRAY_A
        );

        if ( $existing ) {
            return $existing;
        }

        throw new \RuntimeException( 'No se pudo crear ni recuperar la conversación.', 500 );
    }

    /**
     * Recupera los últimos $limit mensajes de la conversación.
     * El JOIN valida que conversation_id pertenece al tenant — guardrail de aislamiento.
     * Retorna en orden cronológico ascendente para el contexto del LLM.
     */
    public function getRecentMessages( int $conversationId, int $tenantId, int $limit ): array {
        global $wpdb;

        $msgTable  = $wpdb->prefix . 'infouno_messages';
        $convTable = $wpdb->prefix . 'infouno_conversations';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT m.role, m.content
                 FROM `{$msgTable}` m
                 INNER JOIN `{$convTable}` c ON c.id = m.conversation_id
                 WHERE m.conversation_id = %d
                   AND c.tenant_id = %d
                   AND m.deleted_at IS NULL
                 ORDER BY m.id DESC
                 LIMIT %d",
                $conversationId,
                $tenantId,
                $limit
            ),
            ARRAY_A
        );

        return array_reverse( $rows ?: [] );
    }

    /**
     * Guarda un mensaje con desglose de tokens por tipo.
     * $applyExpiry activa la retención limitada para planes free/trial.
     */
    public function saveMessage(
        int    $conversationId,
        string $role,
        string $content,
        int    $tokensInput  = 0,
        int    $tokensOutput = 0,
        bool   $applyExpiry  = false
    ): int {
        global $wpdb;

        $table     = $wpdb->prefix . 'infouno_messages';
        $expiresAt = $applyExpiry
            ? gmdate( 'Y-m-d H:i:s', strtotime( '+' . self::RETENTION_DAYS . ' days' ) )
            : null;

        $wpdb->insert(
            $table,
            [
                'conversation_id' => $conversationId,
                'role'            => $role,
                'content'         => $content,
                'tokens_input'    => $tokensInput,
                'tokens_output'   => $tokensOutput,
                'tokens_used'     => $tokensInput + $tokensOutput,
                'expires_at'      => $expiresAt,
            ],
            // '%s' con valor null → $wpdb inserta SQL NULL correctamente
            [ '%d', '%s', '%s', '%d', '%d', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Guarda el par usuario+asistente en una transacción ACID.
     * El mensaje del usuario registra tokens de input; el asistente, de output.
     *
     * @param string $tenantPlan Plan activo del tenant — determina si aplica expiración.
     */
    public function saveExchange(
        int    $conversationId,
        string $userMessage,
        string $assistantMessage,
        int    $tokensInput,
        int    $tokensOutput,
        string $tenantPlan = 'free'
    ): void {
        global $wpdb;

        $applyExpiry = in_array( $tenantPlan, self::LIMITED_RETENTION_PLANS, true );

        $wpdb->query( 'START TRANSACTION' );

        try {
            $this->saveMessage( $conversationId, 'user',      $userMessage,      $tokensInput,  0,             $applyExpiry );
            $this->saveMessage( $conversationId, 'assistant', $assistantMessage, 0,             $tokensOutput, $applyExpiry );
            $wpdb->query( 'COMMIT' );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        }
    }

    /**
     * Suma de tokens consumidos en la conversación (input + output).
     * Usado para verificar el techo por conversación antes de enviar al LLM.
     */
    public function totalTokensForConversation( int $conversationId, int $tenantId ): int {
        global $wpdb;

        $msgTable  = $wpdb->prefix . 'infouno_messages';
        $convTable = $wpdb->prefix . 'infouno_conversations';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(m.tokens_used), 0)
                 FROM `{$msgTable}` m
                 INNER JOIN `{$convTable}` c ON c.id = m.conversation_id
                 WHERE m.conversation_id = %d
                   AND c.tenant_id = %d
                   AND m.deleted_at IS NULL",
                $conversationId,
                $tenantId
            )
        );
    }

    /**
     * Derecho de supresión — Art. 16, Ley 25.326 Argentina.
     *
     * GUARDRAIL legal-copliance.md: Las filas con tokens_used > 0 NUNCA se borran
     * físicamente. Se anonimiza el contenido (texto reemplazado por ANON_CONTENT)
     * preservando los contadores de tokens para auditoría financiera.
     *
     * Flujo:
     *   - Mensajes con tokens: anonimización + deleted_at (excluidos del contexto LLM)
     *   - Mensajes sin tokens: solo deleted_at (purga física por cron en 24h)
     *   - Conversaciones: deleted_at (excluidas de nuevos getOrCreate)
     *
     * @return int Total de mensajes procesados (anonimizados + marcados).
     */
    public function deleteSession( string $sessionId, int $tenantId ): int {
        global $wpdb;

        $msgTable  = $wpdb->prefix . 'infouno_messages';
        $convTable = $wpdb->prefix . 'infouno_conversations';
        $now       = gmdate( 'Y-m-d H:i:s' );

        $convIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM `{$convTable}` WHERE session_id = %s AND tenant_id = %d AND deleted_at IS NULL",
                $sessionId,
                $tenantId
            )
        );

        if ( empty( $convIds ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $convIds ), '%d' ) );

        // Mensajes con tokens: anonimizar contenido, preservar contadores
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $anonymized = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$msgTable}`
                 SET content = %s, deleted_at = %s
                 WHERE conversation_id IN ({$placeholders})
                   AND tokens_used > 0
                   AND deleted_at IS NULL",
                self::ANON_CONTENT,
                $now,
                ...$convIds
            )
        );

        // Mensajes sin tokens: soft-delete (serán purgados físicamente por el cron)
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $softDeleted = (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$msgTable}`
                 SET deleted_at = %s
                 WHERE conversation_id IN ({$placeholders})
                   AND tokens_used = 0
                   AND deleted_at IS NULL",
                $now,
                ...$convIds
            )
        );

        // Marcar conversaciones como borradas
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$convTable}` SET deleted_at = %s WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
                $now,
                ...$convIds
            )
        );

        error_log( sprintf(
            '[INFOUNO] Session deleted. Tenant: %d, Session hash: %s, Anonymized: %d, Soft-deleted: %d',
            $tenantId,
            substr( hash( 'sha256', $sessionId ), 0, 12 ),
            $anonymized,
            $softDeleted
        ) );

        return $anonymized + $softDeleted;
    }

    /**
     * Purga física de mensajes soft-deleted SIN tokens consumidos.
     *
     * GUARDRAIL legal-copliance.md: Queda PROHIBIDO borrar físicamente filas
     * con tokens_used > 0. Solo se eliminan filas sin datos financieros.
     */
    public function purgeDeletedMessages( int $afterDays = 1 ): void {
        global $wpdb;

        $table     = $wpdb->prefix . 'infouno_messages';
        $threshold = gmdate( 'Y-m-d H:i:s', strtotime( "-{$afterDays} days" ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}`
                 WHERE deleted_at IS NOT NULL
                   AND tokens_used = 0
                   AND deleted_at < %s
                 LIMIT %d",
                $threshold,
                self::PURGE_BATCH
            )
        );

        if ( $deleted ) {
            error_log( sprintf( '[INFOUNO] Purged %d zero-token soft-deleted messages.', $deleted ) );
        }
    }

    /**
     * Purga de mensajes expirados (retención free/trial).
     *
     * GUARDRAIL legal-copliance.md:
     *   - Con tokens_used > 0: anonimizar contenido (no borrar, preservar auditoría)
     *   - Con tokens_used = 0: DELETE físico (sin datos financieros)
     */
    public function purgeExpiredMessages(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_messages';
        $now   = gmdate( 'Y-m-d H:i:s' );

        // Anonimizar los que tienen tokens — preserva auditoría financiera
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET content = %s, deleted_at = %s
                 WHERE expires_at IS NOT NULL
                   AND expires_at < %s
                   AND tokens_used > 0
                   AND deleted_at IS NULL
                 LIMIT %d",
                self::ANON_CONTENT,
                $now,
                $now,
                self::PURGE_BATCH
            )
        );

        // Borrar físicamente los que no tienen tokens
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}`
                 WHERE expires_at IS NOT NULL
                   AND expires_at < %s
                   AND tokens_used = 0
                 LIMIT %d",
                $now,
                self::PURGE_BATCH
            )
        );

        if ( $deleted ) {
            error_log( sprintf( '[INFOUNO] Purged %d zero-token expired messages.', $deleted ) );
        }
    }

}
