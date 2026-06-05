<?php
/**
 * Runtime check ejecutable (local y CI): ejercita en un WordPress REAL los caminos
 * que NO cubren los tests unitarios — la cadena comercial (lead → opportunity →
 * automation), la terminalidad de stages, las métricas y el render de los paneles
 * admin. Sale con código != 0 ante cualquier fatal o aserción fallida.
 *
 * Uso: wp eval-file smoke-test/runtime-check.php --path=<wp>
 *
 * Existe porque un fatal que rompía el arranque del plugin (union type ilegal) y
 * otros bugs de runtime pasaron los tests unitarios + lint y solo se vieron al
 * correr el plugin de verdad. Este gate evita que reaparezcan.
 */

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Tenant\TenantManager;
use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Opportunity\OpportunityService;
use Infouno\SaaS\Admin\LeadDashboard;
use Infouno\SaaS\Admin\OpportunityDashboard;
use Infouno\SaaS\Admin\BotDashboard;
use Infouno\SaaS\Admin\BotWizard;

global $wpdb;

$fail = static function ( string $msg ): void {
    fwrite( STDERR, "RUNTIME-CHECK FAIL: {$msg}\n" );
    exit( 1 );
};

// — Seed idempotente: tenant (user 1) + bot —
$tenantId = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}infouno_tenants WHERE user_id = 1 ORDER BY id LIMIT 1" );
if ( ! $tenantId ) {
    $tenantId = ( new TenantManager() )->create( 1, 'premium' );
}
$botId = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}infouno_bots WHERE tenant_id = %d LIMIT 1", $tenantId ) );
if ( ! $botId ) {
    $wpdb->insert( $wpdb->prefix . 'infouno_bots', [
        'tenant_id'     => $tenantId,
        'bot_name'      => 'Runtime Check Bot',
        'public_token'  => bin2hex( random_bytes( 32 ) ),
        'system_prompt' => 'check',
        'settings'      => json_encode( [ 'max_tokens' => 1024 ] ),
        'is_active'     => 1,
    ] );
    $botId = (int) $wpdb->insert_id;
}

// — Lead calificado nuevo + disparo del hook comercial —
$wpdb->insert( $wpdb->prefix . 'infouno_leads', [
    'tenant_id'    => $tenantId,
    'bot_id'       => $botId,
    'session_hash' => hash( 'sha256', 'runtime-check-' . uniqid( '', true ) ),
    'name'         => 'Runtime Check',
    'email'        => 'rc@test.com',
    'interest'     => 'compra',
    'score'        => 75,
    'temperature'  => 'hot',
    'status'       => 'new',
] );
$leadId = (int) $wpdb->insert_id;
$leadId || $fail( 'no se pudo insertar el lead' );

do_action( 'infouno_lead_captured', $leadId, $tenantId, $botId, [
    'score'        => 75,
    'is_qualified' => true,
    'fields'       => [ 'name' => 'Runtime Check', 'email' => 'rc@test.com', 'interest' => 'compra' ],
] );

$repo    = new OpportunityRepository();
$service = new OpportunityService( $repo );

$opp = $repo->getActiveByLead( $leadId, $tenantId );
$opp || $fail( 'lead_captured no creó una oportunidad activa' );
$oppId = (int) $opp['id'];

// — Terminalidad de stages —
$service->updateStage( $oppId, $tenantId, 'won' ) || $fail( 'no se pudo mover a won' );
if ( false !== $service->updateStage( $oppId, $tenantId, 'quoted' ) ) {
    $fail( 'won es terminal: no debería permitir won→quoted' );
}

// — Métricas —
$metrics = $service->getPipelineMetrics( $tenantId );
( is_array( $metrics ) && isset( $metrics['total'], $metrics['by_stage'] ) ) || $fail( 'getPipelineMetrics devolvió una forma inesperada' );

// — Render de los 4 paneles admin (no deben fatalear) —
wp_set_current_user( 1 );
$tm = new TenantManager();
$bm = new BotManager();
$panels = [
    'LeadDashboard'        => new LeadDashboard( $tm ),
    'OpportunityDashboard' => new OpportunityDashboard( $tm, $repo ),
    'BotDashboard'         => new BotDashboard( $bm, $tm ),
    'BotWizard'            => new BotWizard( $bm, $tm ),
];
foreach ( $panels as $name => $panel ) {
    try {
        ob_start();
        $panel->renderPage();
        $bytes = strlen( (string) ob_get_clean() );
    } catch ( \Throwable $e ) {
        ob_end_clean();
        $fail( "render de {$name} fataleó: " . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
    }
    $bytes > 0 || $fail( "render de {$name} no produjo salida" );
}

echo "RUNTIME-CHECK OK: cadena comercial, terminalidad, métricas y 4 paneles admin sin fatal\n";
