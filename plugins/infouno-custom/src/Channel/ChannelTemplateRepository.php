<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * CRUD de wp_infouno_channel_templates.
 * Gestiona las plantillas de WhatsApp aprobadas por Meta para cada tenant/canal.
 * Toda query filtra tenant_id — guardrail multitenant.
 */
final class ChannelTemplateRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channel_templates';
    }

    /**
     * Devuelve las plantillas aprobadas para un canal.
     * @return array<int,array<string,mixed>>
     */
    public function findApproved( int $tenantId, int $channelId ): array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE tenant_id = %d AND channel_id = %d AND status = 'approved'
                 ORDER BY name ASC",
                $tenantId,
                $channelId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Busca una plantilla por nombre (único por tenant/canal).
     * @return array<string,mixed>|null
     */
    public function findByName( int $tenantId, int $channelId, string $name ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE tenant_id = %d AND channel_id = %d AND name = %s LIMIT 1",
                $tenantId,
                $channelId,
                $name
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }
}
