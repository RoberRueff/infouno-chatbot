<?php

declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Proveedor Anthropic (Claude). Llama a la Messages API con streaming SSE.
 * La API key se lee desde la constante INFOUNO_ANTHROPIC_KEY en wp-config.php.
 * Nunca se expone en respuestas ni logs.
 */
final class AnthropicProvider implements LLMProviderInterface {

    private const API_URL        = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION    = '2023-06-01';
    private const TIMEOUT_SECS   = 15;

    public function streamChat( array $messages, array $options, callable $onChunk ): StreamResult {
        $apiKey = $this->resolveKey();

        $systemPrompt = '';
        $filtered     = [];

        foreach ( $messages as $msg ) {
            if ( 'system' === $msg['role'] ) {
                $systemPrompt = $msg['content'];
            } else {
                $filtered[] = $msg;
            }
        }

        $payload = [
            'model'      => $options['model'] ?? 'claude-haiku-4-5-20251001',
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'stream'     => true,
            'messages'   => $filtered,
        ];

        if ( '' !== $systemPrompt ) {
            $payload['system'] = $systemPrompt;
        }

        if ( isset( $options['temperature'] ) ) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $inputTokens  = 0;
        $outputTokens = 0;
        $finishReason = 'end_turn';
        $sseBuffer    = '';

        $ch = curl_init( self::API_URL );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'anthropic-version: ' . self::API_VERSION,
                'x-api-key: ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$sseBuffer, &$inputTokens, &$outputTokens, &$finishReason, $onChunk ) {
                if ( connection_aborted() ) {
                    return -1;
                }

                $sseBuffer .= $data;

                while ( false !== ( $pos = strpos( $sseBuffer, "\n\n" ) ) ) {
                    $block     = substr( $sseBuffer, 0, $pos );
                    $sseBuffer = substr( $sseBuffer, $pos + 2 );
                    $this->parseBlock( $block, $onChunk, $inputTokens, $outputTokens, $finishReason );
                }

                return strlen( $data );
            },
        ] );

        curl_exec( $ch );
        $httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlErr  = curl_error( $ch );
        curl_close( $ch );

        if ( $httpCode === 429 ) {
            throw new \RuntimeException( 'Rate limit de Anthropic alcanzado.', 429 );
        }

        if ( $httpCode === 401 || $httpCode === 403 ) {
            error_log( '[INFOUNO-ERROR] Anthropic auth error. HTTP ' . $httpCode );
            throw new \RuntimeException( 'Error de autenticación con Anthropic.', 500 );
        }

        if ( $httpCode === 400 ) {
            error_log( '[INFOUNO-ERROR] Anthropic bad request. HTTP 400.' );
            throw new \RuntimeException( 'Solicitud rechazada por Anthropic.', 502 );
        }

        if ( $httpCode >= 500 || ( $httpCode === 0 && $curlErr ) ) {
            throw new \RuntimeException( sprintf( 'Error de Anthropic (HTTP %d): %s', $httpCode, $curlErr ), 502 );
        }

        return new StreamResult( $inputTokens, $outputTokens, $finishReason, $this->providerName(), $payload['model'] );
    }

    public function providerName(): string {
        return 'anthropic';
    }

    private function parseBlock( string $block, callable $onChunk, int &$inputTokens, int &$outputTokens, string &$finishReason ): void {
        $eventType = '';
        $dataLine  = '';

        foreach ( explode( "\n", $block ) as $line ) {
            if ( str_starts_with( $line, 'event: ' ) ) {
                $eventType = trim( substr( $line, 7 ) );
            } elseif ( str_starts_with( $line, 'data: ' ) ) {
                $dataLine = trim( substr( $line, 6 ) );
            }
        }

        if ( '' === $dataLine ) {
            return;
        }

        $payload = json_decode( $dataLine, true );
        if ( ! is_array( $payload ) ) {
            return;
        }

        switch ( $eventType ) {
            case 'content_block_delta':
                $delta = $payload['delta'] ?? [];
                if ( 'text_delta' === ( $delta['type'] ?? '' ) && isset( $delta['text'] ) ) {
                    $onChunk( $delta['text'] );
                }
                break;

            case 'message_start':
                $usage        = $payload['message']['usage'] ?? [];
                $inputTokens  = (int) ( $usage['input_tokens'] ?? 0 );
                $outputTokens = (int) ( $usage['output_tokens'] ?? 0 );
                break;

            case 'message_delta':
                $outputTokens = (int) ( $payload['usage']['output_tokens'] ?? $outputTokens );
                $finishReason = $payload['delta']['stop_reason'] ?? $finishReason;
                break;
        }
    }

    private function resolveKey(): string {
        if ( defined( 'INFOUNO_ANTHROPIC_KEY' ) && '' !== INFOUNO_ANTHROPIC_KEY ) {
            return INFOUNO_ANTHROPIC_KEY;
        }
        throw new \RuntimeException( 'Clave de Anthropic no configurada (INFOUNO_ANTHROPIC_KEY).', 500 );
    }
}
