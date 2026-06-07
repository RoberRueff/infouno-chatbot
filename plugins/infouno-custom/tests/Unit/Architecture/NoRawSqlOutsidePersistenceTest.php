<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Guard estático: ningún archivo fuera de la capa de persistencia puede
 * contener SQL crudo ($wpdb->) salvo los que están en la ALLOWLIST de legacy.
 *
 * Cobertura del scanner:
 *   - src/API/          (todos los archivos)
 *   - src/Admin/        (todos los archivos)
 *   - src/Lead/LeadService.php          (explícito)
 *   - src/Channel/ChannelConsentService.php (explícito)
 *
 * Excluidos siempre (no forman parte del scan-set):
 *   - *Repository.php                (capa de persistencia — autorizado)
 *   - Bot/BotManager.php             (manager de datos — autorizado)
 *   - Tenant/TenantManager.php       (manager de datos — autorizado)
 *   - src/Persistence/*              (capa de persistencia — autorizado)
 *   - src/Core/Migrator.php          (DDL — autorizado)
 *   - src/Channel/WindowChecker.php  (helper de canal — fuera del scan-set)
 *
 * La ALLOWLIST cubre el legacy conocido. Se achica conforme se migra cada dominio.
 * El guard bloquea toda violación NUEVA desde el día 1.
 */
final class NoRawSqlOutsidePersistenceTest extends TestCase {

    /**
     * Archivos con $wpdb-> que son legacy conocido y aún no migrados.
     * Paths relativos a src/. Al migrar un dominio, eliminar su entrada aquí.
     *
     * Increment 1 (foundation): allowlist completa — ningún archivo migrado aún.
     * Increment 2 (Leads): se eliminan LeadController, LeadDashboard, LeadService.
     * Increment 3 (Consents): se eliminan ConsentController, ChannelConsentService.
     * Increment 4 (Opportunities): se eliminan OpportunityController, OpportunityDashboard.
     * Increment 5 (Bots): se eliminan BotController, BotDashboard, BotWizard → allowlist vacía.
     */
    private const ALLOWLIST = [
        'API/ConsentController.php',
        'API/LeadController.php',
        'API/OpportunityController.php',
        'API/BotController.php',
        'Admin/LeadDashboard.php',
        'Admin/OpportunityDashboard.php',
        'Admin/BotDashboard.php',
        'Admin/BotWizard.php',
        'Lead/LeadService.php',
        'Channel/ChannelConsentService.php',
    ];

    /**
     * Token que se busca: cualquier uso de $wpdb-> en el código PHP.
     * No se usa regex — str_contains() es suficiente y no genera falsos negativos.
     */
    private const SQL_TOKEN = '$wpdb->';

    private function srcPath(): string {
        // Desde plugins/infouno-custom/tests/Unit/Architecture/, subir a plugins/infouno-custom/src/
        return dirname( __DIR__, 3 ) . '/src';
    }

    /**
     * Construye el scan-set: archivos a revisar.
     *
     * Exactamente:
     *   - Todos los .php en src/API/ (recursivo)
     *   - Todos los .php en src/Admin/ (recursivo)
     *   - src/Lead/LeadService.php (explícito)
     *   - src/Channel/ChannelConsentService.php (explícito)
     *
     * WindowChecker.php está en src/Channel/ — fuera de API/ y Admin/ y no está
     * en los dos servicios explícitos → NO es escaneado → no produce falso positivo.
     *
     * @return array<string, string> [path_relativo_a_src => contenido]
     */
    private function buildScanSet(): array {
        $src   = $this->srcPath();
        $files = [];

        foreach ( [ 'API', 'Admin' ] as $dir ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $src . '/' . $dir, \FilesystemIterator::SKIP_DOTS )
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && 'php' === $file->getExtension() ) {
                    // Path relativo a src/ desde el pathname absoluto — robusto ante
                    // subdirectorios (p. ej. API/V2/FooController.php), no solo el basename.
                    $rel           = substr( $file->getPathname(), strlen( $src ) + 1 );
                    $files[ $rel ] = (string) file_get_contents( $file->getPathname() );
                }
            }
        }

        // Servicios explícitos fuera de API/Admin.
        $explicit = [
            'Lead/LeadService.php',
            'Channel/ChannelConsentService.php',
        ];
        foreach ( $explicit as $rel ) {
            $abs = $src . '/' . $rel;
            if ( file_exists( $abs ) ) {
                $files[ $rel ] = (string) file_get_contents( $abs );
            }
        }

        return $files;
    }

    public function test_no_raw_sql_outside_persistence_layer(): void {
        $files    = $this->buildScanSet();
        $failures = [];

        foreach ( $files as $rel => $content ) {
            // Excluir *Repository.php — son la capa de persistencia autorizada.
            if ( str_ends_with( $rel, 'Repository.php' ) ) {
                continue;
            }

            if ( str_contains( $content, self::SQL_TOKEN ) && ! in_array( $rel, self::ALLOWLIST, true ) ) {
                $failures[] = $rel;
            }
        }

        $this->assertEmpty(
            $failures,
            sprintf(
                "Los siguientes archivos contienen SQL crudo (\$wpdb->) fuera de la capa de persistencia\n" .
                "y NO están en la ALLOWLIST. Agrégalos a la allowlist (si es legacy) o mueve el SQL\n" .
                "a un Repository:\n  - %s",
                implode( "\n  - ", $failures )
            )
        );
    }

    /**
     * Self-test: el scanner detecta un archivo con $wpdb-> que NO está en la allowlist.
     * Sin esto, el guard podría pasar siempre aunque su lógica estuviera rota.
     */
    public function test_scanner_detects_unlisted_sql_usage(): void {
        $fakeContent = '<?php $wpdb->get_results("SELECT * FROM wp_leads");';
        $fakeRel     = 'API/FakeUnlistedController.php'; // no está en ALLOWLIST

        $hasToken    = str_contains( $fakeContent, self::SQL_TOKEN );
        $allowListed = in_array( $fakeRel, self::ALLOWLIST, true );

        $this->assertTrue( $hasToken, 'El scanner debe detectar $wpdb-> en el contenido.' );
        $this->assertFalse( $allowListed, 'FakeUnlistedController.php NO debe estar en la allowlist.' );

        $wouldFail = $hasToken && ! $allowListed;
        $this->assertTrue( $wouldFail, 'El guard debe marcar este archivo como violación.' );
    }

    /**
     * Self-test: un archivo en la allowlist con $wpdb-> NO se marca como violación.
     */
    public function test_scanner_allows_allowlisted_files(): void {
        $fakeContent = '<?php $wpdb->get_results("SELECT * FROM wp_leads");';
        $fakeRel     = 'API/LeadController.php'; // sí está en ALLOWLIST

        $hasToken    = str_contains( $fakeContent, self::SQL_TOKEN );
        $allowListed = in_array( $fakeRel, self::ALLOWLIST, true );

        $wouldFail = $hasToken && ! $allowListed;
        $this->assertFalse( $wouldFail, 'Un archivo en la allowlist no debe marcarse como violación.' );
    }
}
