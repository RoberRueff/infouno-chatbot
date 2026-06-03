<?php

declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Lead;

use Infouno\SaaS\Lead\LeadScorer;
use PHPUnit\Framework\TestCase;

/**
 * Tests del LeadScorer — motor de calificación de leads para PyMEs argentinas.
 *
 * Cobertura:
 *   - Estructura del resultado (contrato de la API pública).
 *   - Puntuación por tipo de intención (compra, informativa).
 *   - Bonos por señales de alta intención, urgencia, pago.
 *   - Extracción de PII (email, teléfono, nombre).
 *   - Temperatura comercial (cold/warm/hot/ready).
 *   - Señales BANT estructuradas.
 *   - Bonus de historial.
 *   - Techo de score en 100.
 */
final class LeadScorerTest extends TestCase {

    private LeadScorer $scorer;

    protected function setUp(): void {
        $this->scorer = new LeadScorer();
    }

    // ── Estructura del resultado ──────────────────────────────────────────────

    public function test_analyze_returns_expected_keys(): void {
        $result = $this->scorer->analyze( 'hola' );

        $this->assertArrayHasKey( 'extracted',      $result );
        $this->assertArrayHasKey( 'score',          $result );
        $this->assertArrayHasKey( 'is_qualified',   $result );
        $this->assertArrayHasKey( 'temperature',    $result );
        $this->assertArrayHasKey( 'intent_signals', $result );
    }

    public function test_extracted_has_all_pii_keys(): void {
        $result = $this->scorer->analyze( 'hola' );

        $this->assertArrayHasKey( 'email',    $result['extracted'] );
        $this->assertArrayHasKey( 'phone',    $result['extracted'] );
        $this->assertArrayHasKey( 'name',     $result['extracted'] );
        $this->assertArrayHasKey( 'interest', $result['extracted'] );
    }

    public function test_neutral_message_scores_zero(): void {
        $result = $this->scorer->analyze( 'hola, cómo están?' );

        $this->assertSame( 0,          $result['score'] );
        $this->assertFalse(            $result['is_qualified'] );
        $this->assertSame( 'consulta', $result['extracted']['interest'] );
        $this->assertSame( 'cold',     $result['temperature'] );
    }

    // ── Tipos de intención ────────────────────────────────────────────────────

    public function test_buy_intent_scores_40(): void {
        $result = $this->scorer->analyze( 'quiero comprar una heladera' );

        $this->assertSame( 'compra', $result['extracted']['interest'] );
        $this->assertGreaterThanOrEqual( 40, $result['score'] );
    }

    public function test_info_intent_scores_20(): void {
        $result = $this->scorer->analyze( 'quisiera consultar los horarios de atención' );

        $this->assertSame( 'informacion', $result['extracted']['interest'] );
        $this->assertGreaterThanOrEqual( 20, $result['score'] );
    }

    public function test_high_intent_keyword_adds_10_bonus(): void {
        $with_keyword    = $this->scorer->analyze( 'quiero un presupuesto' );
        $without_keyword = $this->scorer->analyze( 'quiero comprar algo' );

        $this->assertGreaterThan( $without_keyword['score'], $with_keyword['score'] );
    }

    // ── Señales adicionales ───────────────────────────────────────────────────

    public function test_urgency_adds_15_points(): void {
        $with_urgency    = $this->scorer->analyze( 'quiero comprar urgente' );
        $without_urgency = $this->scorer->analyze( 'quiero comprar algo' );

        $this->assertGreaterThan( $without_urgency['score'], $with_urgency['score'] );
        $diff = $with_urgency['score'] - $without_urgency['score'];
        $this->assertSame( 15, $diff );
    }

    public function test_payment_signal_adds_20_points(): void {
        $with_payment    = $this->scorer->analyze( 'quiero comprar, acepta mercadopago?' );
        $without_payment = $this->scorer->analyze( 'quiero comprar algo' );

        $diff = $with_payment['score'] - $without_payment['score'];
        $this->assertSame( 20, $diff );
    }

    // ── Extracción de PII ─────────────────────────────────────────────────────

    public function test_email_is_extracted_and_adds_15_points(): void {
        $result = $this->scorer->analyze( 'pueden contactarme a juan@gmail.com' );

        $this->assertSame( 'juan@gmail.com', $result['extracted']['email'] );
        $this->assertGreaterThanOrEqual( 15, $result['score'] );
    }

    public function test_phone_is_extracted_and_adds_15_points(): void {
        $result = $this->scorer->analyze( 'mi número es 11 2345 6789' );

        $this->assertNotNull( $result['extracted']['phone'] );
        $this->assertGreaterThanOrEqual( 15, $result['score'] );
    }

    public function test_name_is_extracted_and_adds_5_points(): void {
        $base   = $this->scorer->analyze( 'quiero una consulta' );
        $result = $this->scorer->analyze( 'quiero una consulta, me llamo Martín' );

        $this->assertSame( 'Martín', $result['extracted']['name'] );
        $this->assertGreaterThan( $base['score'], $result['score'] );
    }

    public function test_no_pii_in_neutral_message(): void {
        $result = $this->scorer->analyze( 'hola, buen día' );

        $this->assertNull( $result['extracted']['email'] );
        $this->assertNull( $result['extracted']['phone'] );
        $this->assertNull( $result['extracted']['name'] );
    }

    // ── Temperatura comercial ─────────────────────────────────────────────────

    public function test_temperature_cold_below_25(): void {
        $result = $this->scorer->analyze( 'hola' );

        $this->assertSame( 'cold', $result['temperature'] );
    }

    public function test_temperature_warm_between_25_and_59(): void {
        // Intención informativa (+20) + urgencia (+15) = 35 → warm
        $result = $this->scorer->analyze( 'quisiera saber los horarios urgente' );

        $this->assertContains( $result['temperature'], [ 'warm', 'hot' ] );
        $this->assertGreaterThanOrEqual( 25, $result['score'] );
    }

    public function test_temperature_hot_at_60(): void {
        // Buy intent (+40) + urgency (+15) + payment (+20) = 75 → hot
        $result = $this->scorer->analyze( 'quiero comprar urgente, pago con tarjeta' );

        $this->assertSame( 'hot', $result['temperature'] );
        $this->assertTrue( $result['is_qualified'] );
    }

    public function test_temperature_ready_at_85_or_above(): void {
        // Buy (+40) + high_intent (+10) + urgency (+15) + payment (+20) + email (+15) = 100 → ready
        $result = $this->scorer->analyze( 'quiero un presupuesto urgente, pago con tarjeta, contactame a x@x.com' );

        $this->assertSame( 'ready', $result['temperature'] );
    }

    // ── Techo de score ────────────────────────────────────────────────────────

    public function test_score_never_exceeds_100(): void {
        // Mensaje con todas las señales posibles
        $msg    = 'quiero presupuesto urgente, pago tarjeta, whatsapp: 1123456789, soy el dueño, x@x.com, me llamo Juan';
        $result = $this->scorer->analyze( $msg );

        $this->assertLessThanOrEqual( 100, $result['score'] );
    }

    // ── Señales BANT ──────────────────────────────────────────────────────────

    public function test_bant_budget_detected(): void {
        $result = $this->scorer->analyze( 'mi presupuesto es de 500 mil pesos' );

        $this->assertTrue( $result['intent_signals']['budget'] );
    }

    public function test_bant_authority_detected(): void {
        $result = $this->scorer->analyze( 'soy el dueño del negocio' );

        $this->assertTrue( $result['intent_signals']['authority'] );
    }

    public function test_bant_timeline_hoy_detected(): void {
        $result = $this->scorer->analyze( 'lo necesito para hoy' );

        $this->assertSame( 'hoy', $result['intent_signals']['timeline'] );
    }

    public function test_bant_timeline_urgente_detected(): void {
        $result = $this->scorer->analyze( 'es urgente, lo necesito cuanto antes' );

        $this->assertSame( 'urgente', $result['intent_signals']['timeline'] );
    }

    public function test_bant_signals_false_for_neutral_message(): void {
        $result = $this->scorer->analyze( 'hola, cómo están?' );

        $this->assertFalse( $result['intent_signals']['budget'] );
        $this->assertFalse( $result['intent_signals']['authority'] );
        $this->assertNull(  $result['intent_signals']['timeline'] );
    }

    // ── Bonus de historial ────────────────────────────────────────────────────

    public function test_history_bonus_for_repeated_price_questions(): void {
        $history = [
            [ 'role' => 'user',      'content' => 'cuánto cuesta el servicio?' ],
            [ 'role' => 'assistant', 'content' => 'El precio varía según el plan.' ],
            [ 'role' => 'user',      'content' => 'y el presupuesto para 10 personas?' ],
        ];

        $with_history    = $this->scorer->analyze( 'quiero cotizar', $history );
        $without_history = $this->scorer->analyze( 'quiero cotizar' );

        $this->assertGreaterThan( $without_history['score'], $with_history['score'] );
    }

    public function test_history_engagement_bonus_at_4_messages(): void {
        $history = array_fill( 0, 4, [ 'role' => 'user', 'content' => 'consulta genérica' ] );

        $with_history    = $this->scorer->analyze( 'otra consulta', $history );
        $without_history = $this->scorer->analyze( 'otra consulta' );

        $this->assertGreaterThan( $without_history['score'], $with_history['score'] );
    }

    public function test_history_bonus_capped_at_25(): void {
        // Historial con máximas señales: precio repetido + PII + engagement
        $history = [
            [ 'role' => 'user', 'content' => 'precio presupuesto cotización cuánto' ],
            [ 'role' => 'user', 'content' => 'precio otra vez, mi email es test@test.com' ],
            [ 'role' => 'user', 'content' => 'presupuesto y envío stock disponible' ],
            [ 'role' => 'user', 'content' => 'cuánto cuesta todo?' ],
        ];

        $base  = $this->scorer->analyze( 'hola' );
        $result = $this->scorer->analyze( 'hola', $history );

        $bonus = $result['score'] - $base['score'];
        $this->assertLessThanOrEqual( 25, $bonus );
    }
}
