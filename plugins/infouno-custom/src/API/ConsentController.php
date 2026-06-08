<?php

declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Persistence\ConsentRepository;

/**
 * Registro server-side de consentimiento — Ley 25.326 Argentina.
 *
 * Guarda evidencia legal del momento en que el usuario aceptó el aviso,
 * sin almacenar datos personales identificables directamente:
 *   - session_hash: SHA-256 del session_id (no reversible)
 *   - ip_hash:      SHA-256 de la IP (no reversible)
 *   - user_agent_hash: SHA-256 del User-Agent
 *
 * Esto cumple el principio de minimización de datos (Art. 4, Ley 25.326)
 * y permite demostrar que el consentimiento existió si es cuestionado.
 */
final class ConsentController {

    public function __construct(
        private readonly BotManager             $botManager,
        private readonly ConversationRepository $conversationRepo,
        private readonly ConsentRepository      $consentRepo,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/consent', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'record' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'bot_token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( $v ) === 64,
                ],
                'session_id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 8,
                ],
            ],
        ] );

        register_rest_route( $namespace, '/consent/revoke', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'revoke' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'bot_token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( $v ) === 64,
                ],
                'session_id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 8,
                ],
            ],
        ] );

        register_rest_route( $namespace, '/consent/lead', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'recordLead' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'bot_token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( $v ) === 64,
                ],
                'session_id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn( $v ) => strlen( trim( $v ) ) >= 8,
                ],
                'scopes' => [
                    'type'              => 'object',
                    'required'          => true,
                    'properties'        => [
                        'name'  => [ 'type' => 'boolean' ],
                        'phone' => [ 'type' => 'boolean' ],
                        'email' => [ 'type' => 'boolean' ],
                    ],
                    'validate_callback' => static function ( $v ): bool {
                        $v = (array) $v;
                        return ! empty( $v['name'] ) || ! empty( $v['phone'] ) || ! empty( $v['email'] );
                    },
                ],
            ],
        ] );
    }

    /**
     * Registra el consentimiento general de chat en wp_infouno_consents (scope='chat').
     * Idempotente: si ya existe un registro de chat para esta sesión + bot, retorna 200 sin duplicar.
     */
    public function record( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $origin    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

        $bot = $this->botManager->getByPublicToken( $botToken );
        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado.', [ 'status' => 404 ] );
        }

        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            return new \WP_Error( 'origin_not_allowed', 'Origen no autorizado.', [ 'status' => 403 ] );
        }

        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $hashes   = $this->buildHashes( $sessionId );
        $version  = $this->consentVersion();

        if ( $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'chat' ) ) {
            return new \WP_REST_Response( [ 'recorded' => false, 'reason' => 'already_consented' ], 200 );
        }

        $this->consentRepo->recordConsentRow(
            $tenantId,
            $botId,
            $hashes['session'],
            'chat',
            $version,
            $hashes['ip'],
            $hashes['ua'],
        );

        return new \WP_REST_Response( [ 'recorded' => true ], 201 );
    }

    /**
     * POST /infouno/v1/consent/lead
     *
     * Registra el consentimiento granular para captura de datos PII (nombre, teléfono, email)
     * en wp_infouno_lead_consents. También registra scope='lead_capture' en wp_infouno_consents
     * como evidencia legal independiente (Ley 25.326, Art. 6).
     *
     * Idempotente: si ya existe un registro para esta sesión + bot, retorna 200 sin duplicar.
     */
    public function recordLead( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $scopes    = (array) $request->get_param( 'scopes' );
        $origin    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

        $bot = $this->botManager->getByPublicToken( $botToken );
        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado.', [ 'status' => 404 ] );
        }

        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            return new \WP_Error( 'origin_not_allowed', 'Origen no autorizado.', [ 'status' => 403 ] );
        }

        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $version  = $this->consentVersion();
        $hashes   = $this->buildHashes( $sessionId );

        if ( $this->consentRepo->leadConsentExists( $botId, $hashes['session'] ) ) {
            return new \WP_REST_Response( [ 'recorded' => false, 'reason' => 'already_consented' ], 200 );
        }

        $this->consentRepo->recordLeadConsentRow(
            $tenantId,
            $botId,
            $hashes['session'],
            ! empty( $scopes['name'] ),
            ! empty( $scopes['phone'] ),
            ! empty( $scopes['email'] ),
            $version,
            $hashes['ip'],
            $hashes['ua'],
        );

        // Evidencia legal independiente en wp_infouno_consents con scope='lead_capture'.
        if ( ! $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'lead_capture' ) ) {
            $this->consentRepo->recordConsentRow(
                $tenantId,
                $botId,
                $hashes['session'],
                'lead_capture',
                $version,
                $hashes['ip'],
                $hashes['ua'],
            );
        }

        return new \WP_REST_Response( [ 'recorded' => true ], 201 );
    }

    /**
     * POST /infouno/v1/consent/revoke
     *
     * Derecho de supresión completo — Art. 16, Ley 25.326 Argentina.
     *
     * Cubre el gap que deja DELETE /session: ese endpoint anonimiza mensajes
     * pero deja PII en wp_infouno_leads intacta. Este endpoint completa la
     * supresión en todas las tablas que contienen datos del usuario:
     *
     *   1. Mensajes + conversaciones → deleteSession() (anonimiza/soft-delete)
     *   2. Leads PII → name/phone/email → NULL en wp_infouno_leads
     *   3. Consent flags → can_capture_* = 0 en wp_infouno_lead_consents
     *   4. Audit trail → INSERT scope='consent_revoked' en wp_infouno_consents
     *
     * El registro de consentimiento original (accepted_at, ip_hash, etc.) se preserva
     * como evidencia legal — solo se añade el registro de revocación.
     */
    public function revoke( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $origin    = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

        $bot = $this->botManager->getByPublicToken( $botToken );
        if ( ! $bot ) {
            return new \WP_Error( 'bot_not_found', 'Bot no encontrado.', [ 'status' => 404 ] );
        }

        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            return new \WP_Error( 'origin_not_allowed', 'Origen no autorizado.', [ 'status' => 403 ] );
        }

        $botId    = (int) $bot['id'];
        $tenantId = (int) $bot['tenant_id'];
        $hashes   = $this->buildHashes( $sessionId );
        $version  = $this->consentVersion();

        // 1. Anonimizar mensajes y conversaciones.
        $messagesProcessed = $this->conversationRepo->deleteSession( $sessionId, $tenantId );

        // 2. Anonimizar PII en leads — supresión de datos, núcleo del Art. 16.
        $this->consentRepo->anonymizeLeadPii( $tenantId, $hashes['session'] );

        // 3. Desactivar flags de captura futura.
        $this->consentRepo->revokeCaptureFlags( $botId, $hashes['session'] );

        // 4. Audit trail inmutable de la revocación (el registro original se preserva).
        if ( ! $this->consentRepo->consentExistsByBot( $botId, $hashes['session'], 'consent_revoked' ) ) {
            $this->consentRepo->recordConsentRow(
                $tenantId,
                $botId,
                $hashes['session'],
                'consent_revoked',
                $version,
                $hashes['ip'],
                $hashes['ua'],
            );
        }

        error_log( sprintf(
            '[INFOUNO] Consent revoked. Tenant: %d, Bot: %d, Session hash: %s',
            $tenantId,
            $botId,
            substr( $hashes['session'], 0, 12 )
        ) );

        return new \WP_REST_Response(
            [
                'revoked'            => true,
                'messages_processed' => $messagesProcessed,
                'message'            => 'Tus datos personales han sido eliminados conforme al Art. 16 de la Ley 25.326.',
            ],
            200
        );
    }

    /**
     * Versión del texto de consentimiento vigente — evidencia legal de qué aceptó el usuario.
     * Cae a '1.0' si la constante no está definida (entornos sin configuración explícita).
     */
    private function consentVersion(): string {
        return defined( 'INFOUNO_CONSENT_VERSION' ) ? INFOUNO_CONSENT_VERSION : '1.0';
    }

    /**
     * Calcula los tres hashes de evidencia legal sin almacenar datos personales directamente.
     *
     * @return array{session: string, ip: string, ua: string}
     */
    private function buildHashes( string $sessionId ): array {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $rawIp = $_SERVER['HTTP_CF_CONNECTING_IP']
              ?? $_SERVER['HTTP_X_REAL_IP']
              ?? $_SERVER['REMOTE_ADDR']
              ?? '';

        return [
            'session' => hash( 'sha256', $sessionId ),
            'ip'      => hash( 'sha256', trim( (string) $rawIp ) ),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            'ua'      => hash( 'sha256', (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
        ];
    }
}
