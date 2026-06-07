<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Chat\BufferedSink;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\PipelineContext;

/**
 * Worker de Action Scheduler: procesa un mensaje entrante de canal end-to-end.
 * Normaliza → resuelve bot/tenant → asegura consentimiento → ejecuta el pipeline
 * con BufferedSink → responde por el adapter. Mapea errores a mensajes amables.
 */
final class InboundDispatcher {

    /**
     * Mensajes de fallback por código HTTP del pipeline. Solo errores de NEGOCIO
     * terminales (sin reintento): cuota, permiso, input inválido, rate limit.
     * Los códigos fuera de este mapa (ej. 503 por agotamiento de proveedores LLM)
     * se consideran transitorios y se re-lanzan para que Action Scheduler reintente.
     */
    private const FALLBACK = [
        402 => 'Alcanzaste el límite de esta conversación. Escribinos más tarde, ¡gracias!',
        403 => 'No pudimos procesar tu mensaje en este momento.',
        422 => 'No puedo responder a eso. ¿Te ayudo con algo sobre nuestros productos o servicios?',
        429 => 'Estás escribiendo muy rápido 🙂 Esperá unos segundos e intentá de nuevo.',
    ];

    private const UNSUPPORTED = 'Por ahora solo puedo leer mensajes de texto. ¿Me contás tu consulta por escrito? 🙂';

    private const WELCOME = "👋 ¡Hola! Te responde un asistente automático. "
        . "Al continuar, aceptás nuestra política de privacidad y el tratamiento de tus datos "
        . "según la Ley 25.326. Podés pedir la baja en cualquier momento.";

    /** @var callable fn(int $tenantId, int $botId): ?array<string,mixed> */
    private $botLoader;

    public function __construct(
        private readonly ChannelRegistry       $registry,
        private readonly ChannelRepository     $channelRepo,
        private readonly ChannelConsentService $consent,
        private readonly ChatPipeline          $pipeline,
        callable                               $botLoader,
        private readonly ?ChannelDeliveryRepository $deliveryRepo  = null,
        private readonly ?WindowChecker             $windowChecker = null,
        private readonly ?ChannelTemplateRepository $templateRepo  = null,
        private readonly ?TemplateVariableResolver  $varResolver   = null,
    ) {
        $this->botLoader = $botLoader;
    }

    /**
     * Handler del job. Firma compatible con Action Scheduler (args posicionales).
     * @param array<string,mixed> $payload Payload crudo del webhook.
     */
    public function handle( int $channelId, array $payload ): void {
        $channel = $this->channelRepo->resolveByRoutingKeyId( $channelId );
        if ( null === $channel ) {
            return; // canal eliminado/desactivado entre el ack y el worker
        }

        $adapter = $this->registry->get( (string) $channel['channel_type'] );

        // Enrutar eventos de estado (status receipts) al repositorio de entregas.
        // Va ANTES de parseInbound() porque parseInbound() devuelve null para los
        // payloads de status (saldríamos temprano y perderíamos el recibo).
        if ( null !== $this->deliveryRepo && method_exists( $adapter, 'parseStatuses' ) ) {
            $statusEvents = $adapter->parseStatuses( $payload );
            if ( ! empty( $statusEvents ) ) {
                $statusTenantId = (int) $channel['tenant_id'];
                foreach ( $statusEvents as $event ) {
                    $this->deliveryRepo->updateStatus(
                        tenantId:      $statusTenantId,
                        externalMsgId: $event->wamid,
                        status:        $event->status,
                        errorCode:     $event->errorCode,
                    );
                    if ( 'failed' === $event->status ) {
                        error_log( sprintf(
                            '[INFOUNO-CHANNEL] WhatsApp delivery failed: wamid=%s errorCode=%s',
                            $event->wamid,
                            $event->errorCode ?? 'n/a'
                        ) );
                    }
                }
                return; // payload de status procesado; no hay mensaje que responder
            }
        }

        $inbound = $adapter->parseInbound( $payload );
        if ( null === $inbound ) {
            return; // no era un mensaje de texto procesable
        }

        // No-texto (audio, foto, sticker...): respondemos pidiendo texto, sin pipeline ni consentimiento.
        if ( 'unsupported' === $inbound->kind ) {
            $this->trySend( $adapter, $channel, $inbound->externalUser, self::UNSUPPORTED );
            return;
        }

        $tenantId = (int) $channel['tenant_id'];
        $botId    = (int) $channel['bot_id'];
        $bot      = ( $this->botLoader )( $tenantId, $botId );
        if ( null === $bot ) {
            return;
        }

        // Consentimiento por primer mensaje: si es primer contacto, enviar bienvenida legal.
        $isFirstContact = $this->consent->ensure( $tenantId, $botId, $inbound->channelType, $inbound->conversationKey() );
        if ( $isFirstContact ) {
            $this->trySend( $adapter, $channel, $inbound->externalUser, self::WELCOME );
        }

        $sink = new BufferedSink();
        try {
            $this->pipeline->run(
                $bot,
                $inbound->conversationKey(),
                $inbound->text,
                $sink,
                PipelineContext::forChannel( $inbound->channelType, $inbound->externalUser )
            );
        } catch ( \RuntimeException $e ) {
            // Solo los códigos del mapa FALLBACK son errores de negocio terminales
            // (cuota, permiso, input, rate limit): fallback amable, sin reintento.
            if ( isset( self::FALLBACK[ $e->getCode() ] ) ) {
                $this->trySend( $adapter, $channel, $inbound->externalUser, self::FALLBACK[ $e->getCode() ] );
                return;
            }
            // Cualquier otro error (ej. 503 por agotamiento de proveedores LLM, o
            // transitorios de red) se re-lanza para que Action Scheduler reintente
            // con backoff. No mandamos respuesta de negocio.
            throw $e;
        }

        $reply = $sink->getBuffer();
        if ( '' === trim( $reply ) ) {
            return;
        }

        // Decidir free-form vs template según la ventana de 24h.
        // Sin windowChecker (tests/legacy) → se asume abierta (comportamiento original).
        $windowOpen = null === $this->windowChecker
            || $this->windowChecker->isOpen( $botId, $inbound->conversationKey() );

        if ( $windowOpen ) {
            $adapter->send( $channel, $inbound->externalUser, $reply );
        } else {
            $template = null !== $this->templateRepo
                ? ( $this->templateRepo->findApproved( $tenantId, (int) $channel['id'] )[0] ?? null )
                : null;

            if ( null === $template ) {
                error_log( sprintf(
                    '[INFOUNO-CHANNEL] Ventana cerrada y sin template aprobado: tenant=%d channel=%d user=%s — respuesta abandonada.',
                    $tenantId,
                    (int) $channel['id'],
                    $inbound->externalUser
                ) );
                return;
            }

            $schema     = json_decode( (string) ( $template['variables_schema'] ?? '[]' ), true );
            $schema     = is_array( $schema ) ? $schema : [];
            $context    = [ 'customer_name' => $inbound->externalUser ]; // contexto mínimo
            $components = null !== $this->varResolver
                ? $this->varResolver->buildComponentsArray( $schema, $context )
                : [];

            if ( method_exists( $adapter, 'sendTemplate' ) ) {
                $adapter->sendTemplate(
                    $channel,
                    $inbound->externalUser,
                    (string) ( $template['name'] ?? '' ),
                    (string) ( $template['language'] ?? 'es_AR' ),
                    $components
                );
            } else {
                error_log( '[INFOUNO-CHANNEL] Adapter sin sendTemplate(); usando free-form como fallback.' );
                $adapter->send( $channel, $inbound->externalUser, $reply );
            }
        }

        // Registrar la entrega saliente con el wamid capturado (si el adapter lo expone).
        if ( null !== $this->deliveryRepo && method_exists( $adapter, 'lastWamid' ) ) {
            $wamid = $adapter->lastWamid();
            if ( null !== $wamid ) {
                $this->deliveryRepo->record(
                    tenantId:      $tenantId,
                    channelId:     (int) $channel['id'],
                    messageId:     null,
                    externalMsgId: $wamid,
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $channel
     */
    private function trySend( ChannelAdapterInterface $adapter, array $channel, string $user, string $text ): void {
        try {
            $adapter->send( $channel, $user, $text );
        } catch ( \Throwable $e ) {
            error_log( '[INFOUNO-CHANNEL] Falló envío de fallback/bienvenida: ' . $e->getMessage() );
        }
    }
}
