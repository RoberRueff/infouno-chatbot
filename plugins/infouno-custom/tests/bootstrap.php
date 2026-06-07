<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Permite a PHPUnit doblar clases declaradas `final` (sin tocar el código de
// producción). Solo afecta el runtime de tests. Debe ir antes de cargar clases.
if ( class_exists( \DG\BypassFinals::class ) ) {
    \DG\BypassFinals::enable();
}

// Constantes de WordPress usadas por las clases bajo test (no se carga WP).
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

/**
 * Stubs mínimos de funciones WordPress para tests unitarios.
 * Solo se definen las funciones que usan las clases bajo test.
 * No se carga WP — los tests son independientes del framework.
 */

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( string $email ): string {
        return strtolower( trim( $email ) );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string {
        return trim( $str );
    }
}

if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( mixed $data, int $options = 0 ): string|false {
        return json_encode( $data, $options );
    }
}

if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $hook, mixed ...$args ): void {
        // no-op: los hooks se testean en integración, no aquí
    }
}

if ( ! function_exists( 'error_log' ) ) {
    // error_log es nativa PHP — no necesita stub, pero por si acaso se redirecciona a /dev/null en tests
}

// Clase base `wpdb` vacía: WP no se carga en tests, pero varias firmas de
// producción tipan `\wpdb` (p. ej. Migrator::create*). Que WpdbStub extienda
// esta base lo hace type-compatible con esos parámetros.
if ( ! class_exists( 'wpdb' ) ) {
    class wpdb {}
}

/**
 * Stub global $wpdb para tests que necesiten LeadService/LeadRepository.
 * Configurable por test via $GLOBALS['wpdb']->stubReturn.
 */
class WpdbStub extends \wpdb {
    public string $prefix          = 'wp_';
    public int    $insert_id       = 0;
    public mixed  $stub_get_row    = null;
    public mixed  $stub_get_var    = null;
    public mixed  $stub_get_results = [];

    public function prepare( string $query, mixed ...$args ): string {
        // Sustituye placeholders para retornar query válida en assertions
        $replaced = str_replace( [ '%s', '%d' ], [ "'%s'", '%d' ], $query );
        return vsprintf( $replaced, $args );
    }

    public function get_row( string $query, string $output = 'ARRAY_A' ): mixed {
        $this->last_query = $query;
        return $this->stub_get_row;
    }

    public function get_var( string $query ): mixed {
        $this->last_query = $query;
        return $this->stub_get_var;
    }

    public function get_results( string $query, string $output = 'ARRAY_A' ): mixed {
        $this->last_query = $query;
        return $this->stub_get_results;
    }

    /** @var callable|null */
    public $onInsert = null;

    public function insert( string $table, array $data, array $formats = [] ): int|false {
        if ( is_callable( $this->onInsert ) ) {
            ( $this->onInsert )( $table, $data );
        }
        // Only set a default insert_id when no value was preset by the test.
        if ( $this->insert_id === 0 ) {
            $this->insert_id = 1;
        }
        return 1;
    }

    /** @var array<string,mixed> Datos del último update(). */
    public array $last_update_data = [];
    /** @var array<string,mixed> Cláusula WHERE del último update() — clave para verificar aislamiento por tenant. */
    public array $last_update_where = [];

    public function update( string $table, array $data, array $where, array $formats = [], array $whereFormats = [] ): int|false {
        $this->last_update_data  = $data;
        $this->last_update_where = $where;
        return 1;
    }

    public mixed  $stub_query_result = 0;
    public string $last_query        = '';
    /** Stores the last SQL sent via query() (write path). */
    public string $last_write_query  = '';

    public function query( string $query ): mixed {
        $this->last_query       = $query;
        $this->last_write_query = $query;
        return $this->stub_query_result;
    }

    public function get_charset_collate(): string {
        return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ): mixed {
        return $GLOBALS['__infouno_transients'][ $key ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, mixed $value, int $ttl = 0 ): bool {
        $GLOBALS['__infouno_transients'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, mixed $default = false ): mixed {
        return $GLOBALS['__infouno_options'][ $key ] ?? $default;
    }
}

if ( ! isset( $GLOBALS['__infouno_transients'] ) ) {
    $GLOBALS['__infouno_transients'] = [];
}

if ( ! isset( $GLOBALS['__infouno_options'] ) ) {
    $GLOBALS['__infouno_options'] = [];
}

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, mixed $value ): bool {
        $GLOBALS['__infouno_options'][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'dbDelta' ) ) {
    /** Stub de dbDelta para tests: captura el SQL en vez de tocar la BD. */
    function dbDelta( string $sql ): array {
        $GLOBALS['__infouno_dbdelta_sql'][] = $sql;
        return [];
    }
}

if ( ! isset( $GLOBALS['__infouno_dbdelta_sql'] ) ) {
    $GLOBALS['__infouno_dbdelta_sql'] = [];
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params  = [];
        private string $body   = '';

        public function set_header( string $key, string $value ): void {
            $this->headers[ strtolower( $key ) ] = $value;
        }
        public function get_header( string $key ): ?string {
            return $this->headers[ strtolower( $key ) ] ?? null;
        }
        public function set_param( string $key, mixed $value ): void {
            $this->params[ $key ] = $value;
        }
        public function get_param( string $key ): mixed {
            return $this->params[ $key ] ?? null;
        }
        public function set_body( string $body ): void {
            $this->body = $body;
        }
        public function get_body(): string {
            return $this->body;
        }
        public function get_json_params(): array {
            $decoded = json_decode( '' !== $this->body ? $this->body : '{}', true );
            return is_array( $decoded ) ? $decoded : [];
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( public mixed $data = null, public int $status = 200 ) {}
        public function get_status(): int { return $this->status; }
        public function get_data(): mixed { return $this->data; }
        public function header( string $k, string $v ): void {}
    }
}

if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $hook, callable $cb, int $priority = 10, int $args = 1 ): bool {
        return true;
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type, int $gmt = 0 ): string {
        return gmdate( 'Y-m-d H:i:s' );
    }
}

if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int {
        return $GLOBALS['__infouno_current_user_id'] ?? 1;
    }
}
if ( ! isset( $GLOBALS['__infouno_current_user_id'] ) ) {
    $GLOBALS['__infouno_current_user_id'] = 1;
}

$GLOBALS['wpdb'] = new WpdbStub();
