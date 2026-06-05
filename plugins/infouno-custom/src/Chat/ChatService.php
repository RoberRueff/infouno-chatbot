<?php

declare(strict_types=1);

namespace Infouno\SaaS\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Lead\LeadService;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Fachada web del pipeline de chat. Valida el Origin (capa 2 CORS, defensa en
 * profundidad del transporte web) y delega la ejecución completa en
 * ChatPipeline con un StreamingSink, conservando la firma pública de handle().
 *
 * Recibe el bot ya resuelto desde ChatController para evitar una
 * segunda query a la BD (optimización de N-1 queries por request).
 */
final class ChatService {

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly QuotaService           $quotaService,
        private readonly ConversationRepository $conversationRepo,
        private readonly LLMRouter              $llmRouter,
        private readonly ?LeadService           $leadService = null,
    ) {}

    /**
     * Punto de entrada principal. Ejecuta todo el pipeline de chat.
     *
     * @param  array<string,mixed> $bot        Bot pre-validado por ChatController.
     * @param  string              $sessionId  ID de sesión del usuario final.
     * @param  string              $userMessage Mensaje del usuario.
     * @param  string              $origin     Cabecera Origin de la petición HTTP.
     * @param  callable            $onChunk    fn(string $delta): void — emite cada fragmento SSE.
     * @throws \RuntimeException Con código HTTP semántico en validaciones fallidas.
     */
    public function handle(
        array    $bot,
        string   $sessionId,
        string   $userMessage,
        string   $origin,
        callable $onChunk
    ): void {
        // Capa 2 CORS — defensa en profundidad del transporte web (no aplica a canales).
        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            throw new \RuntimeException( 'Origen no autorizado para este bot.', 403 );
        }

        $pipeline = new ChatPipeline(
            $this->tenantManager,
            $this->botManager,
            $this->quotaService,
            $this->conversationRepo,
            $this->llmRouter,
            $this->leadService,
        );

        $pipeline->run(
            $bot,
            $sessionId,
            $userMessage,
            new StreamingSink( $onChunk ),
            PipelineContext::web()
        );
    }
}
