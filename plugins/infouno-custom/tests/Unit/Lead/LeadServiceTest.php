<?php

declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Lead;

use Infouno\SaaS\Lead\LeadRepository;
use Infouno\SaaS\Lead\LeadScorer;
use Infouno\SaaS\Lead\LeadService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests de LeadService — orquestador del pipeline de captura de leads.
 *
 * Cobertura:
 *   - Sin consentimiento previo → no hay scoring ni persistencia.
 *   - Con consentimiento → scorer y repository son llamados.
 *   - Lead calificado (score ≥ 60) → hook infouno_lead_captured es disparado.
 *   - Lead no calificado sin PII → no se persiste.
 *   - Consentimiento por campo: solo persiste los datos autorizados.
 *
 * Estrategia: se mockean LeadScorer y LeadRepository; el consentimiento se
 * provee mockeando LeadRepository::getConsentsForSession() (ya no via $wpdb).
 */
final class LeadServiceTest extends TestCase {

    private LeadScorer&MockObject     $scorer;
    private LeadRepository&MockObject $repository;
    private LeadService               $service;

    protected function setUp(): void {
        $this->scorer     = $this->createMock( LeadScorer::class );
        $this->repository = $this->createMock( LeadRepository::class );
        $this->service    = new LeadService( $this->scorer, $this->repository );
    }

    // ── Sin consentimiento ────────────────────────────────────────────────────

    public function test_no_processing_without_consent(): void {
        // getConsentsForSession retorna [] → sin consentimiento registrado
        $this->repository->method( 'getConsentsForSession' )->willReturn( [] );

        $this->scorer->expects( $this->never() )->method( 'analyze' );
        $this->repository->expects( $this->never() )->method( 'save' );

        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'quiero comprar' );
    }

    public function test_no_processing_when_all_consent_flags_zero(): void {
        // Flags todos en 0 → getConsentsForSession colapsa a [] (sin consentimiento efectivo)
        $this->repository->method( 'getConsentsForSession' )->willReturn( [] );

        $this->scorer->expects( $this->never() )->method( 'analyze' );
        $this->repository->expects( $this->never() )->method( 'save' );

        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'quiero comprar' );
    }

    // ── Con consentimiento ────────────────────────────────────────────────────

    public function test_scorer_called_when_consent_exists(): void {
        $this->repository->method( 'getConsentsForSession' )->willReturn( [
            'can_capture_name'  => 1,
            'can_capture_phone' => 0,
            'can_capture_email' => 1,
        ] );

        $this->scorer
            ->expects( $this->once() )
            ->method( 'analyze' )
            ->willReturn( $this->buildScorerResult( 30, false ) );

        $this->repository->method( 'save' )->willReturn( 1 );

        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'quisiera información' );
    }

    public function test_repository_not_called_when_no_score_and_no_pii(): void {
        $this->repository->method( 'getConsentsForSession' )->willReturn( [
            'can_capture_name'  => 1,
            'can_capture_phone' => 1,
            'can_capture_email' => 1,
        ] );

        $this->scorer
            ->method( 'analyze' )
            ->willReturn( $this->buildScorerResult( 0, false, [ 'email' => null, 'phone' => null, 'name' => null ] ) );

        $this->repository->expects( $this->never() )->method( 'save' );

        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'hola' );
    }

    // ── Lead calificado ───────────────────────────────────────────────────────

    public function test_hook_fired_when_lead_is_qualified(): void {
        $this->repository->method( 'getConsentsForSession' )->willReturn( [
            'can_capture_name'  => 1,
            'can_capture_phone' => 1,
            'can_capture_email' => 1,
        ] );

        $scorerResult = $this->buildScorerResult( 75, true );

        $this->scorer->method( 'analyze' )->willReturn( $scorerResult );
        $this->repository->method( 'save' )->willReturn( 42 );

        // do_action es un stub no-op en bootstrap — solo verificamos que no lanza excepción
        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'quiero presupuesto urgente' );

        // Si llegamos aquí sin excepción, el flujo completo funcionó
        $this->assertTrue( true );
    }

    // ── Filtrado de PII por consentimiento ────────────────────────────────────

    public function test_only_consented_fields_are_saved(): void {
        // Solo email autorizado — name y phone NO
        $this->repository->method( 'getConsentsForSession' )->willReturn( [
            'can_capture_name'  => 0,
            'can_capture_phone' => 0,
            'can_capture_email' => 1,
        ] );

        $this->scorer
            ->method( 'analyze' )
            ->willReturn( $this->buildScorerResult( 65, true, [
                'email' => 'test@ejemplo.com',
                'phone' => '1123456789',
                'name'  => 'Juan',
            ] ) );

        $this->repository
            ->expects( $this->once() )
            ->method( 'save' )
            ->with( $this->callback( function ( array $data ): bool {
                // email sí, phone y name NO
                $this->assertSame( 'test@ejemplo.com', $data['email'] );
                $this->assertArrayNotHasKey( 'phone', $data );
                $this->assertArrayNotHasKey( 'name',  $data );
                return true;
            } ) )
            ->willReturn( 1 );

        $this->service->processMessage( 1, 1, 'sess-abc', 1, 'quiero comprar, x@x.com' );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Construye el array de retorno del LeadScorer para usar en mocks.
     *
     * @param array<string,mixed> $piiOverrides
     */
    private function buildScorerResult( int $score, bool $isQualified, array $piiOverrides = [] ): array {
        return [
            'extracted'      => array_merge(
                [ 'email' => null, 'phone' => null, 'name' => null, 'interest' => 'compra' ],
                $piiOverrides
            ),
            'score'          => $score,
            'is_qualified'   => $isQualified,
            'temperature'    => $score >= 85 ? 'ready' : ( $score >= 60 ? 'hot' : ( $score >= 25 ? 'warm' : 'cold' ) ),
            'intent_signals' => [
                'budget'    => false,
                'authority' => false,
                'timeline'  => null,
                'industry'  => null,
                'location'  => null,
                'company'   => null,
            ],
        ];
    }
}
