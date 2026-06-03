<?php

declare(strict_types=1);

namespace Infouno\SaaS\Bot;

/**
 * Genera un system_prompt comercial a partir de los datos estructurados
 * del Knowledge Builder wizard.
 *
 * Entrada: array con los datos del negocio ingresados por el tenant.
 * Salida: string con el prompt listo para usar como system_prompt del bot.
 *
 * No guarda en BD — esa responsabilidad es del caller (BotWizard / BotController).
 */
final class PromptBuilder {

    /**
     * Genera el system_prompt desde los datos del wizard.
     *
     * @param array{
     *   company_name?: string,
     *   industry?: string,
     *   location?: string,
     *   products?: string[],
     *   services?: string[],
     *   faq?: array<array{q: string, a: string}>,
     *   coverage?: string,
     *   hours?: string,
     *   welcome_tone?: string,
     *   lead_goal?: string,
     * } $data Datos del wizard sanitizados por el caller.
     */
    public static function fromWizardData( array $data ): string {
        $company  = trim( (string) ( $data['company_name'] ?? '' ) );
        $industry = trim( (string) ( $data['industry']     ?? '' ) );
        $location = trim( (string) ( $data['location']     ?? '' ) );
        $hours    = trim( (string) ( $data['hours']        ?? '' ) );
        $coverage = trim( (string) ( $data['coverage']     ?? '' ) );
        $tone     = trim( (string) ( $data['welcome_tone'] ?? 'cercano y profesional' ) );
        $leadGoal = trim( (string) ( $data['lead_goal']    ?? 'Capturar el nombre y el número de WhatsApp del interesado.' ) );

        $products = array_values( array_filter( array_map( 'trim', (array) ( $data['products'] ?? [] ) ) ) );
        $services = array_values( array_filter( array_map( 'trim', (array) ( $data['services'] ?? [] ) ) ) );
        $faq      = array_values( array_filter( (array) ( $data['faq'] ?? [] ), static fn( $item ) => ! empty( $item['q'] ) && ! empty( $item['a'] ) ) );

        // ── Línea de introducción ──────────────────────────────────────────────
        $intro = 'Sos el asistente comercial de ' . ( $company ?: 'esta empresa' );
        if ( $industry ) {
            $intro .= ", {$industry}";
        }
        if ( $location ) {
            $intro .= " ubicada en {$location}";
        }
        $intro .= '.';

        $lines = [
            $intro,
            '',
            "Tu objetivo principal es AYUDAR al cliente y CAPTURAR LEADS CALIFICADOS para el equipo de ventas.",
            "Hablá siempre en tono {$tone}, usando vos (tuteo rioplatense).",
            '',
        ];

        // ── Productos ─────────────────────────────────────────────────────────
        if ( ! empty( $products ) ) {
            $lines[] = '═══ PRODUCTOS ═══';
            foreach ( $products as $p ) {
                $lines[] = "• {$p}";
            }
            $lines[] = '';
        }

        // ── Servicios ─────────────────────────────────────────────────────────
        if ( ! empty( $services ) ) {
            $lines[] = '═══ SERVICIOS ═══';
            foreach ( $services as $s ) {
                $lines[] = "• {$s}";
            }
            $lines[] = '';
        }

        // ── Información operativa ─────────────────────────────────────────────
        $operativo = [];
        if ( $hours ) {
            $operativo[] = "Horarios: {$hours}";
        }
        if ( $coverage ) {
            $operativo[] = "Zona de cobertura: {$coverage}";
        }

        if ( ! empty( $operativo ) ) {
            $lines[] = '═══ INFORMACIÓN OPERATIVA ═══';
            foreach ( $operativo as $line ) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        // ── FAQ ───────────────────────────────────────────────────────────────
        if ( ! empty( $faq ) ) {
            $lines[] = '═══ PREGUNTAS FRECUENTES ═══';
            foreach ( $faq as $item ) {
                $lines[] = 'P: ' . trim( (string) $item['q'] );
                $lines[] = 'R: ' . trim( (string) $item['a'] );
                $lines[] = '';
            }
        }

        // ── Reglas de captura de leads ────────────────────────────────────────
        $lines[] = '═══ REGLAS DE CAPTURA DE LEADS ═══';
        $lines[] = "Objetivo: {$leadGoal}";
        $lines[] = '1. Cuando el cliente muestre interés concreto en comprar, pedir presupuesto o solicitar información específica, pedile su nombre y número de WhatsApp.';
        $lines[] = '2. Si menciona urgencia o un plazo concreto, tratalo como prioridad alta y ofrecé contacto inmediato.';
        $lines[] = '3. No termines la conversación sin intentar capturar al menos un dato de contacto.';
        $lines[] = '4. Si el cliente ya dio su teléfono o email, confirmá los datos y ofrecé enviar información por ese canal.';
        $lines[] = '5. Nunca inventés precios, stocks ni características que no estén en este prompt. Si no sabés algo, decí que lo vas a consultar con el equipo.';

        return implode( "\n", $lines );
    }

    /**
     * Valida que los datos del wizard tengan al menos el mínimo necesario
     * para generar un prompt útil.
     *
     * @return string[] Lista de errores (vacía si los datos son válidos).
     */
    public static function validate( array $data ): array {
        $errors = [];

        if ( empty( trim( (string) ( $data['company_name'] ?? '' ) ) ) ) {
            $errors[] = 'El nombre de la empresa es obligatorio.';
        }

        $hasProducts = ! empty( array_filter( array_map( 'trim', (array) ( $data['products'] ?? [] ) ) ) );
        $hasServices = ! empty( array_filter( array_map( 'trim', (array) ( $data['services'] ?? [] ) ) ) );

        if ( ! $hasProducts && ! $hasServices ) {
            $errors[] = 'Ingresá al menos un producto o servicio que ofrece el negocio.';
        }

        return $errors;
    }
}
