<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Determina si la ventana de conversación de 24h de WhatsApp está abierta.
 *
 * Ancla: created_at del último mensaje con role='user' de la conversación.
 * Abierta = < 24h desde ese mensaje. Cerrada = >= 24h, o sin mensajes del usuario.
 *
 * No crea tabla nueva — usa wp_infouno_messages + wp_infouno_conversations.
 */
final class WindowChecker {

    private const WINDOW_SECONDS = 86400; // 24 horas exactas

    /**
     * @param int    $botId           Id del bot (para resolver la conversación correcta).
     * @param string $conversationKey session_id sintético: 'wa:<phone>'.
     */
    public function isOpen( int $botId, string $conversationKey ): bool {
        global $wpdb;

        $tableMsg  = $wpdb->prefix . 'infouno_messages';
        $tableConv = $wpdb->prefix . 'infouno_conversations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $lastUserAt = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT m.created_at
                 FROM `{$tableMsg}` m
                 INNER JOIN `{$tableConv}` c ON c.id = m.conversation_id
                 WHERE c.bot_id     = %d
                   AND c.session_id = %s
                   AND m.role       = 'user'
                   AND m.deleted_at IS NULL
                 ORDER BY m.created_at DESC
                 LIMIT 1",
                $botId,
                $conversationKey
            )
        );

        if ( null === $lastUserAt || '' === (string) $lastUserAt ) {
            return false;
        }

        $lastUserTs = strtotime( (string) $lastUserAt );
        if ( false === $lastUserTs ) {
            return false;
        }

        return ( time() - $lastUserTs ) < self::WINDOW_SECONDS;
    }
}
