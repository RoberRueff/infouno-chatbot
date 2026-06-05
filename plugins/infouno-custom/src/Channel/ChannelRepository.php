<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Security\CredentialVault;

/**
 * CRUD y routing de conexiones de canal. Toda lectura por tenant filtra tenant_id;
 * resolveByRoutingKey() es la única consulta sin tenant_id (el routing_key, único y
 * de alta entropía, ES la clave de routing público — como el public_token del bot).
 */
final class ChannelRepository {

    public function __construct( private readonly CredentialVault $vault ) {}

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channels';
    }

    /**
     * Resuelve un canal por su routing_key (presente en la URL del webhook).
     * Devuelve la fila con 'credentials_decrypted' añadido, o null.
     * @return array<string,mixed>|null
     */
    public function resolveByRoutingKey( string $routingKey ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE routing_key = %s AND status = 'active' LIMIT 1",
                $routingKey
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['credentials_decrypted'] = '' !== (string) ( $row['credentials'] ?? '' )
            ? $this->vault->decryptArray( (string) $row['credentials'] )
            : [];

        return $row;
    }

    /**
     * Resuelve un canal por su id (el worker recibe el channel_id ya resuelto).
     * @return array<string,mixed>|null
     */
    public function resolveByRoutingKeyId( int $channelId ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND status = 'active' LIMIT 1", $channelId ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['credentials_decrypted'] = '' !== (string) ( $row['credentials'] ?? '' )
            ? $this->vault->decryptArray( (string) $row['credentials'] )
            : [];

        return $row;
    }

    /**
     * Crea una conexión de canal. Cifra las credenciales antes de persistir.
     * @param array<string,mixed> $credentials
     */
    public function create(
        int $tenantId,
        int $botId,
        string $channelType,
        string $routingKey,
        array $credentials,
        string $webhookSecret,
        string $displayName = ''
    ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'tenant_id'      => $tenantId,
                'bot_id'         => $botId,
                'channel_type'   => $channelType,
                'routing_key'    => $routingKey,
                'credentials'    => $this->vault->encryptArray( $credentials ),
                'webhook_secret' => $webhookSecret,
                'status'         => 'active',
                'display_name'   => $displayName,
                'created_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Lista los canales de un tenant (sin credenciales). Aislamiento multitenant.
     * @return array<int,array<string,mixed>>
     */
    public function listForTenant( int $tenantId ): array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, tenant_id, bot_id, channel_type, status, display_name, created_at
                 FROM `{$table}` WHERE tenant_id = %d ORDER BY created_at DESC",
                $tenantId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }
}
