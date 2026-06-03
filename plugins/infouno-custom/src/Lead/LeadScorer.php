<?php

declare(strict_types=1);

namespace Infouno\SaaS\Lead;

/**
 * Analiza mensajes del chat para extraer datos de contacto, calcular un score de calificación,
 * derivar la temperatura comercial del lead y detectar señales de intención estructuradas (BANT).
 *
 * Patrones calibrados para el español argentino coloquial (WhatsApp-style).
 * Solo procesa PII si el consentimiento lead_capture fue registrado previamente.
 */
final class LeadScorer {

    /**
     * Patrones de extracción PII y señales de intención.
     * Calibrados para PyMEs argentinas: voseo, modismos rioplatenses,
     * teléfonos argentinos y señales de compra de alta intención.
     */
    private const PATTERNS = [
        // Datos de contacto
        'email'    => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
        'phone_ar' => '/(?:\+?54\s?)?(?:9\s?)?(?:(?:11|15)\s?)?(?:\d{4}[\s\-]?\d{4}|\d{3}[\s\-]?\d{3}[\s\-]?\d{4})/',
        'name'     => '/(?:me llamo|mi nombre es|soy|yo soy|llamame|me pod[eé]s llamar)\s+([A-Za-záéíóúüñÑ]{2,30}(?:\s+[A-Za-záéíóúüñÑ]{2,30})?)/ui',

        // Intención de compra — español argentino (voseo + coloquial)
        'interest_buy' => '/(?:
            quier[oa]\s+(?:comprar?|adquirir|contratar?|(?:ver|saber)\s+(?:el\s+)?precio)|
            necesito\s+(?:comprar?|adquirir|contratar?|(?:uno|una|esto|eso))|
            busco\s+(?:comprar?|adquirir|contratar?)|
            me\s+interes[ao]\s+(?:comprar?|adquirir|contratar?)|
            cu[aá]nto\s+(?:sale|cuesta|costar[íi]a|queda|me\s+saldr[íi]a|cobr[aá]n)|
            precio|presupuesto|cotizaci[oó]n|cotizar|tarifas?|
            comprar?|adquirir|contratar?|lo\s+tomo|lo\s+agarro|me\s+lo\s+llevo|cerramos?|dale|
            mand[aá]me|mand[aé]n|env[íi]en|mand[aá]\s+info|
            tienen?\s+(?:stock|disponible|unidades?)|
            hacen?\s+env[íi]os?|delivery|despachan?|mandan?\s+(?:a\s+domicilio|por\s+correo)|
            cu[aá]ndo\s+(?:pueden|llega|entregan?|estar[íi]a)|
            pueden?\s+venir|tienen?\s+(?:turno|cita|agenda)|sacar\s+(?:un\s+)?turno|
            tienen?\s+(?:cuotas?|financiaci[oó]n|plan\s+de\s+pagos?|ahora\s+\d+)|
            se\s+puede\s+pagar\s+(?:con\s+)?(?:tarjeta|cuotas?|mercado\s*pago|QR|transferencia)
        )/uxi',

        // Intención informativa — preguntas exploratorias (no scoring alto)
        'interest_info' => '/(?:
            consultar?|preguntar?|saber\s+m[aá]s|informaci[oó]n|info|
            c[oó]mo\s+funciona|qu[eé]\s+(?:es|incluye|ofrecen?|cubren?|tienen?)|
            horarios?|d[oó]nde\s+est[aá]n?|direcci[oó]n|ubicaci[oó]n|
            abren?\s+(?:los\s+)?(?:s[aá]bados?|domingos?|feriados?|hoy)|
            tienen?\s+(?:local|sucursal|sede|web|p[aá]gina)|
            cuales\s+son\s+los|qu[eé]\s+servicios?|qu[eé]\s+productos?|
            hacen?\s+(?:env[íi]os?|reparaciones?|instalaciones?|presupuestos?)
        )/uxi',

        // Urgencia y prioridad
        'urgency' => '/(?:urgente|hoy|ma[nñ]ana|cuanto\s+antes|ya\s+mismo|r[aá]pido|
            lo\s+antes\s+posible|esta\s+semana|esta\s+tarde|urgencia|
            necesito\s+(?:hoy|urgente|ya)|sin\s+falta|pronto|enseguida)/uxi',

        // Señales de pago — muy alta intención
        'payment' => '/(?:
            tarjeta|cuotas?|mercado\s*pago|efectivo|
            transfer(?:encia)?|d[eé]bito|cr[eé]dito|QR|
            ahora\s+\d+|plan\s+de\s+cuotas?|financiaci[oó]n|
            pago\s+(?:mensual|anual|online)|
            visa|mastercard|naranja|cabal|amex
        )/uxi',

        // Contexto de calificación
        'qualification' => '/(?:
            zona|barrio|provincia|partido|localidad|c[oó]digo\s+postal|
            domicilio|direcci[oó]n\s+de\s+env[íi]o|
            soy\s+(?:de|del?)|vivo\s+en|estoy\s+en|
            empresa|local|negocio|comercio|
            cantidad|volumen|unidades?|metros?\s+cuadrados?
        )/uxi',

        // Contacto via WhatsApp
        'whatsapp' => '/(?:whatsapp|wasap|wsp|wa|por\s+wp)\s*[:=]?\s*(?:\+?54)?[\s\d\-]{8,}/ui',

        // ── BANT signals ─────────────────────────────────────────────────────

        // Budget: menciona presupuesto, monto disponible o capacidad de pago
        'bant_budget' => '/(?:
            tengo\s+(?:un\s+)?presupuesto|
            mi\s+presupuesto\s+(?:es|est[aá]|llega|alcanza)|
            puedo\s+(?:gastar|invertir|pagar|destinar)\s+(?:hasta|[\$])|
            dispongo\s+de|
            tengo\s+(?:plata|dinero|fondos)\s+(?:para|disponible)|
            son\s+[\$₹€£]?\s*\d[\d\.]*(?:\s*(?:mil|millones?|k|m))?(?:\s*(?:pesos|ars|usd|d[oó]lares?))?|
            [\$]\s*\d[\d\.]|
            \d[\d\.]*\s*(?:mil|millones?)\s*(?:de\s+)?(?:pesos?|ars)
        )/uxi',

        // Authority: decisor / propietario del negocio
        'bant_authority' => '/(?:
            soy\s+(?:el\s+|la\s+)?(?:due[nñ]o|propietario|gerente|director|
                jefe|encargado|responsable|socio|ceo|coo|administrador)|
            soy\s+quien\s+decide|yo\s+decido|estoy\s+a\s+cargo|
            trabajo\s+(?:de\s+)?(?:cuenta\s+propia|independiente|aut[oó]nomo)|
            mi\s+(?:empresa|negocio|comercio|local|estudio|consultora|taller|f[aá]brica)|
            somos\s+(?:una?\s+)?(?:empresa|pyme|comercio|f[aá]brica|taller)
        )/uxi',

        // Timeline: cuándo lo necesita
        'bant_timeline_hoy'    => '/(?:lo\s+necesito|para|quiero|es|lo\s+quiero)\s+(?:hoy|ahora|ya|esta\s+(?:tarde|noche|ma[nñ]ana))/uxi',
        'bant_timeline_urgente' => '/(?:urgente|urgencia|cuanto\s+antes|ya\s+mismo|lo\s+antes\s+posible|a\s+la\s+brevedad|sin\s+falta|en\s+el\s+d[íi]a)/uxi',
        'bant_timeline_semana'  => '/(?:esta\s+semana|en\s+(?:los\s+)?pr[oó]ximos\s+d[íi]as|a\s+fin\s+de\s+semana|esta\s+semana\s+si|r[aá]pido)/uxi',
        'bant_timeline_mes'    => '/(?:este\s+mes|pr[oó]ximo\s+mes|en\s+\d+\s+(?:d[íi]as?|semanas?)|en\s+las\s+pr[oó]ximas\s+semanas)/uxi',

        // Industry: tipo de negocio / vertical
        'bant_industry' => '/(?:
            (?:somos|soy|es\s+una?|tenemos\s+una?|trabajo\s+en|rubro)\s+
            (inmobili(?:aria|ario)|ferrer[íi]a|metal[úu]rgica|construcci[oó]n|gastronom[íi]a|
             restaurant(?:e)?|hotel|bar|caf[eé]|m[eé]dic(?:o|a)|odontolog[íi]a|
             cl[íi]nica|farmacia|veterinaria|transporte|log[íi]stica|
             contabilidad|estudio\s+contable|abogad(?:o|a)|estudio\s+jur[íi]dico|
             f[aá]brica|manufactura|agr[íi]cola|agropecuario|tecnolog[íi]a|software|
             moda|indumentaria|calzado|joyer[íi]a|automotor|concesionaria|
             taller\s+mec[aá]nico|educaci[oó]n|academia|instituto|
             limpieza|seguridad|eventos|decoraci[oó]n|dise[nñ]o|
             flete|mudanza|pintura|plomer[íi]a|electricidad|cerrajer[íi]a)
        )/uxi',

        // Location: ciudad, provincia o zona mencionada
        'bant_location' => '/(?:
            (?:estoy\s+en|soy\s+de|somos\s+de|estamos\s+en|queda\s+en|
             ubicados?\s+en|zona\s+de|en\s+el\s+barrio|en\s+la\s+ciudad\s+de)\s+
            ([A-Za-záéíóúüñÑ][A-Za-záéíóúüñÑ\s]{2,35})
        )/uxi',

        // Company name
        'bant_company' => '/(?:
            (?:mi\s+empresa|nuestro\s+negocio|el\s+local|mi\s+comercio|nuestro\s+comercio|
             la\s+empresa|el\s+negocio)\s+(?:se\s+llama|es|se\s+denomina)\s+
            ([A-Za-záéíóúüñÑ0-9][A-Za-záéíóúüñÑ0-9\s]{1,50})|
            ([A-Za-záéíóúüñÑ0-9][A-Za-záéíóúüñÑ0-9\s]{1,30})\s+
            (?:S\.?\s?A\.?|S\.?\s?R\.?\s?L\.?|S\.?\s?A\.?\s?S\.?|S\.?\s?A\.?\s?C\.?)\b
        )/uxi',
    ];

    private const HIGH_INTENT_KEYWORDS = [
        'presupuesto', 'cotización', 'cotizar', 'cuánto sale', 'cuanto sale',
        'cuánto cuesta', 'cuanto cuesta', 'cuánto me saldría', 'cuanto me saldria',
        'lo tomo', 'lo agarro', 'me lo llevo', 'cerramos', 'contratar',
        'quiero comprar', 'necesito urgente', 'cuotas', 'tarjeta',
        'tienen stock', 'hacen envíos', 'delivery', 'turno', 'agenda',
    ];

    /**
     * Analiza un mensaje y el historial de conversación.
     *
     * Retorna score 0-100, temperatura comercial (cold/warm/hot/ready),
     * señales BANT estructuradas e información PII extraída.
     *
     * @param  array<int, array{role: string, content: string}> $conversationHistory
     * @return array{
     *   extracted: array{email: string|null, phone: string|null, name: string|null, interest: string},
     *   score: int,
     *   is_qualified: bool,
     *   temperature: string,
     *   intent_signals: array{budget: bool, authority: bool, timeline: string|null, industry: string|null, location: string|null, company: string|null}
     * }
     */
    public function analyze( string $message, array $conversationHistory = [] ): array {
        $extracted = [
            'email'    => null,
            'phone'    => null,
            'name'     => null,
            'interest' => null,
        ];

        // Extracción PII del mensaje actual
        if ( preg_match( self::PATTERNS['email'], $message, $matches ) ) {
            $extracted['email'] = sanitize_email( $matches[0] );
        }

        if ( preg_match( self::PATTERNS['phone_ar'], $message, $matches ) ) {
            $cleaned = preg_replace( '/[\s\-]/', '', $matches[0] );
            if ( strlen( $cleaned ) >= 8 ) {
                $extracted['phone'] = sanitize_text_field( $cleaned );
            }
        }

        if ( preg_match( self::PATTERNS['name'], $message, $matches ) ) {
            $extracted['name'] = sanitize_text_field( trim( $matches[1] ) );
        }

        // Scoring de intención
        $score        = 0;
        $interestType = 'consulta';

        if ( preg_match( self::PATTERNS['interest_buy'], $message ) ) {
            $interestType = 'compra';
            $score       += 40;

            foreach ( self::HIGH_INTENT_KEYWORDS as $keyword ) {
                if ( mb_stripos( $message, $keyword ) !== false ) {
                    $score += 10;
                    break;
                }
            }
        } elseif ( preg_match( self::PATTERNS['interest_info'], $message ) ) {
            $interestType = 'informacion';
            $score       += 20;
        }

        if ( preg_match( self::PATTERNS['urgency'], $message ) ) {
            $score += 15;
        }

        if ( preg_match( self::PATTERNS['payment'], $message ) ) {
            $score += 20;
        }

        if ( preg_match( self::PATTERNS['qualification'], $message ) ) {
            $score += 10;
        }

        if ( $extracted['email'] ) {
            $score += 15;
        }
        if ( $extracted['phone'] ) {
            $score += 15;
        }
        if ( $extracted['name'] ) {
            $score += 5;
        }

        if ( preg_match( self::PATTERNS['whatsapp'], $message ) ) {
            $score += 20;
        }

        if ( ! empty( $conversationHistory ) ) {
            $score += $this->analyzeHistory( $conversationHistory );
        }

        $extracted['interest'] = $interestType;

        $finalScore    = min( 100, $score );
        $intentSignals = $this->extractIntentSignals( $message, $conversationHistory );

        return [
            'extracted'      => $extracted,
            'score'          => $finalScore,
            'is_qualified'   => $finalScore >= 60,
            'temperature'    => $this->calculateTemperature( $finalScore, $intentSignals ),
            'intent_signals' => $intentSignals,
        ];
    }

    /**
     * Extrae señales de intención estructuradas (BANT) del mensaje y el historial.
     * Analiza el texto completo del usuario (mensaje actual + historial) para
     * detectar presupuesto, autoridad, timeline, industria, ubicación y empresa.
     *
     * @param  array<int, array{role: string, content: string}> $conversationHistory
     * @return array{budget: bool, authority: bool, timeline: string|null, industry: string|null, location: string|null, company: string|null}
     */
    private function extractIntentSignals( string $message, array $conversationHistory ): array {
        // Construir texto completo: historial del usuario + mensaje actual
        $historicalText = '';
        if ( ! empty( $conversationHistory ) ) {
            $userMsgs       = array_filter( $conversationHistory, static fn( $m ) => 'user' === ( $m['role'] ?? '' ) );
            $historicalText = implode( ' ', array_column( $userMsgs, 'content' ) ) . ' ';
        }
        $fullText = $historicalText . $message;

        // Budget
        $budget = (bool) preg_match( self::PATTERNS['bant_budget'], $fullText );

        // Authority
        $authority = (bool) preg_match( self::PATTERNS['bant_authority'], $fullText );

        // Timeline: prioridad del más urgente al más lejano
        $timeline = null;
        if ( preg_match( self::PATTERNS['bant_timeline_hoy'], $message ) ) {
            $timeline = 'hoy';
        } elseif ( preg_match( self::PATTERNS['bant_timeline_urgente'], $message ) ) {
            $timeline = 'urgente';
        } elseif ( preg_match( self::PATTERNS['bant_timeline_semana'], $message ) ) {
            $timeline = 'esta_semana';
        } elseif ( preg_match( self::PATTERNS['bant_timeline_mes'], $fullText ) ) {
            $timeline = 'proximo_mes';
        }

        // Industry — busca en texto completo para capturar si lo mencionó antes
        $industry = null;
        if ( preg_match( self::PATTERNS['bant_industry'], $fullText, $matches ) ) {
            $industry = mb_strtolower( sanitize_text_field( trim( $matches[1] ?? '' ) ) ) ?: null;
        }

        // Location
        $location = null;
        if ( preg_match( self::PATTERNS['bant_location'], $fullText, $matches ) ) {
            $raw = sanitize_text_field( trim( $matches[1] ?? '' ) );
            // Filtrar capturas muy cortas o genéricas
            if ( mb_strlen( $raw ) >= 3 ) {
                $location = $raw;
            }
        }

        // Company name
        $company = null;
        if ( preg_match( self::PATTERNS['bant_company'], $fullText, $matches ) ) {
            $raw = sanitize_text_field( trim( $matches[1] ?? $matches[2] ?? '' ) );
            if ( mb_strlen( $raw ) >= 2 ) {
                $company = $raw;
            }
        }

        return [
            'budget'    => $budget,
            'authority' => $authority,
            'timeline'  => $timeline,
            'industry'  => $industry,
            'location'  => $location,
            'company'   => $company,
        ];
    }

    /**
     * Calcula la temperatura comercial del lead en base al score y las señales BANT.
     *
     * Escala:
     *   ready — score ≥ 85, o score ≥ 60 con presupuesto confirmado + timeline inmediato.
     *   hot   — score ≥ 60 (lead calificado, señal de compra clara).
     *   warm  — score ≥ 25 (interés presente pero no calificado aún).
     *   cold  — score < 25 (exploración inicial o sin señal).
     *
     * @param array{budget: bool, authority: bool, timeline: string|null, ...} $signals
     */
    private function calculateTemperature( int $score, array $signals ): string {
        // Ready: muy alto score, o calificado con budget + necesidad inmediata
        if ( $score >= 85 ) {
            return 'ready';
        }

        if (
            $score >= 60 &&
            $signals['budget'] &&
            in_array( $signals['timeline'], [ 'hoy', 'urgente' ], true )
        ) {
            return 'ready';
        }

        // Hot: lead calificado
        if ( $score >= 60 ) {
            return 'hot';
        }

        // Warm: interés presente pero aún por calificar
        if ( $score >= 25 ) {
            return 'warm';
        }

        return 'cold';
    }

    /**
     * Analiza el historial acumulado para detectar señales de intención repetida.
     * Solo analiza mensajes del usuario — el asistente no genera señales de compra.
     *
     * @param array<int, array{role: string, content: string}> $messages
     */
    private function analyzeHistory( array $messages ): int {
        $userMessages = array_filter( $messages, static fn( $m ) => 'user' === ( $m['role'] ?? '' ) );
        $allText      = implode( ' ', array_column( $userMessages, 'content' ) );
        $bonus        = 0;

        // Preguntas repetidas sobre precio — alta intención
        $priceHits = preg_match_all( '/(?:precio|presupuesto|cu[aá]nto|cotiz)/ui', $allText );
        if ( $priceHits >= 2 ) {
            $bonus += 15;
        }

        // Volvió a preguntar sobre entrega o disponibilidad
        if ( preg_match( '/(?:env[íi]o|entrega|stock|disponible|delivery)/ui', $allText ) ) {
            $bonus += 5;
        }

        // PII encontrada en historial
        if (
            preg_match( self::PATTERNS['email'], $allText ) ||
            preg_match( self::PATTERNS['phone_ar'], $allText )
        ) {
            $bonus += 10;
        }

        // Alto engagement: muchos mensajes del usuario
        if ( count( $userMessages ) >= 4 ) {
            $bonus += 5;
        }

        return min( 25, $bonus );
    }
}
