<?php
/**
 * Plugin Name:       Infouno Custom SaaS
 * Plugin URI:        https://infouno.com
 * Description:       Core engine del SaaS Multitenant de Chatbots. Gestiona tenants, bots, consumo de LLMs y economía de tokens.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Infouno
 * License:           Proprietary
 * Text Domain:       infouno-custom
 */

declare(strict_types=1);

namespace Infouno\SaaS;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'INFOUNO_VERSION',     '0.1.0' );
define( 'INFOUNO_PLUGIN_FILE', __FILE__ );
define( 'INFOUNO_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'INFOUNO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'INFOUNO_DB_VERSION',  '11' );
define( 'INFOUNO_CONSENT_VERSION', '1.0' );

require_once INFOUNO_PLUGIN_DIR . 'vendor/autoload.php';

// Action Scheduler: cola de jobs en background para procesar webhooks de canales.
$infouno_as = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $infouno_as ) ) {
    require_once $infouno_as;
}

register_activation_hook( __FILE__, [ Core\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Core\Deactivator::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function () {
    Plugin::instance()->boot();
} );
