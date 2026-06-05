<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Bot;

use Infouno\SaaS\Bot\QuotaService;
use PHPUnit\Framework\TestCase;

final class QuotaServiceTest extends TestCase {

    private array $store;

    protected function setUp(): void {
        $this->store = [];
        $GLOBALS['__infouno_transients'] = &$this->store;
    }

    /**
     * Verifica que la clave secundaria (canal) se acumula independientemente
     * de la sesión: 30 mensajes de distintas sesiones del mismo usuario de canal
     * deben agotar el límite de la capa 2 (MAX_PER_IP = 30).
     *
     * Con el código viejo (sin resolveSecondaryKey), la capa 2 usa la IP
     * (infouno_rl_ip_*) para cada sesión distinta, por lo que los 30 incrementos
     * a distintas claves de IP nunca alcanzan el límite. Con la implementación
     * correcta, todos los incrementos comparten la misma clave derivada de
     * 'telegram:55' y el límite se alcanza.
     */
    public function test_secondary_key_limits_channel_user_after_30(): void {
        $svc = new QuotaService();

        // 30 incrementos usando distintas sesiones pero el MISMO external_user.
        // Esto simula a un usuario de canal enviando desde 30 sesiones distintas.
        for ( $i = 0; $i < 30; $i++ ) {
            // Cambiar la IP en cada iteración para que el flujo viejo (ipKey) no acumule.
            $_SERVER['REMOTE_ADDR'] = '10.0.0.' . $i;
            $svc->increment( 'tg:55:' . $i, 'telegram:55' );
        }

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 429 );
        $svc->checkRateLimit( 'tg:55:new', 'telegram:55' );
    }

    public function test_null_secondary_key_uses_ip_path_without_error(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $svc = new QuotaService();

        // Web: una sola llamada no debe lanzar.
        $svc->checkRateLimit( 'web-session-abc' );
        $svc->increment( 'web-session-abc' );

        $this->assertTrue( true );
    }
}
