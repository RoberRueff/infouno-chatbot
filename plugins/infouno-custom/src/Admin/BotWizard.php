<?php

declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\PromptBuilder;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Knowledge Builder — Wizard de configuración de bots para el panel WP Admin.
 *
 * Permite al tenant describir su negocio en pasos simples y genera
 * automáticamente un system_prompt comercial optimizado para PyMEs argentinas.
 *
 * Flujo:
 *   1. El tenant selecciona un bot y completa el formulario.
 *   2. Al enviar, se genera el system_prompt y se muestra un preview.
 *   3. El tenant confirma y el system_prompt + wizard_data se guardan en el bot.
 *
 * Registro: Plugin::boot() → add_action('admin_menu', registerMenu).
 */
final class BotWizard {

    private const PAGE_SLUG   = 'infouno-wizard';
    private const ACTION_SAVE = 'infouno_wizard_save';

    public function __construct(
        private readonly BotManager    $botManager,
        private readonly TenantManager $tenantManager
    ) {}

    public function init(): void {
        add_action( 'admin_post_' . self::ACTION_SAVE, [ $this, 'handleSave' ] );
    }

    public function registerMenu(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Knowledge Builder',
            'Knowledge Builder',
            'read',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // ── Handlers ─────────────────────────────────────────────────────────────

    /**
     * Procesa el POST del formulario del wizard.
     * Genera el system_prompt, lo guarda en el bot y redirige con el resultado.
     */
    public function handleSave(): void {
        check_admin_referer( self::ACTION_SAVE );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin acceso.', 'infouno-custom' ), 403 );
        }

        $botId = (int) ( $_POST['bot_id'] ?? 0 );
        $bot   = $this->botManager->getById( $botId, (int) $tenant['id'] );

        if ( ! $bot ) {
            wp_die( esc_html__( 'Bot no encontrado.', 'infouno-custom' ), 404 );
        }

        $data = $this->sanitizeWizardInput( $_POST );

        $errors = PromptBuilder::validate( $data );
        if ( ! empty( $errors ) ) {
            $query = http_build_query( [
                'page'   => self::PAGE_SLUG,
                'bot_id' => $botId,
                'error'  => implode( ' | ', $errors ),
            ] );
            wp_safe_redirect( admin_url( "admin.php?{$query}" ) );
            exit;
        }

        $generatedPrompt = PromptBuilder::fromWizardData( $data );

        // Guardar wizard_data y el prompt generado en el bot
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'infouno_bots',
            [
                'system_prompt' => $generatedPrompt,
                'wizard_data'   => wp_json_encode( $data ),
            ],
            [
                'id'        => $botId,
                'tenant_id' => (int) $tenant['id'],
            ],
            [ '%s', '%s' ],
            [ '%d', '%d' ]
        );

        $query = http_build_query( [
            'page'    => self::PAGE_SLUG,
            'bot_id'  => $botId,
            'success' => '1',
        ] );
        wp_safe_redirect( admin_url( "admin.php?{$query}" ) );
        exit;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function renderPage(): void {
        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Sin tenant activo.', 'infouno-custom' ) . '</p></div>';
            return;
        }

        $bots         = $this->botManager->getAllForTenant( (int) $tenant['id'] );
        $selectedBotId = (int) ( $_GET['bot_id'] ?? ( $bots[0]['id'] ?? 0 ) );
        $selectedBot  = null;
        $wizardData   = [];

        foreach ( $bots as $b ) {
            if ( (int) $b['id'] === $selectedBotId ) {
                $selectedBot = $b;
                $raw = $b['wizard_data'] ?? null;
                if ( $raw ) {
                    $decoded = json_decode( $raw, true );
                    if ( is_array( $decoded ) ) {
                        $wizardData = $decoded;
                    }
                }
                break;
            }
        }

        $successMsg = ! empty( $_GET['success'] ) ? 'Prompt generado y guardado correctamente.' : '';
        $errorMsg   = ! empty( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Knowledge Builder — Configurá tu bot comercial', 'infouno-custom' ) . '</h1>';
        echo '<p style="color:#555;max-width:700px">' . esc_html__( 'Describí tu negocio en simples pasos y el sistema generará automáticamente el prompt óptimo para que tu bot capture leads calificados.', 'infouno-custom' ) . '</p>';

        if ( $successMsg ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $successMsg ) . '</p></div>';
        }
        if ( $errorMsg ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errorMsg ) . '</p></div>';
        }

        if ( empty( $bots ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'No tenés bots creados. Creá uno primero desde la API o el panel de configuración.', 'infouno-custom' ) . '</p></div>';
            echo '</div>';
            return;
        }

        // Selector de bot
        echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="margin-bottom:20px">';
        echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
        echo '<label for="bot_id_select"><strong>' . esc_html__( 'Bot a configurar:', 'infouno-custom' ) . '</strong> </label>';
        echo '<select name="bot_id" id="bot_id_select" onchange="this.form.submit()">';
        foreach ( $bots as $b ) {
            $sel = ( (int) $b['id'] === $selectedBotId ) ? ' selected' : '';
            echo '<option value="' . esc_attr( (string) $b['id'] ) . '"' . $sel . '>' . esc_html( $b['bot_name'] ) . '</option>';
        }
        echo '</select>';
        echo '</form>';

        if ( ! $selectedBot ) {
            echo '</div>';
            return;
        }

        $this->renderForm( $selectedBotId, $wizardData );
        echo '</div>';
    }

    private function renderForm( int $botId, array $data ): void {
        $postUrl = esc_url( admin_url( 'admin-post.php' ) );

        // Helpers para recuperar valores previos
        $val  = static fn( string $key, string $default = '' ): string => esc_attr( (string) ( $data[ $key ] ?? $default ) );
        $text = static fn( string $key, string $default = '' ): string => esc_textarea( (string) ( $data[ $key ] ?? $default ) );

        $products = array_values( array_filter( array_map( 'trim', (array) ( $data['products'] ?? [] ) ) ) );
        $services = array_values( array_filter( array_map( 'trim', (array) ( $data['services'] ?? [] ) ) ) );
        $faq      = array_values( array_filter( (array) ( $data['faq'] ?? [] ), static fn( $i ) => ! empty( $i['q'] ) ) );

        // Aseguramos mínimo 3 filas en cada sección
        while ( count( $products ) < 3 ) { $products[] = ''; }
        while ( count( $services ) < 3 ) { $services[] = ''; }
        while ( count( $faq ) < 2 ) { $faq[] = [ 'q' => '', 'a' => '' ]; }

        echo '<form method="post" action="' . $postUrl . '">';
        wp_nonce_field( self::ACTION_SAVE );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';
        echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $botId ) . '">';

        echo '<table class="form-table"><tbody>';

        // ── Paso 1: Datos del negocio ─────────────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0">1. Tu negocio</h2></th></tr>';

        echo '<tr><th scope="row"><label for="wz_company">' . esc_html__( 'Nombre de la empresa *', 'infouno-custom' ) . '</label></th>';
        echo '<td><input type="text" id="wz_company" name="company_name" value="' . $val( 'company_name' ) . '" class="regular-text" required placeholder="Ej: Metalúrgica Rodríguez"></td></tr>';

        echo '<tr><th scope="row"><label for="wz_industry">' . esc_html__( 'Rubro / Industria', 'infouno-custom' ) . '</label></th>';
        echo '<td><input type="text" id="wz_industry" name="industry" value="' . $val( 'industry' ) . '" class="regular-text" placeholder="Ej: metalúrgica, inmobiliaria, farmacia"></td></tr>';

        echo '<tr><th scope="row"><label for="wz_location">' . esc_html__( 'Ubicación', 'infouno-custom' ) . '</label></th>';
        echo '<td><input type="text" id="wz_location" name="location" value="' . $val( 'location' ) . '" class="regular-text" placeholder="Ej: Córdoba, Argentina"></td></tr>';

        // ── Paso 2: Productos ──────────────────────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0;margin-top:16px">2. Productos</h2></th></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Lista de productos *', 'infouno-custom' ) . '<br><small>' . esc_html__( '(uno por línea)', 'infouno-custom' ) . '</small></th>';
        echo '<td>';
        foreach ( $products as $i => $p ) {
            echo '<input type="text" name="products[]" value="' . esc_attr( $p ) . '" class="regular-text" style="margin-bottom:4px;display:block" placeholder="' . esc_attr( 'Producto ' . ( $i + 1 ) ) . '">';
        }
        echo '<button type="button" class="button" onclick="addField(this,\'products[]\',\'Producto\')">+ Agregar producto</button>';
        echo '</td></tr>';

        // ── Paso 3: Servicios ──────────────────────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0;margin-top:16px">3. Servicios</h2></th></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Lista de servicios', 'infouno-custom' ) . '<br><small>' . esc_html__( '(uno por línea)', 'infouno-custom' ) . '</small></th>';
        echo '<td>';
        foreach ( $services as $i => $s ) {
            echo '<input type="text" name="services[]" value="' . esc_attr( $s ) . '" class="regular-text" style="margin-bottom:4px;display:block" placeholder="' . esc_attr( 'Servicio ' . ( $i + 1 ) ) . '">';
        }
        echo '<button type="button" class="button" onclick="addField(this,\'services[]\',\'Servicio\')">+ Agregar servicio</button>';
        echo '</td></tr>';

        // ── Paso 4: FAQ ────────────────────────────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0;margin-top:16px">4. Preguntas frecuentes</h2></th></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Preguntas y respuestas', 'infouno-custom' ) . '</th>';
        echo '<td id="faq_container">';
        foreach ( $faq as $i => $item ) {
            $q = esc_attr( (string) ( $item['q'] ?? '' ) );
            $a = esc_attr( (string) ( $item['a'] ?? '' ) );
            echo '<div style="margin-bottom:12px;border-left:3px solid #2271b1;padding-left:10px">';
            echo '<input type="text" name="faq[' . $i . '][q]" value="' . $q . '" class="large-text" placeholder="' . esc_attr__( 'Pregunta', 'infouno-custom' ) . '" style="margin-bottom:4px">';
            echo '<input type="text" name="faq[' . $i . '][a]" value="' . $a . '" class="large-text" placeholder="' . esc_attr__( 'Respuesta', 'infouno-custom' ) . '">';
            echo '</div>';
        }
        echo '</td></tr>';

        // ── Paso 5: Cobertura y horarios ───────────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0;margin-top:16px">5. Operación</h2></th></tr>';

        echo '<tr><th scope="row"><label for="wz_coverage">' . esc_html__( 'Zona de cobertura', 'infouno-custom' ) . '</label></th>';
        echo '<td><input type="text" id="wz_coverage" name="coverage" value="' . $val( 'coverage' ) . '" class="large-text" placeholder="Ej: CABA y GBA, envíos a todo el país"></td></tr>';

        echo '<tr><th scope="row"><label for="wz_hours">' . esc_html__( 'Horarios de atención', 'infouno-custom' ) . '</label></th>';
        echo '<td><input type="text" id="wz_hours" name="hours" value="' . $val( 'hours' ) . '" class="large-text" placeholder="Ej: Lunes a viernes 9-18hs, sábados 9-13hs"></td></tr>';

        // ── Paso 6: Tono y objetivo del lead ──────────────────────────────────
        echo '<tr><th colspan="2"><h2 style="margin:0;margin-top:16px">6. Tono y objetivo</h2></th></tr>';

        echo '<tr><th scope="row"><label for="wz_tone">' . esc_html__( 'Tono del bot', 'infouno-custom' ) . '</label></th>';
        echo '<td>';
        $toneOptions = [
            'cercano y profesional' => 'Cercano y profesional (recomendado)',
            'formal'                => 'Formal / corporativo',
            'informal y amigable'   => 'Informal y amigable',
            'técnico y preciso'     => 'Técnico y preciso',
        ];
        $currentTone = $data['welcome_tone'] ?? 'cercano y profesional';
        echo '<select id="wz_tone" name="welcome_tone" class="regular-text">';
        foreach ( $toneOptions as $toneVal => $toneLabel ) {
            $sel = ( $currentTone === $toneVal ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $toneVal ) . '"' . $sel . '>' . esc_html( $toneLabel ) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wz_lead_goal">' . esc_html__( 'Objetivo de captura', 'infouno-custom' ) . '</label></th>';
        echo '<td>';
        echo '<input type="text" id="wz_lead_goal" name="lead_goal" value="' . $val( 'lead_goal', 'Capturar el nombre y el número de WhatsApp del interesado.' ) . '" class="large-text">';
        echo '<p class="description">' . esc_html__( 'Qué dato quiere conseguir el bot de cada potencial cliente.', 'infouno-custom' ) . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit">';
        echo '<input type="submit" class="button button-primary button-large" value="' . esc_attr__( 'Generar y guardar prompt', 'infouno-custom' ) . '">';
        echo '</p>';
        echo '</form>';

        // Script mínimo para agregar campos dinámicamente
        echo '<script>
function addField(btn, name, placeholder) {
    var input = document.createElement("input");
    input.type = "text";
    input.name = name;
    input.className = "regular-text";
    input.style = "margin-bottom:4px;display:block";
    input.placeholder = placeholder + " " + (btn.parentNode.querySelectorAll("input").length + 1);
    btn.parentNode.insertBefore(input, btn);
    input.focus();
}
</script>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Sanitiza todos los campos del POST del wizard.
     * Nunca confiar en datos crudos del usuario — sanitizar campo por campo.
     *
     * @return array<string, mixed>
     */
    private function sanitizeWizardInput( array $post ): array {
        $sf = 'sanitize_text_field';

        $products = array_values( array_filter(
            array_map( $sf, (array) ( $post['products'] ?? [] ) )
        ) );

        $services = array_values( array_filter(
            array_map( $sf, (array) ( $post['services'] ?? [] ) )
        ) );

        $faq = [];
        foreach ( (array) ( $post['faq'] ?? [] ) as $item ) {
            $q = $sf( (string) ( $item['q'] ?? '' ) );
            $a = $sf( (string) ( $item['a'] ?? '' ) );
            if ( $q && $a ) {
                $faq[] = [ 'q' => $q, 'a' => $a ];
            }
        }

        $toneAllowed = [ 'cercano y profesional', 'formal', 'informal y amigable', 'técnico y preciso' ];
        $tone        = $sf( (string) ( $post['welcome_tone'] ?? '' ) );
        if ( ! in_array( $tone, $toneAllowed, true ) ) {
            $tone = 'cercano y profesional';
        }

        return [
            'company_name' => $sf( (string) ( $post['company_name'] ?? '' ) ),
            'industry'     => $sf( (string) ( $post['industry']     ?? '' ) ),
            'location'     => $sf( (string) ( $post['location']     ?? '' ) ),
            'products'     => $products,
            'services'     => $services,
            'faq'          => $faq,
            'coverage'     => $sf( (string) ( $post['coverage']     ?? '' ) ),
            'hours'        => $sf( (string) ( $post['hours']        ?? '' ) ),
            'welcome_tone' => $tone,
            'lead_goal'    => $sf( (string) ( $post['lead_goal']    ?? '' ) ),
        ];
    }
}
