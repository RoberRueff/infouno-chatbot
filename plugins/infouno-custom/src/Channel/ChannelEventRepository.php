<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Idempotencia de webhooks. markIfNew() hace INSERT IGNORE sobre la UNIQUE
 * (channel_type, external_msg_id): devuelve true si el evento es nuevo, false
 * si ya se había recibido (retry del proveedor).
 */
final class ChannelEventRepository {

    public function markIfNew( string $channelType, string $externalMsgId ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_channel_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $affected = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (channel_type, external_msg_id, received_at)
                 VALUES (%s, %s, %s)",
                $channelType,
                $externalMsgId,
                gmdate( 'Y-m-d H:i:s' )
            )
        );

        return (int) $affected === 1;
    }

    /** Purga eventos más viejos que $days (mantenimiento via wp_cron). */
    public function purgeOlderThan( int $days = 7 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_channel_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE received_at < %s",
                gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
            )
        );
    }
}
