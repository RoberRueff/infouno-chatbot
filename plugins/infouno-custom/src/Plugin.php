<?php

declare(strict_types=1);

namespace Infouno\SaaS;

use Infouno\SaaS\Admin\BotDashboard;
use Infouno\SaaS\Admin\BotWizard;
use Infouno\SaaS\Admin\LeadDashboard;
use Infouno\SaaS\Admin\OpportunityDashboard;
use Infouno\SaaS\API\ChannelWebhookController;
use Infouno\SaaS\API\LeadController;
use Infouno\SaaS\API\OpportunityController;
use Infouno\SaaS\API\RestRouter;
use Infouno\SaaS\Automation\AutomationEngine;
use Infouno\SaaS\Automation\NotificationDispatcher;
use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Channel\ChannelConsentService;
use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\Http\WpHttpClient;
use Infouno\SaaS\Channel\InboundDispatcher;
use Infouno\SaaS\Channel\TelegramAdapter;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\ChatService;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Core\Migrator;
use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Lead\LeadScorer;
use Infouno\SaaS\Lead\LeadService;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\Opportunity\OpportunityRepository;
use Infouno\SaaS\Opportunity\OpportunityService;
use Infouno\SaaS\Security\CredentialVault;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Clase principal del plugin. Singleton que inicializa todos los subsistemas.
 * Los hooks de WP se registran aquí; la lógica de negocio vive en las clases controladoras.
 */
final class Plugin {

    private static ?self $instance = null;

    private TenantManager          $tenantManager;
    private BotManager             $botManager;
    private QuotaService           $quotaService;
    private ConversationRepository $conversationRepo;
    private LLMRouter              $llmRouter;
    private LeadRepository         $leadRepository;
    private LeadScorer             $leadScorer;
    private LeadService            $leadService;
    private ChatService            $chatService;
    private OpportunityRepository  $opportunityRepo;
    private OpportunityService     $opportunityService;
    private AutomationEngine       $automationEngine;
    private RestRouter             $restRouter;
    private BotDashboard           $botDashboard;
    private LeadDashboard          $leadDashboard;
    private OpportunityDashboard   $opportunityDashboard;
    private BotWizard              $botWizard;
    private ChannelRegistry        $channelRegistry;
    private ChannelRepository      $channelRepository;
    private ChannelEventRepository $channelEventRepository;
    private InboundDispatcher      $inboundDispatcher;

    private function __construct() {}

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        // ── Infraestructura ──────────────────────────────────────────────────
        $this->tenantManager    = new TenantManager();
        $this->botManager       = new BotManager();
        $this->quotaService     = new QuotaService();
        $this->conversationRepo = new ConversationRepository();
        $this->llmRouter        = new LLMRouter();

        // ── Lead Engine ──────────────────────────────────────────────────────
        $this->leadRepository = new LeadRepository();
        $this->leadScorer     = new LeadScorer();
        $this->leadService    = new LeadService( $this->leadScorer, $this->leadRepository );

        // ── Opportunity Engine ───────────────────────────────────────────────
        $this->opportunityRepo    = new OpportunityRepository();
        $this->opportunityService = new OpportunityService( $this->opportunityRepo );

        // ── Sales Automation ─────────────────────────────────────────────────
        $dispatcher               = new NotificationDispatcher( $this->tenantManager, $this->opportunityRepo );
        $this->automationEngine   = new AutomationEngine(
            $this->opportunityRepo,
            $this->tenantManager,
            $this->botManager,
            $dispatcher
        );

        // ── Chat (recibe LeadService para scoring post-mensaje) ──────────────
        $this->chatService = new ChatService(
            $this->tenantManager,
            $this->botManager,
            $this->quotaService,
            $this->conversationRepo,
            $this->llmRouter,
            $this->leadService,
        );

        // ── Canales Sociales (Fase 1) ────────────────────────────────────────
        if ( ! defined( 'INFOUNO_ENCRYPTION_KEY' ) ) {
            // Sin clave de cifrado no se pueden manejar credenciales de canal.
            $vault = null;
        } else {
            $vault = new CredentialVault( INFOUNO_ENCRYPTION_KEY );
        }

        if ( null !== $vault ) {
            $this->channelRepository      = new ChannelRepository( $vault );
            $this->channelEventRepository = new ChannelEventRepository();
            $this->channelRegistry        = new ChannelRegistry();
            $this->channelRegistry->register( new TelegramAdapter( $vault, new WpHttpClient() ) );
            $this->channelRegistry->register( new WhatsAppAdapter( $vault, new WpHttpClient() ) );

            $pipeline = new ChatPipeline(
                $this->tenantManager,
                $this->botManager,
                $this->quotaService,
                $this->conversationRepo,
                $this->llmRouter,
                $this->leadService,
            );
            // El dispatcher invoca al loader como ($tenantId, $botId); BotManager::getById
            // es ($botId, $tenantId) y devuelve el bot con settings decodificado a array (o null).
            $botLoader = fn( int $tid, int $bid ): ?array => $this->botManager->getById( $bid, $tid );

            $this->inboundDispatcher = new InboundDispatcher(
                $this->channelRegistry,
                $this->channelRepository,
                new ChannelConsentService(),
                $pipeline,
                $botLoader,
            );
        }

        // ── API ──────────────────────────────────────────────────────────────
        // El RestRouter exige un ChannelWebhookController (Task 15). Si no hay clave de
        // cifrado, se construye uno con registry/repo de fallback: sin canales activos en
        // BD, resolveByRoutingKey devuelve null y el endpoint responde 404 sin descifrar nada.
        $webhookController = new ChannelWebhookController(
            $this->channelRegistry        ?? new ChannelRegistry(),
            $this->channelRepository      ?? new ChannelRepository( new CredentialVault( str_repeat( '0', 64 ) ) ),
            $this->channelEventRepository ?? new ChannelEventRepository(),
        );

        $this->restRouter = new RestRouter(
            $this->tenantManager,
            $this->botManager,
            $this->quotaService,
            $this->chatService,
            $this->conversationRepo,
            new LeadController( $this->tenantManager ),
            new OpportunityController( $this->opportunityService, $this->opportunityRepo, $this->tenantManager ),
            $webhookController,
        );

        // ── Admin ────────────────────────────────────────────────────────────
        $this->botDashboard         = new BotDashboard( $this->botManager, $this->tenantManager );
        $this->leadDashboard        = new LeadDashboard( $this->tenantManager );
        $this->opportunityDashboard = new OpportunityDashboard( $this->tenantManager, $this->opportunityRepo );
        $this->botWizard            = new BotWizard( $this->botManager, $this->tenantManager );

        // Migración directa — no via hook porque plugins_loaded ya está disparado en este punto
        $this->maybeMigrate();

        // ── Hooks de WordPress ───────────────────────────────────────────────
        add_action( 'rest_api_init', [ $this->restRouter, 'register' ] );
        add_action( 'init',          [ $this, 'registerRoles' ] );
        add_action( 'admin_menu',    [ $this, 'registerAdminMenu' ] );

        // Bot Dashboard
        $this->botDashboard->init();

        // Lead Engine
        $this->leadDashboard->init();
        add_action( 'infouno_lead_captured', [ $this, 'onLeadCaptured' ], 10, 4 );

        // Opportunity Dashboard
        $this->opportunityDashboard->init();

        // Opportunity Engine — prioridad 20 (posterior al email de notificación en prio 10)
        add_action( 'infouno_lead_captured', [ $this->opportunityService, 'onLeadCaptured' ], 20, 4 );

        // Sales Automation — escucha los 3 hooks del pipeline de oportunidades
        add_action( 'infouno_opportunity_created',       [ $this->automationEngine, 'onOpportunityCreated' ], 10, 4 );
        add_action( 'infouno_opportunity_stage_changed', [ $this->automationEngine, 'onStageChanged' ],       10, 4 );
        add_action( 'infouno_deal_won',                  [ $this->automationEngine, 'onDealWon' ],            10, 3 );

        // LLM fallback — log estructurado + transient para futura alerta al tenant
        add_action( 'infouno_model_fallback', [ $this, 'onModelFallback' ], 10, 4 );

        // Knowledge Builder
        $this->botWizard->init();
        add_action( 'admin_menu', [ $this->botWizard, 'registerMenu' ] );

        // Cron de mantenimiento
        add_action( 'infouno_purge_expired_messages', [ $this, 'purgeExpiredMessages' ] );
        add_action( 'infouno_reset_monthly_quotas',   [ $this, 'resetMonthlyQuotas' ] );

        // Worker de canales (Action Scheduler) — solo si la infra de canal está activa.
        if ( isset( $this->inboundDispatcher ) ) {
            // Action Scheduler expande el array de args como parámetros posicionales
            // (do_action_ref_array), por eso el callback recibe channelId y payload sueltos.
            add_action( 'infouno_process_inbound', function ( $channelId = 0, $payload = [] ): void {
                $channelId = (int) $channelId;
                $payload   = is_array( $payload ) ? $payload : [];
                if ( $channelId > 0 ) {
                    $this->inboundDispatcher->handle( $channelId, $payload );
                }
            }, 10, 2 );
        }

        // Purga de eventos de idempotencia (mantenimiento).
        add_action( 'infouno_purge_channel_events', function (): void {
            ( new ChannelEventRepository() )->purgeOlderThan( 7 );
        } );
    }

    /** Ejecuta migraciones si la versión almacenada en BD es antigua. */
    public function maybeMigrate(): void {
        $stored = get_option( 'infouno_db_version', '0' );
        if ( version_compare( $stored, INFOUNO_DB_VERSION, '<' ) ) {
            ( new Migrator() )->run();
        }
    }

    /**
     * Menú raíz de InfoUno en el panel WP admin.
     * LeadDashboard registra el submenú "Leads" desde su propio init().
     */
    public function registerAdminMenu(): void {
        add_menu_page(
            'InfoUno Dashboard',
            'InfoUno',
            'read',
            'infouno-dashboard',
            [ $this, 'renderAdminDashboard' ],
            'dashicons-format-chat',
            30
        );
    }

    /**
     * Página principal del panel admin — muestra el estado de cuota del tenant.
     * El dashboard completo (Astra + gráficos) es Fase 2.
     */
    public function renderAdminDashboard(): void {
        $tenant = $this->tenantManager->getForCurrentUser();

        if ( ! $tenant ) {
            echo '<div class="wrap"><h1>InfoUno</h1>';
            echo '<p>' . esc_html__( 'No tenés un tenant activo. Registrate en la plataforma para comenzar.', 'infouno-custom' ) . '</p>';
            echo '</div>';
            return;
        }

        $used  = (int) $tenant['quota_used'];
        $limit = (int) $tenant['quota_limit'];
        $pct   = $limit > 0 ? round( $used / $limit * 100, 1 ) : 0;
        $color = $pct >= 90 ? '#d63638' : ( $pct >= 70 ? '#dba617' : '#00a32a' );

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'InfoUno — Panel de Control', 'infouno-custom' ) . '</h1>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__( 'Plan activo', 'infouno-custom' ) . '</th>';
        echo '<td><strong>' . esc_html( strtoupper( $tenant['plan'] ) ) . '</strong></td></tr>';
        echo '<tr><th>' . esc_html__( 'Tokens usados', 'infouno-custom' ) . '</th><td>';
        echo '<strong style="color:' . esc_attr( $color ) . '">';
        echo esc_html( number_format( $used ) ) . ' / ' . esc_html( number_format( $limit ) );
        echo ' (' . esc_html( (string) $pct ) . '%)</strong></td></tr>';
        echo '<tr><th>' . esc_html__( 'Reset de cuota', 'infouno-custom' ) . '</th>';
        echo '<td>' . esc_html( $tenant['quota_reset_at'] ?? '—' ) . '</td></tr>';
        echo '</tbody></table>';
        echo '<p>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=infouno-bots' ) ) . '" class="button button-primary">';
        echo esc_html__( 'Mis Bots', 'infouno-custom' );
        echo '</a> ';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=infouno-leads' ) ) . '" class="button">';
        echo esc_html__( 'Leads', 'infouno-custom' );
        echo '</a> ';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=infouno-opportunities' ) ) . '" class="button">';
        echo esc_html__( 'Pipeline', 'infouno-custom' );
        echo '</a>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Email al tenant cuando se captura un lead calificado (score >= 60).
     * Incluye datos de contacto disponibles para acción inmediata.
     * Anti-spam: máximo un email por lead_id cada 24 horas.
     */
    public function onLeadCaptured( int $leadId, int $tenantId, int $botId, array $result ): void {
        $transientKey = 'infouno_lead_notif_' . $leadId;
        if ( get_transient( $transientKey ) ) {
            return;
        }
        set_transient( $transientKey, 1, DAY_IN_SECONDS );

        $tenant = $this->tenantManager->getById( $tenantId );
        if ( ! $tenant ) {
            return;
        }

        $user = get_userdata( (int) $tenant['user_id'] );
        if ( ! $user || ! $user->user_email ) {
            return;
        }

        $score    = (int) $result['score'];
        $interest = sanitize_text_field( (string) ( $result['extracted']['interest'] ?? 'consulta' ) );
        $name     = sanitize_text_field( (string) ( $result['extracted']['name']  ?? '' ) );
        $email    = sanitize_email( (string) ( $result['extracted']['email']  ?? '' ) );
        $phone    = sanitize_text_field( (string) ( $result['extracted']['phone']  ?? '' ) );
        $leadsUrl = admin_url( 'admin.php?page=infouno-leads' );

        // Recupera el nombre del bot para contexto
        global $wpdb;
        $botName = (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT bot_name FROM `{$wpdb->prefix}infouno_bots` WHERE id = %d AND tenant_id = %d LIMIT 1",
                $botId,
                $tenantId
            )
        );

        // Bloque de datos de contacto — solo muestra los que están disponibles
        $contactLines = [];
        if ( $name ) {
            $contactLines[] = "  Nombre:    {$name}";
        }
        if ( $email ) {
            $contactLines[] = "  Email:     {$email}";
        }
        if ( $phone ) {
            $contactLines[] = "  Teléfono:  {$phone}";
        }

        $contactBlock = empty( $contactLines )
            ? "  (El usuario no compartió datos de contacto aún)\n"
            : implode( "\n", $contactLines ) . "\n";

        $priorityLabel = match ( true ) {
            $score >= 80 => 'ALTA (acción inmediata recomendada)',
            $score >= 60 => 'MEDIA',
            default      => 'Baja',
        };

        $interestLabel = match ( $interest ) {
            'compra'      => 'Intención de compra',
            'informacion' => 'Consulta informativa',
            default       => 'Consulta general',
        };

        $botLine = $botName ? "Bot:           {$botName}\n" : '';

        $subject = sprintf( '[InfoUno] 🔔 Nuevo lead — Score %d/100 · %s', $score, $interestLabel );

        $body = sprintf(
            "Hola %s,\n\n" .
            "Tu chatbot capturó un nuevo lead calificado. Contactalo pronto para maximizar la conversión.\n\n" .
            "──────────────────────────────\n" .
            "DATOS DEL LEAD\n" .
            "──────────────────────────────\n" .
            "%s" .
            "  Score:     %d / 100\n" .
            "  Interés:   %s\n" .
            "  Prioridad: %s\n" .
            "  %s" .
            "──────────────────────────────\n\n" .
            "Gestioná el seguimiento desde tu panel:\n%s\n\n" .
            "Tip: Los leads contactados en los primeros 5 minutos convierten hasta 9x más.\n\n" .
            "— InfoUno\nhttps://infouno.com",
            $user->display_name,
            $contactBlock,
            $score,
            $interestLabel,
            $priorityLabel,
            $botLine,
            $leadsUrl
        );

        wp_mail( $user->user_email, $subject, $body );
    }

    /**
     * Listener de infouno_model_fallback.
     *
     * Registra en error_log para observabilidad operacional y guarda un transient
     * por proveedor para poder consultar el estado del fallback desde el admin.
     * El transient expira en 1 hora — frecuencia máxima de alerta implícita.
     *
     * @param string $from     Proveedor que falló (ej: 'anthropic').
     * @param string $to       Proveedor que toma el relay (ej: 'openai').
     * @param string $model    Modelo en uso al momento del fallo.
     * @param string $reason   Mensaje del error que disparó el fallback.
     */
    public function onModelFallback( string $from, string $to, string $model, string $reason ): void {
        error_log( sprintf(
            '[INFOUNO] LLM fallback: %s → %s (model: %s). Reason: %s',
            $from,
            $to,
            $model,
            $reason ?: 'unknown'
        ) );

        set_transient(
            'infouno_llm_fallback_' . $from,
            [
                'from'    => $from,
                'to'      => $to,
                'model'   => $model,
                'reason'  => $reason,
                'time'    => gmdate( 'Y-m-d H:i:s' ),
            ],
            HOUR_IN_SECONDS
        );
    }

    /**
     * Mantenimiento diario. El orden importa: expirados primero, luego soft-deleted sin tokens.
     * Filas con tokens_used > 0 NUNCA se borran físicamente (guardrail legal-copliance.md).
     */
    public function purgeExpiredMessages(): void {
        $this->conversationRepo->purgeExpiredMessages();
        $this->conversationRepo->purgeDeletedMessages( 1 );
    }

    /** Resetea quota_used = 0 en tenants cuyo quota_reset_at ya venció. */
    public function resetMonthlyQuotas(): void {
        $this->tenantManager->resetExpiredQuotas();
    }

    /**
     * Roles custom del SaaS.
     * tenant_admin: administra bots, ve leads, configuración.
     * tenant_agent: accede a conversaciones y leads en modo lectura.
     */
    public function registerRoles(): void {
        if ( ! get_role( 'tenant_admin' ) ) {
            add_role( 'tenant_admin', __( 'Tenant Admin', 'infouno-custom' ), [ 'read' => true ] );
        }

        if ( ! get_role( 'tenant_agent' ) ) {
            add_role( 'tenant_agent', __( 'Tenant Agent', 'infouno-custom' ), [ 'read' => true ] );
        }
    }

    public function tenantManager(): TenantManager { return $this->tenantManager; }
    public function botManager(): BotManager       { return $this->botManager; }
    public function quotaService(): QuotaService   { return $this->quotaService; }
}
