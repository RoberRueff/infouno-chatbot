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

    /** Mensajes de fallback por código HTTP del pipeline (sin reintento). */
    private const FALLBACK = [
        402 => 'Alcanzaste el límite de esta conversación. Escribinos más tarde, ¡gracias!',
        403 => 'No pudimos procesar tu mensaje en este momento.',
        422 => 'No puedo responder a eso. ¿Te ayudo con algo sobre nuestros productos o servicios?',
        429 => 'Estás escribiendo muy rápido 🙂 Esperá unos segundos e intentá de nuevo.',
        503 => 'El servicio no está disponible por unos minutos. Probá de nuevo en un ratito.',
    ];

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
        $inbound = $adapter->parseInbound( $payload );
        if ( null === $inbound ) {
            return; // no era un mensaje de texto procesable
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
            // Errores de negocio (cuota, rate limit, input): fallback amable, sin reintento.
            $msg = self::FALLBACK[ $e->getCode() ] ?? 'Ocurrió un problema. Probá de nuevo en un momento.';
            $this->trySend( $adapter, $channel, $inbound->externalUser, $msg );
            return;
        }
        // Errores transitorios (LLM 5xx/red) son \Throwable distinto de RuntimeException con código:
        // se dejan propagar para que Action Scheduler reintente con backoff.

        $reply = $sink->getBuffer();
        if ( '' !== trim( $reply ) ) {
            $adapter->send( $channel, $inbound->externalUser, $reply );
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
