<?php

declare(strict_types=1);

namespace Infouno\SaaS\Tenant;

/**
 * Gestiona el ciclo de vida de los tenants y valida su estado antes de ejecutar operaciones.
 * Toda consulta a wp_infouno_tenants pasa por aquí — nunca consultar la tabla directamente desde otros módulos.
 */
final class TenantManager {

    /**
     * Límites de tokens mensuales por plan — única fuente de verdad financiera.
     *
     * Referencia de coste (Haiku input $0.80/1M · output $4.00/1M, mix 60/40):
     *   free     50 000 t  ≈ $0.10/mes  → gratis sin riesgo
     *   trial   200 000 t  ≈ $0.42/mes  → activado manualmente por el admin
     *   premium 2 000 000 t ≈ $4.16/mes → margen rentable a €29-49/mes
     *   agency  20 000 000 t ≈ $41.6/mes → margen rentable a €199+/mes
     *
     * @var array<string, int>
     */
    public const PLAN_QUOTAS = [
        'free'    =>    50_000,
        'trial'   =>   200_000,
        'premium' => 2_000_000,
        'agency'  => 20_000_000,
    ];

    /**
     * Solo 'free' es auto-asignable en el registro público.
     * 'trial' lo activa manualmente el superadmin desde el panel WP.
     *
     * @var list<string>
     */
    public const SELF_SERVICE_PLANS = [ 'free' ];

    /**
     * Obtiene el tenant vinculado al usuario WP actual.
     * Retorna null si el usuario no tiene tenant asociado.
     */
    public function getForCurrentUser(): ?array {
        $userId = get_current_user_id();
        if ( ! $userId ) {
            return null;
        }
        return $this->getByUserId( $userId );
    }

    public function getByUserId( int $userId ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_tenants';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE user_id = %d LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public function getById( int $tenantId ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_tenants';
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE id = %d LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Valida que el tenant está activo y tiene cuota disponible.
     * Retorna el array del tenant para evitar queries adicionales en el llamador.
     *
     * @return array<string, mixed> Fila completa del tenant.
     * @throws \RuntimeException con código HTTP semántico en caso de fallo.
     */
    public function validateForChat( int $tenantId ): array {
        $tenant = $this->getById( $tenantId );

        if ( ! $tenant ) {
            throw new \RuntimeException( 'Tenant no encontrado.', 403 );
        }

        if ( 'active' !== $tenant['status'] ) {
            $code = ( 'suspended' === $tenant['status'] ) ? 403 : 402;
            throw new \RuntimeException(
                sprintf( 'Cuenta en estado "%s". Operación no permitida.', $tenant['status'] ),
                $code
            );
        }

        if ( (int) $tenant['quota_used'] >= (int) $tenant['quota_limit'] ) {
            throw new \RuntimeException( 'Cuota mensual agotada.', 402 );
        }

        return $tenant;
    }

    /**
     * Suma los tokens consumidos en el intercambio a la cuota del tenant.
     * Si tokens = 0 (error o stream vacío) no descuenta nada.
     * Emite alerta cuando el uso supera el 90% del límite.
     *
     * @param int $tokens Total de tokens (input + output) del intercambio.
     */
    public function incrementQuota( int $tenantId, int $tokens ): void {
        if ( $tokens <= 0 ) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'infouno_tenants';

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}` SET quota_used = quota_used + %d WHERE id = %d",
                $tokens,
                $tenantId
            )
        );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT quota_used, quota_limit FROM `{$table}` WHERE id = %d LIMIT 1",
                $tenantId
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            return;
        }

        $limit = (int) $row['quota_limit'];
        $used  = (int) $row['quota_used'];

        if ( $limit > 0 && $used >= (int) ( $limit * 0.9 ) ) {
            $this->dispatchQuotaAlert( $tenantId, $used, $limit );
        }
    }

    /**
     * Dispara un hook de WP para que el resto del sistema reaccione a la alerta de cuota.
     * El consumidor (email, log, webhook) se engancha en 'infouno_quota_low'.
     */
    private function dispatchQuotaAlert( int $tenantId, int $used, int $limit ): void {
        $alertKey = 'infouno_quota_alert_' . $tenantId;

        // Disparar la alerta como máximo una vez cada 24 h para no saturar notificaciones
        if ( get_transient( $alertKey ) ) {
            return;
        }

        set_transient( $alertKey, 1, DAY_IN_SECONDS );

        do_action( 'infouno_quota_low', $tenantId, $used, $limit );

        error_log( sprintf(
            '[INFOUNO] Quota alert: tenant=%d used=%d/%d (%.0f%%)',
            $tenantId,
            $used,
            $limit,
            $limit > 0 ? ( $used / $limit * 100 ) : 0
        ) );
    }

    /**
     * Crea un nuevo tenant vinculado a un usuario WP.
     * La cuota se deriva del plan — no se acepta como parámetro externo.
     *
     * @throws \RuntimeException si el plan no es válido o la inserción falla.
     */
    public function create( int $userId, string $plan = 'free' ): int {
        global $wpdb;

        $sanitizedPlan = sanitize_text_field( $plan );
        if ( ! array_key_exists( $sanitizedPlan, self::PLAN_QUOTAS ) ) {
            $sanitizedPlan = 'free';
        }

        $uuid  = wp_generate_uuid4();
        $table = $wpdb->prefix . 'infouno_tenants';

        // quota_reset_at: 30 días desde hoy. El cron resetea quota_used cuando vence.
        $resetAt = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

        $inserted = $wpdb->insert(
            $table,
            [
                'uuid'           => $uuid,
                'user_id'        => $userId,
                'status'         => 'active',
                'plan'           => $sanitizedPlan,
                'quota_limit'    => self::PLAN_QUOTAS[ $sanitizedPlan ],
                'quota_used'     => 0,
                'quota_reset_at' => $resetAt,
            ],
            [ '%s', '%d', '%s', '%s', '%d', '%d', '%s' ]
        );

        if ( ! $inserted ) {
            throw new \RuntimeException( 'No se pudo crear el tenant.', 500 );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Resetea quota_used = 0 para todos los tenants cuyo quota_reset_at ya venció.
     * Avanza quota_reset_at +30 días para el siguiente ciclo.
     * Llamado por wp_cron via Plugin::resetMonthlyQuotas().
     */
    public function resetExpiredQuotas(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'infouno_tenants';
        $now   = gmdate( 'Y-m-d H:i:s' );

        $tenants = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, plan FROM `{$table}` WHERE quota_reset_at IS NOT NULL AND quota_reset_at <= %s",
                $now
            ),
            ARRAY_A
        );

        foreach ( $tenants as $tenant ) {
            $newResetAt = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );

            $wpdb->update(
                $table,
                [
                    'quota_used'     => 0,
                    'quota_reset_at' => $newResetAt,
                ],
                [ 'id' => (int) $tenant['id'] ],
                [ '%d', '%s' ],
                [ '%d' ]
            );

            // Elimina el transient de alerta para que el nuevo ciclo pueda notificar
            delete_transient( 'infouno_quota_alert_' . $tenant['id'] );
        }

        if ( ! empty( $tenants ) ) {
            error_log( sprintf( '[INFOUNO] Monthly quota reset: %d tenants.', count( $tenants ) ) );
        }
    }

    /**
     * Verifica que un tenant_id pertenece al usuario actual.
     * Guardrail de aislamiento: nunca asumir propiedad por existencia del ID.
     */
    public function currentUserOwns( int $tenantId ): bool {
        $userId = get_current_user_id();
        if ( ! $userId ) {
            return false;
        }

        $tenant = $this->getById( $tenantId );
        return $tenant && (int) $tenant['user_id'] === $userId;
    }
}
