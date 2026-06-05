<?php

declare(strict_types=1);

namespace Infouno\SaaS\Core;

final class Deactivator {

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'infouno_purge_expired_messages' );
        wp_clear_scheduled_hook( 'infouno_reset_monthly_quotas' );
        wp_clear_scheduled_hook( 'infouno_purge_channel_events' );

        flush_rewrite_rules();
    }
}
