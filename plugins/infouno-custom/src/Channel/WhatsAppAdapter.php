<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Security\CredentialVault;

/**
 * Canal WhatsApp (Cloud API de Meta). Verifica la firma X-Hub-Signature-256,
 * responde el handshake GET, normaliza mensajes de texto (y marca no-texto como
 * 'unsupported'), y responde vía la Graph API.
 */
final class WhatsAppAdapter implements ChannelAdapterInterface, WebhookChallengeInterface {

    private const MAX_CHARS  = 4096;
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';

    /** Último wamid devuelto por la Graph API. Null si el envío falló o no hubo respuesta. */
    private ?string $lastWamid = null;

    public function __construct(
        private readonly CredentialVault   $vault,
        private readonly ChannelHttpClient $http,
    ) {}

    public function lastWamid(): ?string {
        return $this->lastWamid;
    }

    public function type(): string {
        return 'whatsapp';
    }

    public function verifyWebhook( \WP_REST_Request $request, array $channel ): bool {
        $secret = (string) ( $channel['credentials_decrypted']['app_secret'] ?? '' );
        $header = (string) ( $request->get_header( 'X-Hub-Signature-256' ) ?? '' );
        if ( '' === $secret || '' === $header ) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret );
        return hash_equals( $expected, $header );
    }

    public function verifyChallenge( \WP_REST_Request $request, array $channel ): ?string {
        // PHP convierte los puntos del query string en guiones bajos: hub.x → hub_x.
        $mode      = (string) ( $request->get_param( 'hub_mode' ) ?? '' );
        $token     = (string) ( $request->get_param( 'hub_verify_token' ) ?? '' );
        $challenge = (string) ( $request->get_param( 'hub_challenge' ) ?? '' );
        $expected  = (string) ( $channel['credentials_decrypted']['verify_token'] ?? '' );

        if ( 'subscribe' === $mode && '' !== $expected && hash_equals( $expected, $token ) ) {
            return $challenge;
        }
        return null;
    }

    public function parseInbound( array $payload ): ?InboundMessage {
        $value   = $payload['entry'][0]['changes'][0]['value'] ?? null;
        $message = is_array( $value ) ? ( $value['messages'][0] ?? null ) : null;
        if ( ! is_array( $message ) ) {
            return null; // statuses (receipts) u otros eventos sin mensaje
        }

        $from = (string) ( $message['from'] ?? '' );
        $id   = (string) ( $message['id'] ?? '' );
        if ( '' === $from || '' === $id ) {
            return null;
        }

        if ( 'text' === ( $message['type'] ?? '' ) ) {
            $text = (string) ( $message['text']['body'] ?? '' );
            if ( '' === trim( $text ) ) {
                return null;
            }
            return new InboundMessage( 'whatsapp', $from, $text, $id, 'text' );
        }

        // audio/image/sticker/location/... → no soportado (el dispatcher pide texto).
        return new InboundMessage( 'whatsapp', $from, '', $id, 'unsupported' );
    }

    public function send( array $channel, string $externalUser, string $text ): void {
        $creds = $this->vault->decryptArray( (string) ( $channel['credentials'] ?? '' ) );
        $token = (string) ( $creds['access_token'] ?? '' );
        $pnid  = (string) ( $creds['phone_number_id'] ?? '' );
        if ( '' === $token || '' === $pnid ) {
            throw new \RuntimeException( 'Canal WhatsApp sin access_token/phone_number_id.' );
        }

        $url = self::GRAPH_BASE . '/' . $pnid . '/messages';

        $this->lastWamid = null;

        foreach ( $this->splitMessage( $text ) as $chunk ) {
            $res = $this->http->postJson(
                $url,
                [ 'Authorization' => 'Bearer ' . $token ],
                [
                    'messaging_product' => 'whatsapp',
                    'to'                => $externalUser,
                    'type'              => 'text',
                    'text'              => [ 'body' => $chunk ],
                ]
            );
            $code = (int) ( $res['code'] ?? 0 );
            if ( 0 === $code || $code >= 400 ) {
                $decoded   = json_decode( (string) ( $res['body'] ?? '' ), true );
                $errorBody = is_array( $decoded ) ? $decoded : [];
                $ex        = WhatsAppGraphException::fromGraphError( $code, $errorBody );

                error_log( sprintf(
                    '[INFOUNO-CHANNEL] WhatsApp send error: HTTP %d | graphCode=%d | retryable=%s | %s',
                    $code,
                    $ex->graphCode(),
                    $ex->isRetryable() ? 'yes' : 'no',
                    $ex->getMessage()
                ) );

                throw $ex;
            } else {
                $decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
                if ( is_array( $decoded ) ) {
                    $wamid = $decoded['messages'][0]['id'] ?? null;
                    if ( is_string( $wamid ) && '' !== $wamid ) {
                        $this->lastWamid = $wamid;
                    }
                }
            }
        }
    }

    /**
     * Envía un mensaje usando un template aprobado por Meta (ventana cerrada).
     * El nombre, idioma y componentes deben estar ya resueltos por el caller
     * (TemplateVariableResolver::buildComponentsArray()).
     * Captura el wamid igual que send(). Lanza WhatsAppGraphException en error.
     *
     * @param  array<string,mixed>            $channel      Fila de wp_infouno_channels.
     * @param  string                         $externalUser Número de teléfono destino.
     * @param  string                         $templateName Nombre del template en Meta.
     * @param  string                         $language     Código de idioma, ej. 'es_AR'.
     * @param  array<int,array<string,mixed>> $components   Componentes resueltos.
     */
    public function sendTemplate(
        array  $channel,
        string $externalUser,
        string $templateName,
        string $language,
        array  $components,
    ): void {
        $creds = $this->vault->decryptArray( (string) ( $channel['credentials'] ?? '' ) );
        $token = (string) ( $creds['access_token'] ?? '' );
        $pnid  = (string) ( $creds['phone_number_id'] ?? '' );
        if ( '' === $token || '' === $pnid ) {
            throw new \RuntimeException( 'Canal WhatsApp sin access_token/phone_number_id.' );
        }

        $url = self::GRAPH_BASE . '/' . $pnid . '/messages';

        $this->lastWamid = null;

        $res  = $this->http->postJson(
            $url,
            [ 'Authorization' => 'Bearer ' . $token ],
            [
                'messaging_product' => 'whatsapp',
                'to'                => $externalUser,
                'type'              => 'template',
                'template'          => [
                    'name'       => $templateName,
                    'language'   => [ 'code' => $language ],
                    'components' => $components,
                ],
            ]
        );

        $code    = (int) ( $res['code'] ?? 0 );
        $decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];

        if ( $code >= 200 && $code < 300 ) {
            $wamid = $decoded['messages'][0]['id'] ?? null;
            if ( is_string( $wamid ) && '' !== $wamid ) {
                $this->lastWamid = $wamid;
            }
        } else {
            $ex = WhatsAppGraphException::fromGraphError( $code, $decoded );
            error_log( sprintf(
                '[INFOUNO-CHANNEL] WhatsApp sendTemplate error: HTTP %d | graphCode=%d | retryable=%s | template=%s | %s',
                $code,
                $ex->graphCode(),
                $ex->isRetryable() ? 'yes' : 'no',
                $templateName,
                $ex->getMessage()
            ) );
            throw $ex;
        }
    }

    /**
     * Parsea los eventos `statuses` del payload de Meta y devuelve un array de
     * WhatsAppStatusEvent. Devuelve [] si el payload no contiene statuses o si
     * los estados no son reconocidos. parseInbound() sigue devolviendo null para
     * estos payloads (sin cambios en el contrato de ChannelAdapterInterface).
     *
     * @param  array<string,mixed> $payload
     * @return WhatsAppStatusEvent[]
     */
    public function parseStatuses( array $payload ): array {
        $value    = $payload['entry'][0]['changes'][0]['value'] ?? null;
        $statuses = is_array( $value ) ? ( $value['statuses'] ?? null ) : null;
        if ( ! is_array( $statuses ) ) {
            return [];
        }

        $events = [];
        foreach ( $statuses as $raw ) {
            if ( ! is_array( $raw ) ) {
                continue;
            }
            $event = WhatsAppStatusEvent::fromStatusArray( $raw );
            if ( WhatsAppStatusEvent::isKnownStatus( $event->status ) ) {
                $events[] = $event;
            }
        }

        return $events;
    }

    public function splitMessage( string $text ): array {
        if ( '' === $text ) {
            return [ '' ];
        }
        $chunks = str_split( $text, self::MAX_CHARS );
        return false === $chunks ? [ $text ] : $chunks;
    }
}
