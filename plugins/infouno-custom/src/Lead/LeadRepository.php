<?php

declare(strict_types=1);

namespace Infouno\SaaS\Lead;

/**
 * Acceso a las tablas wp_infouno_leads y wp_infouno_lead_consents.
 * Toda consulta incluye tenant_id — guardrail de aislamiento de datos.
 * Los datos PII solo se almacenan si hasConsent() retorna true previamente.
 */
final class LeadRepository {

    private string $table;
    private string $tableLeadConsents;

    public function __construct() {
        global $wpdb;
        $this->table             = $wpdb->prefix . 'infouno_leads';
        $this->tableLeadConsents = $wpdb->prefix . 'infouno_lead_consents';
    }

    /**
     * Guarda o actualiza un lead garantizando aislamiento por tenant y bot.
     *
     * En actualización solo sobreescribe campos que vengan con valor no nulo,
     * preservando datos ya capturados (nombre, email, teléfono) frente a
     * mensajes posteriores que no los repitan.
     *
     * @param array<string, mixed> $leadData
     * @throws \InvalidArgumentException Si faltan campos obligatorios.
     */
    public function save( array $leadData ): int {
        global $wpdb;

        foreach ( [ 'tenant_id', 'bot_id', 'session_hash' ] as $required ) {
            if ( empty( $leadData[ $required ] ) ) {
                throw new \InvalidArgumentException( "{$required} es requerido." );
            }
        }

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM `{$this->table}` WHERE session_hash = %s AND tenant_id = %d AND bot_id = %d LIMIT 1",
                $leadData['session_hash'],
                (int) $leadData['tenant_id'],
                (int) $leadData['bot_id']
            )
        );

        if ( $existing ) {
            // Solo actualiza campos con valor — preserva datos ya capturados.
            $updateData = array_filter(
                [
                    'name'           => $leadData['name']        ?? null,
                    'phone'          => $leadData['phone']       ?? null,
                    'email'          => $leadData['email']       ?? null,
                    'interest'       => $leadData['interest']    ?? null,
                    'score'          => $leadData['score']       ?? null,
                    'temperature'    => $leadData['temperature'] ?? null,
                    // intent_signals siempre se reemplaza con la versión más reciente del análisis
                    'intent_signals' => isset( $leadData['intent_signals'] )
                        ? wp_json_encode( $leadData['intent_signals'] )
                        : null,
                ],
                static fn( $v ) => $v !== null
            );

            if ( ! empty( $updateData ) ) {
                $formats = array_map(
                    static fn( $k ) => $k === 'score' ? '%d' : '%s',
                    array_keys( $updateData )
                );

                $wpdb->update(
                    $this->table,
                    $updateData,
                    [ 'id' => (int) $existing->id ],
                    $formats,
                    [ '%d' ]
                );
            }

            return (int) $existing->id;
        }

        $wpdb->insert(
            $this->table,
            [
                'tenant_id'       => (int) $leadData['tenant_id'],
                'bot_id'          => (int) $leadData['bot_id'],
                'conversation_id' => isset( $leadData['conversation_id'] ) ? (int) $leadData['conversation_id'] : null,
                'session_hash'    => $leadData['session_hash'],
                'name'            => $leadData['name']        ?? null,
                'phone'           => $leadData['phone']       ?? null,
                'email'           => $leadData['email']       ?? null,
                'interest'        => $leadData['interest']    ?? null,
                'score'           => (int) ( $leadData['score'] ?? 0 ),
                'temperature'     => $leadData['temperature'] ?? 'cold',
                'intent_signals'  => isset( $leadData['intent_signals'] )
                    ? wp_json_encode( $leadData['intent_signals'] )
                    : null,
                'source'          => $leadData['source']      ?? 'chat',
                'status'          => 'new',
            ],
            [ '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Verifica si una sesión de un bot específico consintió la captura de un campo PII.
     *
     * El bot_id es obligatorio: el consentimiento es específico por bot,
     * no transferible entre bots distintos dentro de la misma sesión.
     *
     * @param string $dataType 'name' | 'phone' | 'email'
     */
    public function hasConsent( string $sessionId, int $botId, string $dataType ): bool {
        global $wpdb;

        $columnMap = [
            'name'  => 'can_capture_name',
            'phone' => 'can_capture_phone',
            'email' => 'can_capture_email',
        ];

        if ( ! isset( $columnMap[ $dataType ] ) ) {
            return false;
        }

        $column      = $columnMap[ $dataType ];
        $sessionHash = hash( 'sha256', $sessionId );

        $value = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `{$column}` FROM `{$this->tableLeadConsents}`
                 WHERE session_hash = %s AND bot_id = %d
                 ORDER BY accepted_at DESC
                 LIMIT 1",
                $sessionHash,
                $botId
            )
        );

        return (int) $value === 1;
    }
}
