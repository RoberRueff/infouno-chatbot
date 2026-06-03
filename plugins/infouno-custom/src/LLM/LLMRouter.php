<?php

declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Enruta las llamadas al proveedor configurado por el bot.
 * Implementa exponential backoff (hasta 2 reintentos) y fallback automático
 * al proveedor secundario si el primario falla repetidamente.
 */
final class LLMRouter {

    private const MAX_RETRIES   = 2;
    private const BASE_DELAY_MS = 500;
    private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

    /**
     * Modelos permitidos por plan — única fuente de verdad del guardrail financiero de modelos.
     * Un tenant no puede usar un modelo más caro que su plan, aunque lo configure en el bot.
     *
     * @var array<string, list<string>>
     */
    public const ALLOWED_MODELS = [
        'free'    => [ 'claude-haiku-4-5-20251001', 'gpt-4o-mini' ],
        'trial'   => [ 'claude-haiku-4-5-20251001', 'gpt-4o-mini' ],
        'premium' => [ 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'gpt-4o-mini', 'gpt-4o' ],
        'agency'  => [ 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6', 'claude-opus-4-8', 'gpt-4o-mini', 'gpt-4o' ],
    ];

    /** @var array<string, LLMProviderInterface> */
    private array $providers;

    public function __construct() {
        $this->providers = [
            'anthropic' => new AnthropicProvider(),
            'openai'    => new OpenAIProvider(),
        ];
    }

    /**
     * Ejecuta el stream del chat usando el proveedor configurado en el bot.
     * Si falla con 429 o 5xx, reintenta con backoff exponencial.
     * Tras MAX_RETRIES fallos, conmuta al proveedor de respaldo.
     *
     * @param array    $bot        Fila del bot (llm_provider, llm_model, settings).
     * @param array    $messages   Historial construido con sliding window.
     * @param callable $onChunk    Recibe cada fragmento de texto para emitirlo por SSE.
     * @param string   $tenantPlan Plan activo — determina los modelos permitidos.
     */
    public function stream( array $bot, array $messages, callable $onChunk, string $tenantPlan = 'free' ): StreamResult {
        $primaryName  = $bot['llm_provider'] ?? 'anthropic';
        $fallbackName = 'anthropic' === $primaryName ? 'openai' : 'anthropic';
        $settings     = $bot['settings'] ?? [];

        $options = [
            'model'       => $this->resolveModel( $bot['llm_model'] ?? self::DEFAULT_MODEL, $tenantPlan ),
            'max_tokens'  => (int) ( $settings['max_tokens'] ?? 1024 ),
            'temperature' => (float) ( $settings['temperature'] ?? 0.7 ),
        ];

        $lastException = null;

        // Intenta primero el proveedor configurado
        for ( $attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++ ) {
            try {
                if ( $attempt > 0 ) {
                    $this->sleep( self::BASE_DELAY_MS * ( 2 ** ( $attempt - 1 ) ) );
                }

                return $this->providers[ $primaryName ]->streamChat( $messages, $options, $onChunk );

            } catch ( \RuntimeException $e ) {
                $lastException = $e;

                // Solo reintenta en errores recuperables
                if ( ! $this->isRetryable( $e->getCode() ) ) {
                    break;
                }
            }
        }

        // Fallback al proveedor secundario (un solo intento).
        // Hook: listeners pueden logear, alertar al tenant o registrar métricas de costo inesperado.
        if ( isset( $this->providers[ $fallbackName ] ) ) {
            do_action(
                'infouno_model_fallback',
                $primaryName,
                $fallbackName,
                $options['model'],
                $lastException?->getMessage() ?? ''
            );

            try {
                return $this->providers[ $fallbackName ]->streamChat( $messages, $options, $onChunk );
            } catch ( \RuntimeException $e ) {
                $lastException = $e;
            }
        }

        throw new \RuntimeException(
            'Todos los proveedores de IA fallaron. ' . ( $lastException?->getMessage() ?? '' ),
            503
        );
    }

    /**
     * Valida que el modelo solicitado esté permitido para el plan del tenant.
     * Si no, degrada silenciosamente al modelo por defecto para evitar costes no autorizados.
     */
    private function resolveModel( string $requestedModel, string $plan ): string {
        $allowed = self::ALLOWED_MODELS[ $plan ] ?? self::ALLOWED_MODELS['free'];

        if ( in_array( $requestedModel, $allowed, true ) ) {
            return $requestedModel;
        }

        error_log( sprintf(
            '[INFOUNO-SECURITY] Model "%s" not allowed for plan "%s". Downgraded to %s.',
            $requestedModel,
            $plan,
            self::DEFAULT_MODEL
        ) );

        return self::DEFAULT_MODEL;
    }

    private function isRetryable( int $code ): bool {
        return in_array( $code, [ 429, 500, 502, 503, 504 ], true );
    }

    /** usleep en ms para no bloquear con valores grandes, cumple el guardrail de timeout. */
    private function sleep( int $ms ): void {
        usleep( $ms * 1000 );
    }
}
