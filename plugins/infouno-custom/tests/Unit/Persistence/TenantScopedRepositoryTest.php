<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Persistence;

use Infouno\SaaS\Persistence\MissingTenantScopeException;
use Infouno\SaaS\Persistence\TenantScopedRepository;
use PHPUnit\Framework\TestCase;

final class TenantScopedRepositoryTest extends TestCase {

    /**
     * Subclase concreta mínima para testear la clase abstracta.
     * Solo implementa table() con un nombre de tabla fake.
     */
    private function makeRepo(): TenantScopedRepository {
        return new class extends TenantScopedRepository {
            protected function table(): string {
                return 'wp_test_table';
            }

            /** Expone guardScope() como público para el test. */
            public function exposedGuardScope( int $id ): int {
                return $this->guardScope( $id );
            }
        };
    }

    public function test_guardScope_throws_on_zero(): void {
        $repo = $this->makeRepo();
        $this->expectException( MissingTenantScopeException::class );
        $repo->exposedGuardScope( 0 );
    }

    public function test_guardScope_throws_on_negative(): void {
        $repo = $this->makeRepo();
        $this->expectException( MissingTenantScopeException::class );
        $repo->exposedGuardScope( -1 );
    }

    public function test_guardScope_throws_on_large_negative(): void {
        $repo = $this->makeRepo();
        $this->expectException( MissingTenantScopeException::class );
        $repo->exposedGuardScope( -999 );
    }

    public function test_guardScope_returns_id_on_positive(): void {
        $repo = $this->makeRepo();
        $this->assertSame( 1,    $repo->exposedGuardScope( 1 ) );
        $this->assertSame( 42,   $repo->exposedGuardScope( 42 ) );
        $this->assertSame( 9999, $repo->exposedGuardScope( 9999 ) );
    }

    public function test_exception_message_contains_class_name(): void {
        $repo = $this->makeRepo();
        try {
            $repo->exposedGuardScope( 0 );
            $this->fail( 'Expected MissingTenantScopeException was not thrown.' );
        } catch ( MissingTenantScopeException $e ) {
            $this->assertStringContainsString( 'operación sin scope de tenant válido', $e->getMessage() );
        }
    }
}
