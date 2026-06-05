<?php
// smoke-test/wa-seed.php — siembra un canal whatsapp y muestra routing_key/app_secret.
global $wpdb;
$tenantId = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}infouno_tenants ORDER BY id LIMIT 1" );
$botId    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}infouno_bots WHERE tenant_id=%d LIMIT 1", $tenantId ) );
$vault    = new \Infouno\SaaS\Security\CredentialVault( INFOUNO_ENCRYPTION_KEY );
$repo     = new \Infouno\SaaS\Channel\ChannelRepository( $vault );
$rk       = 'wa_' . bin2hex( random_bytes( 6 ) );
$repo->create( $tenantId, $botId, 'whatsapp', $rk, [
    'access_token' => 'fake-token', 'phone_number_id' => 'PNID-1',
    'app_secret'   => 'smoke-secret', 'verify_token' => 'smoke-verify',
], '', '@SmokeWA' );
echo "WA_ROUTING_KEY={$rk}\n";
