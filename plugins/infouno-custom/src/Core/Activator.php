<?php

declare(strict_types=1);

namespace Infouno\SaaS\Core;

final class Activator {

    public static function activate(): void {
        ( new Migrator() )->run();

        // Cron diario: purga mensajes expirados (planes free/trial)
        if ( ! wp_next_scheduled( 'infouno_purge_expired_messages' ) ) {
            wp_schedule_event( time(), 'daily', 'infouno_purge_expired_messages' );
        }

        // Cron cada hora: resetea quota_used para tenants con quota_reset_at vencido
        if ( ! wp_next_scheduled( 'infouno_reset_monthly_quotas' ) ) {
            wp_schedule_event( time(), 'hourly', 'infouno_reset_monthly_quotas' );
        }

        // Cron diario: purga eventos de idempotencia de canales antiguos
        if ( ! wp_next_scheduled( 'infouno_purge_channel_events' ) ) {
            wp_schedule_event( time(), 'daily', 'infouno_purge_channel_events' );
        }

        flush_rewrite_rules();
    }
}
