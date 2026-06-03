<?php

declare(strict_types=1);

namespace Infouno\SaaS\LLM;

/**
 * Proveedor OpenAI — usado como fallback automático cuando Anthropic falla.
 * La API key se lee desde la constante INFOUNO_OPENAI_KEY en wp-config.php.
 */
final class OpenAIProvider implements LLMProviderInterface {

    private const API_URL      = 'https://api.openai.com/v1/chat/completions';
    private const TIMEOUT_SECS = 15;

    public function streamChat( array $messages, array $options, callable $onChunk ): StreamResult {
        $apiKey = $this->resolveKey();

        $model   = $options['model'] ?? 'gpt-4o-mini';
        $payload = [
            'model'       => $model,
            'max_tokens'  => $options['max_tokens'] ?? 1024,
            'stream'      => true,
            'stream_options' => [ 'include_usage' => true ],
            'messages'    => $messages,
        ];

        if ( isset( $options['temperature'] ) ) {
            $payload['temperature'] = (float) $options['temperature'];
        }

        $inputTokens  = 0;
        $outputTokens = 0;
        $finishReason = 'stop';
        $sseBuffer    = '';

        $ch = curl_init( self::API_URL );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT        => self::TIMEOUT_SECS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$sseBuffer, &$inputTokens, &$outputTokens, &$finishReason, $onChunk ) {
                if ( connection_aborted() ) {
                    return -1;
                }

                $sseBuffer .= $data;

                while ( false !== ( $pos = strpos( $sseBuffer, "\n\n" ) ) ) {
                    $line      = trim( substr( $sseBuffer, 0, $pos ) );
                    $sseBuffer = substr( $sseBuffer, $pos + 2 );
                    $this->parseLine( $line, $onChunk, $inputTokens, $outputTokens, $finishReason );
                }

                return strlen( $data );
            },
        ] );

        curl_exec( $ch );
        $httpCode = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curlErr  = curl_error( $ch );
        curl_close( $ch );

        if ( $httpCode === 429 ) {
            throw new \RuntimeException( 'Rate limit de OpenAI alcanzado.', 429 );
        }

        if ( $httpCode === 401 || $httpCode === 403 ) {
            error_log( '[INFOUNO-ERROR] OpenAI auth error. HTTP ' . $httpCode );
            throw new \RuntimeException( 'Error de autenticación con OpenAI.', 500 );
        }

        if ( $httpCode === 400 ) {
            error_log( '[INFOUNO-ERROR] OpenAI bad request. HTTP 400.' );
            throw new \RuntimeException( 'Solicitud rechazada por OpenAI.', 502 );
        }

        if ( $httpCode >= 500 || ( $httpCode === 0 && $curlErr ) ) {
            throw new \RuntimeException( sprintf( 'Error de OpenAI (HTTP %d): %s', $httpCode, $curlErr ), 502 );
        }

        return new StreamResult( $inputTokens, $outputTokens, $finishReason, $this->providerName(), $model );
    }

    public function providerName(): string {
        return 'openai';
    }

    private function parseLine( string $line, callable $onChunk, int &$inputTokens, int &$outputTokens, string &$finishReason ): void {
        if ( ! str_starts_with( $line, 'data: ' ) ) {
            return;
        }

        $data = substr( $line, 6 );

        if ( '[DONE]' === $data ) {
            return;
        }

        $payload = json_decode( $data, true );
        if ( ! is_array( $payload ) ) {
            return;
        }

        // Token usage viene en el último chunk con stream_options include_usage
        if ( isset( $payload['usage'] ) ) {
            $inputTokens  = (int) ( $payload['usage']['prompt_tokens'] ?? 0 );
            $outputTokens = (int) ( $payload['usage']['completion_tokens'] ?? 0 );
        }

        $choice = $payload['choices'][0] ?? null;
        if ( ! $choice ) {
            return;
        }

        if ( isset( $choice['finish_reason'] ) && null !== $choice['finish_reason'] ) {
            $finishReason = $choice['finish_reason'];
        }

        $delta = $choice['delta'] ?? [];
        if ( isset( $delta['content'] ) && '' !== $delta['content'] ) {
            $onChunk( $delta['content'] );
        }
    }

    private function resolveKey(): string {
        if ( defined( 'INFOUNO_OPENAI_KEY' ) && '' !== INFOUNO_OPENAI_KEY ) {
            return INFOUNO_OPENAI_KEY;
        }
        throw new \RuntimeException( 'Clave de OpenAI no configurada (INFOUNO_OPENAI_KEY).', 500 );
    }
}
