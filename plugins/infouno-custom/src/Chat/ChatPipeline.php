<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Lead\LeadService;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use Infouno\SaaS\LLM\TokenEstimator;
use Infouno\SaaS\Security\InputGuard;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Pipeline de chat transport-agnostic. Ejecuta las etapas de validación,
 * contexto, LLM, persistencia, cuota y captura de lead, escribiendo la
 * salida en un OutputSink (SSE para web, bufferizado para canales).
 *
 * La validación de Origin NO vive aquí: es responsabilidad del transporte web
 * (ChatController::preValidate). Los canales autentican vía firma de webhook.
 */
final class ChatPipeline {

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly QuotaService           $quotaService,
        private readonly ConversationRepository $conversationRepo,
        private readonly LLMRouter              $llmRouter,
        private readonly ?LeadService           $leadService = null,
    ) {}

    /**
     * @param array<string,mixed> $bot             Bot pre-validado.
     * @param string              $conversationKey session_id (web) | "tg:<chatid>" (canal).
     * @param string              $userMessage     Mensaje del usuario.
     * @param OutputSink          $sink            Transporte de salida.
     * @param PipelineContext     $ctx             Contexto de canal.
     * @throws \RuntimeException con código HTTP semántico en validaciones fallidas.
     */
    public function run(
        array           $bot,
        string          $conversationKey,
        string          $userMessage,
        OutputSink      $sink,
        PipelineContext $ctx
    ): StreamResult {
        // 1. Validar y sanitizar el mensaje — prompt injection + longitud
        $userMessage = InputGuard::validateMessage( $userMessage );

        $tenantId = (int) $bot['tenant_id'];

        // 2. Validar tenant (estado + cuota mensual) — guardrail financiero pre-vuelo
        $tenant     = $this->tenantManager->validateForChat( $tenantId );
        $tenantPlan = $tenant['plan'] ?? 'free';

        // 3. Rate limiting por sesión + clave secundaria (IP en web, external_user en canal)
        $this->quotaService->checkRateLimit( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 4. Obtener o crear la conversación de esta clave
        $conversation = $this->conversationRepo->getOrCreate(
            $tenantId,
            (int) $bot['id'],
            $conversationKey,
            $ctx->channel,
            $ctx->externalUser
        );
        $convId     = (int) $conversation['id'];
        $windowSize = (int) ( $bot['settings']['context_window'] ?? 10 );

        // 5. Techo de tokens por conversación — guardrail token-economy.md
        $maxConvTokens = (int) ( $bot['settings']['max_conv_tokens'] ?? 20_000 );
        $usedInConv    = $this->conversationRepo->totalTokensForConversation( $convId, $tenantId );
        if ( $usedInConv >= $maxConvTokens ) {
            throw new \RuntimeException(
                'Esta conversación alcanzó su límite. Iniciá una nueva para continuar.',
                402
            );
        }

        $history  = $this->conversationRepo->getRecentMessages( $convId, $tenantId, $windowSize );
        $messages = $this->buildMessages( (string) ( $bot['system_prompt'] ?? '' ), $history, $userMessage );

        // 6. Reservar presupuesto de cuota ANTES del LLM — límite duro y race-safe.
        $maxTokens = (int) ( $bot['settings']['max_tokens'] ?? 1024 );
        $estimate  = TokenEstimator::estimateMessages( $messages ) + $maxTokens;
        if ( ! $this->tenantManager->reserve( $tenantId, $estimate ) ) {
            throw new \RuntimeException( 'Cuota mensual agotada.', 402 );
        }

        // 7. Incrementar rate limit antes de llamar al LLM (evita retry flooding)
        $this->quotaService->increment( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 8. Stream al sink — acumula la respuesta completa para persistirla.
        //    Reconcilia la reserva con el consumo real; libera si el request falla.
        $fullResponse = '';
        try {
            $result = $this->llmRouter->stream(
                $bot,
                $messages,
                static function ( string $delta ) use ( $sink, &$fullResponse ) {
                    $fullResponse .= $delta;
                    $sink->write( $delta );
                },
                $tenantPlan
            );
            $sink->finish();

            // Conteo real; si el proveedor no devolvió usage pero hubo texto, estimar.
            $actual = $result->totalTokens();
            if ( 0 === $actual && '' !== trim( $fullResponse ) ) {
                $actual = TokenEstimator::estimateMessages( $messages ) + TokenEstimator::estimate( $fullResponse );
            }

            $this->conversationRepo->saveExchange(
                $convId,
                $userMessage,
                $fullResponse,
                $result->inputTokens,
                $result->outputTokens,
                $tenantPlan
            );

            $this->tenantManager->reconcile( $tenantId, $estimate, $actual );
        } catch ( \Throwable $e ) {
            $this->tenantManager->release( $tenantId, $estimate );
            throw $e;
        }

        // 9. Lead Engine — best-effort, nunca interrumpe el chat
        if ( null !== $this->leadService ) {
            try {
                $this->leadService->processMessage(
                    $tenantId,
                    (int) $bot['id'],
                    $conversationKey,
                    $convId,
                    $userMessage,
                    $history
                );
            } catch ( \Throwable ) {
                // silencioso
            }
        }

        return $result;
    }

    /**
     * @param array<array{role:string,content:string}> $history
     * @return array<array{role:string,content:string}>
     */
    private function buildMessages( string $systemPrompt, array $history, string $userMessage ): array {
        $messages = [];

        if ( '' !== trim( $systemPrompt ) ) {
            $messages[] = [ 'role' => 'system', 'content' => $systemPrompt ];
        }
        foreach ( $history as $msg ) {
            $messages[] = [ 'role' => $msg['role'], 'content' => $msg['content'] ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $userMessage ];

        return $messages;
    }
}
