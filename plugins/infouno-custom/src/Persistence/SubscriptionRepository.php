<?php
declare(strict_types=1);

namespace Infouno\SaaS\Persistence;

/**
 * Acceso a wp_infouno_subscriptions y wp_infouno_payment_events.
 *
 * Extiende TenantScopedRepository: scope key = tenant_id en toda operación
 * scopeada. findByPreapprovalId/paymentEventExists son lookups por id de MP
 * (usados por el webhook, que resuelve el tenant DESDE ese id) — sin guardScope.
 */
final class SubscriptionRepository extends TenantScopedRepository {

    private string $tablePaymentEvents;

    public function __construct() {
        parent::__construct();
        $this->tablePaymentEvents = $this->db->prefix . 'infouno_payment_events';
    }

    protected function table(): string {
        return $this->db->prefix . 'infouno_subscriptions';
    }

    public function createPending( int $tenantId, string $preapprovalId, string $plan, float $amount ): int {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert(
            $this->table(),
            [
                'tenant_id'         => $tenantId,
                'mp_preapproval_id' => $preapprovalId,
                'plan'              => $plan,
                'status'            => 'pending',
                'amount'            => $amount,
                'currency'          => 'ARS',
            ],
            [ '%d', '%s', '%s', '%s', '%f', '%s' ]
        );

        return (int) $this->db->insert_id;
    }

    /** @return array<string,mixed>|null */
    public function findByPreapprovalId( string $preapprovalId ): ?array {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$this->table()}` WHERE mp_preapproval_id = %s LIMIT 1",
                $preapprovalId
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findActiveForTenant( int $tenantId ): ?array {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM `{$this->table()}`
                 WHERE tenant_id = %d AND status IN ('pending','authorized')
                 ORDER BY created_at DESC LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function markAuthorized( int $tenantId, string $preapprovalId, int $eventTs, ?string $nextPaymentAt ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [
                'status'          => 'authorized',
                'next_payment_at' => $nextPaymentAt,
                'last_event_ts'   => $eventTs,
                'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    public function markCancelled( int $tenantId, string $preapprovalId, int $eventTs ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [ 'status' => 'cancelled', 'last_event_ts' => $eventTs, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    public function updateNextPayment( int $tenantId, string $preapprovalId, int $eventTs, ?string $nextPaymentAt ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->update(
            $this->table(),
            [ 'next_payment_at' => $nextPaymentAt, 'last_event_ts' => $eventTs, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ],
            [ 'tenant_id' => $tenantId, 'mp_preapproval_id' => $preapprovalId ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%s' ]
        );
    }

    public function paymentEventExists( string $paymentId ): bool {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $id = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM `{$this->tablePaymentEvents}` WHERE mp_payment_id = %s LIMIT 1",
                $paymentId
            )
        );
        return (bool) $id;
    }

    public function recordPaymentEvent( int $tenantId, string $paymentId, string $preapprovalId, string $status, float $amount ): void {
        $this->guardScope( $tenantId );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $this->db->insert(
            $this->tablePaymentEvents,
            [
                'tenant_id'         => $tenantId,
                'mp_payment_id'     => $paymentId,
                'mp_preapproval_id' => $preapprovalId,
                'status'            => $status,
                'amount'            => $amount,
            ],
            [ '%d', '%s', '%s', '%s', '%f' ]
        );
    }
}
