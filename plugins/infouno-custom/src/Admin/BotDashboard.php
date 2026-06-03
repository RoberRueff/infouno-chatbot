<?php

declare(strict_types=1);

namespace Infouno\SaaS\Admin;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Panel de gestión de bots en el WP Admin.
 *
 * Permite al tenant crear, editar, activar/desactivar y eliminar bots
 * sin tocar código. También genera el embed snippet listo para copiar.
 *
 * Flujo del tenant:
 *   1. Crea un bot con nombre y prompt base.
 *   2. Refina el prompt con el Knowledge Builder.
 *   3. Copia el snippet y lo pega en su sitio.
 *   4. Empieza a recibir leads.
 */
final class BotDashboard {

    private const PAGE_SLUG      = 'infouno-bots';
    private const ACTION_CREATE  = 'infouno_bot_create';
    private const ACTION_UPDATE  = 'infouno_bot_update';
    private const ACTION_DELETE  = 'infouno_bot_delete';
    private const ACTION_TOGGLE  = 'infouno_bot_toggle';

    private const LLM_LABELS = [
        'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 — Rápido y económico (recomendado)',
        'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 — Balance calidad/velocidad',
        'claude-opus-4-8'           => 'Claude Opus 4.8 — Máxima calidad',
        'gpt-4o-mini'               => 'GPT-4o Mini — Rápido y económico',
        'gpt-4o'                    => 'GPT-4o — Alta calidad OpenAI',
    ];

    public function __construct(
        private readonly BotManager    $botManager,
        private readonly TenantManager $tenantManager,
    ) {}

    public function init(): void {
        add_action( 'admin_menu',                               [ $this, 'addMenuPage' ] );
        add_action( 'admin_post_' . self::ACTION_CREATE, [ $this, 'handleCreate' ] );
        add_action( 'admin_post_' . self::ACTION_UPDATE, [ $this, 'handleUpdate' ] );
        add_action( 'admin_post_' . self::ACTION_DELETE, [ $this, 'handleDelete' ] );
        add_action( 'admin_post_' . self::ACTION_TOGGLE, [ $this, 'handleToggle' ] );
    }

    public function addMenuPage(): void {
        add_submenu_page(
            'infouno-dashboard',
            'Mis Bots',
            'Mis Bots',
            'read',
            self::PAGE_SLUG,
            [ $this, 'renderPage' ]
        );
    }

    // ── Render principal ──────────────────────────────────────────────────────

    public function renderPage(): void {
        $tenant = $this->tenantManager->getForCurrentUser();

        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin tenant activo.', 'infouno-custom' ), 403 );
        }

        $tenantId = (int) $tenant['id'];
        $plan     = $tenant['plan'] ?? 'free';
        $botLimit = BotManager::PLAN_BOT_LIMITS[ $plan ] ?? 1;
        $bots     = $this->botManager->getAllForTenant( $tenantId );
        $botCount = count( $bots );

        $view     = sanitize_text_field( $_GET['view']   ?? 'list' ); // phpcs:ignore
        $editId   = (int) ( $_GET['bot_id'] ?? 0 );                    // phpcs:ignore
        $snippetId = (int) ( $_GET['snippet'] ?? 0 );                  // phpcs:ignore

        // Mensajes de feedback
        $this->renderNotices();

        echo '<div class="wrap">';
        echo '<h1 style="display:flex;align-items:center;gap:12px">';
        echo esc_html__( 'Mis Bots', 'infouno-custom' );
        if ( $botCount < $botLimit ) {
            printf(
                ' <a href="%s" class="page-title-action">+ Crear bot</a>',
                esc_url( add_query_arg( 'view', 'create', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) )
            );
        }
        echo '</h1>';

        printf(
            '<p style="color:#646970">Plan <strong>%s</strong> — %d/%d bots usados.</p>',
            esc_html( strtoupper( $plan ) ),
            $botCount,
            $botLimit
        );

        if ( 'create' === $view ) {
            $this->renderForm( $tenantId, $plan );
        } elseif ( 'edit' === $view && $editId ) {
            $bot = $this->botManager->getById( $editId, $tenantId );
            if ( $bot ) {
                $this->renderForm( $tenantId, $plan, $bot );
            }
        } elseif ( $snippetId ) {
            $bot = $this->botManager->getById( $snippetId, $tenantId );
            if ( $bot ) {
                $this->renderSnippet( $bot );
            }
        } else {
            $this->renderList( $bots, $tenantId );
        }

        echo '</div>';
    }

    // ── Lista de bots ─────────────────────────────────────────────────────────

    private function renderList( array $bots, int $tenantId ): void {
        if ( empty( $bots ) ) {
            echo '<div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:40px;text-align:center;margin-top:20px">';
            echo '<p style="font-size:16px;color:#374151;margin-bottom:16px">Todavía no creaste ningún bot.</p>';
            printf(
                '<a href="%s" class="button button-primary button-large">Crear mi primer bot</a>',
                esc_url( add_query_arg( 'view', 'create', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) )
            );
            echo '</div>';
            return;
        }

        // Contadores de leads por bot (una sola query)
        global $wpdb;
        $leadCounts = [];
        $botIds     = array_map( static fn( $b ) => (int) $b['id'], $bots );
        if ( $botIds ) {
            $placeholders = implode( ',', array_fill( 0, count( $botIds ), '%d' ) );
            $rows         = $wpdb->get_results(
                $wpdb->prepare( // phpcs:ignore
                    "SELECT bot_id, COUNT(*) AS total
                     FROM `{$wpdb->prefix}infouno_leads`
                     WHERE bot_id IN ({$placeholders}) AND tenant_id = %d
                     GROUP BY bot_id",
                    ...array_merge( $botIds, [ $tenantId ] )
                ),
                ARRAY_A
            );
            foreach ( $rows ?: [] as $row ) {
                $leadCounts[ (int) $row['bot_id'] ] = (int) $row['total'];
            }
        }

        echo '<table class="wp-list-table widefat fixed striped" style="margin-top:16px">';
        echo '<thead><tr>';
        foreach ( [ 'Bot', 'Token público', 'Modelo', 'Leads', 'Estado', 'Acciones' ] as $col ) {
            echo '<th>' . esc_html( $col ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $bots as $bot ) {
            $botId    = (int) $bot['id'];
            $isActive = (bool) $bot['is_active'];
            $leads    = $leadCounts[ $botId ] ?? 0;

            $editUrl    = add_query_arg( [ 'view' => 'edit', 'bot_id' => $botId ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            $snippetUrl = add_query_arg( [ 'snippet' => $botId ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            $wizardUrl  = add_query_arg( [ 'page' => 'infouno-wizard', 'bot_id' => $botId ], admin_url( 'admin.php' ) );
            $toggleUrl  = wp_nonce_url(
                admin_url( 'admin-post.php?action=' . self::ACTION_TOGGLE . '&bot_id=' . $botId . '&active=' . ( $isActive ? '0' : '1' ) ),
                self::ACTION_TOGGLE . '_' . $botId
            );
            $deleteUrl  = wp_nonce_url(
                admin_url( 'admin-post.php?action=' . self::ACTION_DELETE . '&bot_id=' . $botId ),
                self::ACTION_DELETE . '_' . $botId
            );

            $token       = (string) ( $bot['public_token'] ?? '' );
            $tokenMasked = substr( $token, 0, 8 ) . '…' . substr( $token, -4 );
            $model       = (string) ( $bot['llm_model'] ?? '—' );
            $modelLabel  = self::LLM_LABELS[ $model ] ?? $model;
            $modelShort  = explode( ' ', $modelLabel )[0] . ' ' . ( explode( ' ', $modelLabel )[1] ?? '' );

            echo '<tr>';
            printf(
                '<td><strong>%s</strong><br><span style="font-size:11px;color:#646970">Creado: %s</span></td>',
                esc_html( $bot['bot_name'] ?? '—' ),
                esc_html( substr( $bot['created_at'] ?? '', 0, 10 ) )
            );
            printf(
                '<td><code style="font-size:11px">%s</code></td>',
                esc_html( $tokenMasked )
            );
            printf( '<td style="font-size:12px">%s</td>', esc_html( $modelShort ) );
            printf( '<td><strong>%d</strong></td>', $leads );

            // Estado con toggle
            printf(
                '<td><a href="%s" class="button button-small" style="color:%s">%s</a></td>',
                esc_url( $toggleUrl ),
                $isActive ? '#00a32a' : '#646970',
                $isActive ? '● Activo' : '○ Inactivo'
            );

            // Acciones
            echo '<td style="white-space:nowrap">';
            printf( '<a href="%s" class="button button-small">✏ Editar</a> ', esc_url( $editUrl ) );
            printf( '<a href="%s" class="button button-small">🧙 Wizard</a> ', esc_url( $wizardUrl ) );
            printf( '<a href="%s" class="button button-primary button-small">&lt;/&gt; Snippet</a> ', esc_url( $snippetUrl ) );
            printf(
                '<a href="%s" class="button button-small" style="color:#d63638" onclick="return confirm(\'¿Eliminar el bot %s? Esta acción no se puede deshacer.\')">✕</a>',
                esc_url( $deleteUrl ),
                esc_js( (string) ( $bot['bot_name'] ?? '' ) )
            );
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    // ── Formulario crear/editar ───────────────────────────────────────────────

    private function renderForm( int $tenantId, string $plan, ?array $bot = null ): void {
        $isEdit    = $bot !== null;
        $action    = $isEdit ? self::ACTION_UPDATE : self::ACTION_CREATE;
        $title     = $isEdit ? 'Editar bot' : 'Crear nuevo bot';
        $postUrl   = esc_url( admin_url( 'admin-post.php' ) );
        $listUrl   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

        $settings  = $bot['settings'] ?? [];
        $val       = static fn( string $k, string $default = '' ): string =>
            esc_attr( (string) ( $settings[ $k ] ?? $default ) );

        $allowedModels = LLMRouter::ALLOWED_MODELS[ $plan ] ?? LLMRouter::ALLOWED_MODELS['free'];
        $currentModel  = $bot['llm_model'] ?? 'claude-haiku-4-5-20251001';
        $currentProvider = $bot['llm_provider'] ?? 'anthropic';

        printf( '<h2>%s</h2>', esc_html( $title ) );
        echo '<form method="post" action="' . $postUrl . '" style="max-width:700px;margin-top:16px">';
        wp_nonce_field( $action );
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
        if ( $isEdit ) {
            echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot['id'] ) . '">';
        }

        echo '<table class="form-table"><tbody>';

        // ── Nombre ────────────────────────────────────────────────────────────
        echo '<tr><th><label for="bot_name">Nombre del bot *</label></th><td>';
        printf(
            '<input type="text" id="bot_name" name="bot_name" value="%s" class="regular-text" required placeholder="Ej: Lucas de TechHogar">',
            esc_attr( $bot['bot_name'] ?? '' )
        );
        echo '<p class="description">El nombre visible en el header del chat.</p></td></tr>';

        // ── Mensaje de bienvenida ─────────────────────────────────────────────
        echo '<tr><th><label for="welcome_message">Mensaje de bienvenida</label></th><td>';
        printf(
            '<input type="text" id="welcome_message" name="settings[welcome_message]" value="%s" class="large-text" placeholder="¡Hola! ¿En qué te puedo ayudar hoy?">',
            $val( 'welcome_message', '¡Hola! ¿En qué puedo ayudarte?' )
        );
        echo '<p class="description">Aparece al abrir el chat. Incluí el nombre del asesor y un emoji para +2x clics.</p></td></tr>';

        // ── WhatsApp ──────────────────────────────────────────────────────────
        echo '<tr><th><label for="whatsapp_number">WhatsApp del negocio</label></th><td>';
        printf(
            '<input type="text" id="whatsapp_number" name="settings[whatsapp_number]" value="%s" class="regular-text" placeholder="+5491112345678">',
            $val( 'whatsapp_number' )
        );
        echo '<p class="description">Con código de país. Si se configura, aparece un botón WhatsApp en el footer del chat.</p></td></tr>';

        // ── Quick Replies ─────────────────────────────────────────────────────
        $quickReplies = $settings['quick_replies'] ?? [];
        if ( is_string( $quickReplies ) ) {
            $quickReplies = json_decode( $quickReplies, true ) ?: [];
        }
        while ( count( $quickReplies ) < 4 ) {
            $quickReplies[] = [ 'label' => '', 'value' => '' ];
        }

        echo '<tr><th>Respuestas rápidas<br><small style="font-weight:400">Botones al abrir el chat</small></th><td>';
        echo '<div id="quick-replies-container">';
        foreach ( $quickReplies as $i => $qr ) {
            printf(
                '<div style="display:flex;gap:8px;margin-bottom:6px">
                    <input type="text" name="quick_replies[%d][label]" value="%s" class="regular-text" placeholder="Texto del botón (ej: Quiero un presupuesto)">
                    <input type="text" name="quick_replies[%d][value]" value="%s" style="width:200px" placeholder="Mensaje enviado (opcional)">
                </div>',
                $i, esc_attr( (string) ( $qr['label'] ?? '' ) ),
                $i, esc_attr( (string) ( $qr['value'] ?? '' ) )
            );
        }
        echo '</div>';
        echo '<p class="description">Label: texto visible. Mensaje: lo que se envía al bot (si está vacío usa el label).</p>';
        echo '</td></tr>';

        // ── Dominios permitidos (CORS) ─────────────────────────────────────────
        echo '<tr><th><label for="allowed_origins">Dominios permitidos *</label></th><td>';
        printf(
            '<textarea id="allowed_origins" name="allowed_origins" rows="3" class="large-text" placeholder="https://tutienda.com.ar\nhttps://www.tutienda.com.ar">%s</textarea>',
            esc_textarea( $bot['allowed_origins'] ?? '' )
        );
        echo '<p class="description">Un dominio por línea, con protocolo (https://). Sin esto el chat no responde en ningún sitio.</p></td></tr>';

        // ── Webhook URL ────────────────────────────────────────────────────────
        echo '<tr><th><label for="webhook_url">Webhook al CRM</label></th><td>';
        printf(
            '<input type="url" id="webhook_url" name="settings[webhook_url]" value="%s" class="large-text" placeholder="https://hooks.zapier.com/...">',
            $val( 'webhook_url' )
        );
        echo '<p class="description">Recibe un POST cuando se crea una oportunidad, cambia de stage o se cierra una venta.</p></td></tr>';

        // ── Modelo LLM ────────────────────────────────────────────────────────
        echo '<tr><th><label for="llm_model">Modelo de IA</label></th><td>';
        echo '<select id="llm_model" name="llm_model">';
        foreach ( $allowedModels as $modelId ) {
            $label    = self::LLM_LABELS[ $modelId ] ?? $modelId;
            $provider = str_contains( $modelId, 'claude' ) ? 'anthropic' : 'openai';
            $selected = selected( $currentModel, $modelId, false );
            printf(
                '<option value="%s" data-provider="%s"%s>%s</option>',
                esc_attr( $modelId ),
                esc_attr( $provider ),
                $selected,
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">Para la mayoría de las PyMEs, Haiku es suficiente y el más económico.</p></td></tr>';

        // ── System Prompt ─────────────────────────────────────────────────────
        echo '<tr><th><label for="system_prompt">System Prompt</label></th><td>';
        printf(
            '<textarea id="system_prompt" name="system_prompt" rows="8" class="large-text" placeholder="Sos el asistente de ventas de...">%s</textarea>',
            esc_textarea( $bot['system_prompt'] ?? '' )
        );
        echo '<p class="description">El "vendedor" del bot. Podés generarlo automáticamente con el <a href="' .
             esc_url( admin_url( 'admin.php?page=infouno-wizard' ) ) . '">Knowledge Builder</a>.</p></td></tr>';

        echo '</tbody></table>';

        echo '<p class="submit">';
        echo '<input type="submit" class="button button-primary button-large" value="' . ( $isEdit ? 'Guardar cambios' : 'Crear bot' ) . '">';
        printf( ' <a href="%s" class="button button-large">Cancelar</a>', esc_url( $listUrl ) );
        echo '</p>';
        echo '</form>';

        // Script para sincronizar llm_provider según el modelo seleccionado
        echo '<script>
        document.getElementById("llm_model").addEventListener("change", function() {
            var opt = this.options[this.selectedIndex];
            document.querySelectorAll("input[name=llm_provider]").forEach(function(el) {
                el.value = opt.dataset.provider || "anthropic";
            });
        });
        </script>';
    }

    // ── Embed Snippet ─────────────────────────────────────────────────────────

    private function renderSnippet( array $bot ): void {
        $settings      = $bot['settings'] ?? [];
        $token         = (string) ( $bot['public_token'] ?? '' );
        $apiUrl        = trailingslashit( get_site_url() ) . 'wp-json/infouno/v1/chat';
        $botName       = (string) ( $bot['bot_name'] ?? 'Asistente' );
        $welcome       = (string) ( $settings['welcome_message'] ?? '¡Hola! ¿En qué puedo ayudarte?' );
        $whatsapp      = (string) ( $settings['whatsapp_number'] ?? '' );

        $quickReplies = $settings['quick_replies'] ?? [];
        if ( is_string( $quickReplies ) ) {
            $quickReplies = json_decode( $quickReplies, true ) ?: [];
        }
        $quickReplies = array_values( array_filter(
            $quickReplies,
            static fn( $r ) => ! empty( trim( (string) ( $r['label'] ?? '' ) ) )
        ) );

        // Construir el snippet
        $lines   = [];
        $lines[] = '<script';
        $lines[] = '  src="' . esc_attr( plugins_url( 'client-widget/dist/widget.js', dirname( __DIR__ ) ) ) . '"';
        $lines[] = '  data-bot-token="' . esc_attr( $token ) . '"';
        $lines[] = '  data-api-url="' . esc_attr( $apiUrl ) . '"';
        $lines[] = '  data-bot-name="' . esc_attr( $botName ) . '"';
        $lines[] = '  data-welcome="' . esc_attr( $welcome ) . '"';
        if ( $whatsapp ) {
            $lines[] = '  data-whatsapp="' . esc_attr( $whatsapp ) . '"';
        }
        if ( ! empty( $quickReplies ) ) {
            $jsonReplies = wp_json_encode( $quickReplies, JSON_UNESCAPED_UNICODE );
            $lines[]     = "  data-quick-replies='" . esc_attr( $jsonReplies ) . "'";
        }
        $lines[] = '  data-privacy-url="[URL-DE-TU-POLÍTICA-DE-PRIVACIDAD]"';
        $lines[] = '></script>';

        $snippet = implode( "\n", $lines );

        $listUrl = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

        printf( '<h2>Snippet de instalación — <em>%s</em></h2>', esc_html( $botName ) );

        echo '<div style="background:#1e1e2e;border-radius:8px;padding:20px;margin:16px 0;position:relative">';
        echo '<pre id="iw-snippet" style="color:#cdd6f4;font-family:monospace;font-size:13px;margin:0;overflow-x:auto;white-space:pre">';
        echo esc_html( $snippet );
        echo '</pre>';
        echo '<button onclick="copySnippet()" style="position:absolute;top:12px;right:12px;background:#4F46E5;color:#fff;border:none;border-radius:6px;padding:6px 14px;cursor:pointer;font-size:12px" id="copy-btn">Copiar</button>';
        echo '</div>';

        echo '<div style="background:#fef9c3;border:1px solid #fde68a;border-radius:6px;padding:14px 18px;margin-bottom:16px;font-size:13px">';
        echo '<strong>Instrucciones:</strong><ol style="margin:8px 0 0 18px;padding:0;line-height:1.8">';
        echo '<li>Pegá este código <strong>antes del cierre de <code>&lt;/body&gt;</code></strong> en tu sitio.</li>';
        echo '<li>Reemplazá <code>[URL-DE-TU-POLÍTICA-DE-PRIVACIDAD]</code> con la URL real o eliminá esa línea.</li>';
        echo '<li>Asegurate de que el dominio de tu sitio esté en la lista de <strong>Dominios permitidos</strong> del bot.</li>';
        echo '<li>El chat aparece como botón flotante en la esquina inferior derecha.</li>';
        echo '</ol></div>';

        echo '<div style="display:flex;gap:10px">';
        printf( '<a href="%s" class="button button-primary">← Volver a mis bots</a>', esc_url( $listUrl ) );
        printf(
            '<a href="%s" class="button">✏ Editar este bot</a>',
            esc_url( add_query_arg( [ 'view' => 'edit', 'bot_id' => (int) $bot['id'] ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) )
        );
        printf(
            '<a href="%s" class="button">🧙 Knowledge Builder</a>',
            esc_url( add_query_arg( [ 'page' => 'infouno-wizard', 'bot_id' => (int) $bot['id'] ], admin_url( 'admin.php' ) ) )
        );
        echo '</div>';

        echo '<script>
        function copySnippet() {
            var text = document.getElementById("iw-snippet").innerText;
            navigator.clipboard.writeText(text).then(function() {
                var btn = document.getElementById("copy-btn");
                btn.textContent = "✓ Copiado";
                btn.style.background = "#00a32a";
                setTimeout(function() {
                    btn.textContent = "Copiar";
                    btn.style.background = "#4F46E5";
                }, 2000);
            });
        }
        </script>';
    }

    // ── Handlers de POST ──────────────────────────────────────────────────────

    public function handleCreate(): void {
        check_admin_referer( self::ACTION_CREATE );

        $tenant   = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin acceso.', 'infouno-custom' ), 403 );
        }

        $tenantId = (int) $tenant['id'];
        $plan     = $tenant['plan'] ?? 'free';
        $limit    = BotManager::PLAN_BOT_LIMITS[ $plan ] ?? 1;

        if ( $this->botManager->countForTenant( $tenantId ) >= $limit ) {
            wp_safe_redirect( add_query_arg( 'error', 'limit', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
            exit();
        }

        $data = $this->sanitizeFormInput( $_POST ); // phpcs:ignore

        try {
            $botId = $this->botManager->create( $tenantId, $data );
            wp_safe_redirect( add_query_arg( [ 'snippet' => $botId, 'created' => '1' ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        } catch ( \RuntimeException $e ) {
            wp_safe_redirect( add_query_arg( 'error', 'create', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        }

        exit();
    }

    public function handleUpdate(): void {
        check_admin_referer( self::ACTION_UPDATE );

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_die( esc_html__( 'Sin acceso.', 'infouno-custom' ), 403 );
        }

        $tenantId = (int) $tenant['id'];
        $botId    = (int) ( $_POST['bot_id'] ?? 0 ); // phpcs:ignore

        $bot = $this->botManager->getById( $botId, $tenantId );
        if ( ! $bot ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit();
        }

        $data = $this->sanitizeFormInput( $_POST ); // phpcs:ignore
        $this->botManager->update( $botId, $tenantId, $data );

        wp_safe_redirect( add_query_arg( [ 'snippet' => $botId, 'updated' => '1' ], admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit();
    }

    public function handleDelete(): void {
        $botId = (int) ( $_GET['bot_id'] ?? 0 ); // phpcs:ignore

        if ( ! check_admin_referer( self::ACTION_DELETE . '_' . $botId ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'infouno-custom' ), 403 );
        }

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit();
        }

        $this->botManager->delete( $botId, (int) $tenant['id'] );

        wp_safe_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit();
    }

    public function handleToggle(): void {
        $botId    = (int) ( $_GET['bot_id'] ?? 0 ); // phpcs:ignore
        $newState = (int) ( $_GET['active'] ?? 0 );  // phpcs:ignore

        if ( ! check_admin_referer( self::ACTION_TOGGLE . '_' . $botId ) ) {
            wp_die( esc_html__( 'Nonce inválido.', 'infouno-custom' ), 403 );
        }

        $tenant = $this->tenantManager->getForCurrentUser();
        if ( ! $tenant ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
            exit();
        }

        $this->botManager->update( $botId, (int) $tenant['id'], [ 'is_active' => (bool) $newState ] );

        wp_safe_redirect( add_query_arg( 'toggled', '1', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
        exit();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function renderNotices(): void {
        $notices = [ // phpcs:ignore
            'created' => [ 'success', '¡Bot creado! Copiá el snippet para instalarlo en tu sitio.' ],
            'updated' => [ 'success', 'Cambios guardados correctamente.' ],
            'deleted' => [ 'success', 'Bot eliminado.' ],
            'toggled' => [ 'success', 'Estado del bot actualizado.' ],
            'error'   => [ 'error',   'Ocurrió un error. Intentá nuevamente.' ],
            'limit'   => [ 'error',   'Alcanzaste el límite de bots de tu plan. Actualizá para crear más.' ],
        ];

        foreach ( $notices as $key => [ $type, $msg ] ) {
            if ( ! empty( $_GET[ $key ] ) ) { // phpcs:ignore
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr( $type ),
                    esc_html( $msg )
                );
            }
        }
    }

    /**
     * Sanitiza y normaliza el input del formulario de bot.
     * Los quick_replies se convierten a array de objetos para settings JSON.
     *
     * @return array<string, mixed>
     */
    private function sanitizeFormInput( array $post ): array {
        $sf = 'sanitize_text_field';

        // Quick replies: array de pares label/value → filtrar vacíos
        $rawQr        = (array) ( $post['quick_replies'] ?? [] );
        $quickReplies = [];
        foreach ( $rawQr as $item ) {
            $label = $sf( (string) ( $item['label'] ?? '' ) );
            if ( $label ) {
                $value          = $sf( (string) ( $item['value'] ?? '' ) );
                $quickReplies[] = $value ? [ 'label' => $label, 'value' => $value ] : [ 'label' => $label ];
            }
        }

        // Settings inline del formulario
        $formSettings = (array) ( $post['settings'] ?? [] );
        $settings     = [
            'welcome_message' => $sf( (string) ( $formSettings['welcome_message'] ?? '¡Hola! ¿En qué puedo ayudarte?' ) ),
            'whatsapp_number' => $sf( (string) ( $formSettings['whatsapp_number'] ?? '' ) ),
            'webhook_url'     => esc_url_raw( (string) ( $formSettings['webhook_url'] ?? '' ) ),
            'quick_replies'   => $quickReplies,
        ];

        // Modelo LLM → deriva el provider
        $model    = $sf( (string) ( $post['llm_model'] ?? 'claude-haiku-4-5-20251001' ) );
        $provider = str_contains( $model, 'claude' ) ? 'anthropic' : 'openai';

        return [
            'bot_name'        => $sf( (string) ( $post['bot_name']       ?? '' ) ),
            'system_prompt'   => sanitize_textarea_field( (string) ( $post['system_prompt'] ?? '' ) ),
            'allowed_origins' => sanitize_textarea_field( (string) ( $post['allowed_origins'] ?? '' ) ),
            'llm_model'       => $model,
            'llm_provider'    => $provider,
            'settings'        => $settings,
        ];
    }
}
