<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * CRUD de wp_infouno_channel_deliveries.
 * Registra y actualiza el estado de cada mensaje saliente de WhatsApp.
 * Toda query filtra tenant_id — guardrail multitenant.
 */
final class ChannelDeliveryRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channel_deliveries';
    }

    /**
     * Registra una entrega saliente con estado inicial 'sent'.
     * Devuelve el id insertado.
     *
     * @param int|null $messageId FK a wp_infouno_messages. Null si no hay mensaje persistido.
     */
    public function record(
        int     $tenantId,
        int     $channelId,
        ?int    $messageId,
        string  $externalMsgId,
    ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'tenant_id'       => $tenantId,
                'channel_id'      => $channelId,
                'message_id'      => $messageId,
                'external_msg_id' => $externalMsgId,
                'status'          => 'sent',
                'created_at'      => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%d', $messageId !== null ? '%d' : 'NULL', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Actualiza el estado de una entrega identificada por su wamid (external_msg_id).
     * Solo actualiza filas del tenant indicado (tenant isolation).
     */
    public function updateStatus(
        int     $tenantId,
        string  $externalMsgId,
        string  $status,
        ?int    $errorCode,
    ): void {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET status = %s,
                     error_code = %s,
                     status_updated_at = %s
                 WHERE tenant_id = %d
                   AND external_msg_id = %s",
                $status,
                $errorCode !== null ? (string) $errorCode : null,
                gmdate( 'Y-m-d H:i:s' ),
                $tenantId,
                $externalMsgId
            )
        );
    }
}
