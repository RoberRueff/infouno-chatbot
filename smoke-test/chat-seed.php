<?php
// smoke-test/chat-seed.php — siembra tenant + bot con token y origin conocidos
// para el smoke de transporte (Bloque A: SSE vs ?mode=full). Imprime el token.
// Uso: wp eval-file smoke-test/chat-seed.php --path=<wp>
global $wpdb;

use Infouno\SaaS\Tenant\TenantManager;

$tenantId = (int) $wpdb->get_var(
	"SELECT id FROM {$wpdb->prefix}infouno_tenants WHERE user_id = 1 ORDER BY id LIMIT 1"
);
if ( ! $tenantId ) {
	$tenantId = ( new TenantManager() )->create( 1, 'premium' );
}

// Bot idempotente con token fijo y origin del smoke (localhost:8080).
$token  = 'smoke' . str_repeat( '0', 59 ); // 64 chars, determinista para el curl
$origin = 'http://localhost:8080';

$botId = (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT id FROM {$wpdb->prefix}infouno_bots WHERE public_token = %s LIMIT 1",
	$token
) );

if ( ! $botId ) {
	$wpdb->insert( $wpdb->prefix . 'infouno_bots', [
		'tenant_id'       => $tenantId,
		'bot_name'        => 'Smoke Transport Bot',
		'public_token'    => $token,
		'system_prompt'   => 'Sos un asistente de prueba.',
		'settings'        => wp_json_encode( [ 'context_window' => 10, 'max_conv_tokens' => 20000 ] ),
		'llm_provider'    => 'anthropic',
		'llm_model'       => 'claude-haiku-4-5-20251001',
		'allowed_origins' => $origin,
		'is_active'       => 1,
		'created_at'      => current_time( 'mysql' ),
	] );
	$botId = (int) $wpdb->insert_id;
}

echo "SMOKE_BOT_ID={$botId}\n";
echo "SMOKE_BOT_TOKEN={$token}\n";
echo "SMOKE_ORIGIN={$origin}\n";
echo "DB_VERSION=" . get_option( 'infouno_db_version' ) . "\n";
