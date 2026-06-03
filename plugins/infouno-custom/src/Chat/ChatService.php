<?php

declare(strict_types=1);

namespace Infouno\SaaS\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Lead\LeadService;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\Security\InputGuard;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Orquesta el flujo completo de una interacción de chat:
 * validar → construir contexto → stream → guardar → incrementar cuota → capturar lead.
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
        // 1. Validar y sanitizar el mensaje — prompt injection + longitud
        $userMessage = InputGuard::validateMessage( $userMessage );

        // 2. Segunda capa CORS — defensa en profundidad tras la pre-validación del controller
        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            throw new \RuntimeException( 'Origen no autorizado para este bot.', 403 );
        }

        $tenantId = (int) $bot['tenant_id'];

        // 3. Validar tenant (estado + cuota mensual) — guardrail financiero pre-vuelo
        $tenant     = $this->tenantManager->validateForChat( $tenantId );
        $tenantPlan = $tenant['plan'] ?? 'free';

        // 4. Rate limiting por sesión + IP — guardrail financiero
        $this->quotaService->checkRateLimit( $sessionId );

        // 5. Obtener o crear la conversación de esta sesión
        $conversation = $this->conversationRepo->getOrCreate( $tenantId, (int) $bot['id'], $sessionId );
        $convId       = (int) $conversation['id'];
        $windowSize   = (int) ( $bot['settings']['context_window'] ?? 10 );

        // 6. Verificar techo de tokens por conversación — guardrail token-economy.md
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

        // 7. Incrementar rate limit antes de llamar al LLM (evita retry flooding)
        $this->quotaService->increment( $sessionId );

        // 8. Stream al cliente — acumula la respuesta completa para persistirla
        $fullResponse = '';
        $result       = $this->llmRouter->stream(
            $bot,
            $messages,
            static function ( string $delta ) use ( $onChunk, &$fullResponse ) {
                $fullResponse .= $delta;
                $onChunk( $delta );
            },
            $tenantPlan
        );

        // 9. Persistir el intercambio y actualizar cuota mensual del tenant
        $this->conversationRepo->saveExchange(
            $convId,
            $userMessage,
            $fullResponse,
            $result->inputTokens,
            $result->outputTokens,
            $tenantPlan
        );

        $this->tenantManager->incrementQuota( $tenantId, $result->totalTokens() );

        // 10. Lead Engine — analiza el mensaje si hay consentimiento PII previo.
        //     No-crítico: un fallo aquí nunca interrumpe el chat del usuario final.
        if ( null !== $this->leadService ) {
            try {
                $this->leadService->processMessage(
                    $tenantId,
                    (int) $bot['id'],
                    $sessionId,
                    $convId,
                    $userMessage,
                    $history
                );
            } catch ( \Throwable ) {
                // Silencioso — lead capture es best-effort
            }
        }
    }

    /**
     * Construye el array de mensajes para el LLM:
     * [system] + historial (sliding window) + mensaje actual del usuario.
     *
     * @param  array<array{role:string,content:string}> $history
     * @return array<array{role:string,content:string}>
     */
    private function buildMessages( string $systemPrompt, array $history, string $userMessage ): array {
        $messages = [];

        if ( '' !== trim( $systemPrompt ) ) {
            $messages[] = [ 'role' => 'system', 'content' => $systemPrompt ];
        }

        foreach ( $history as $msg ) {
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $messages[] = [ 'role' => 'user', 'content' => $userMessage ];

        return $messages;
    }
}
