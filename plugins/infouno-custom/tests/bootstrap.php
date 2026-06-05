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

/**
 * Stub global $wpdb para tests que necesiten LeadService/LeadRepository.
 * Configurable por test via $GLOBALS['wpdb']->stubReturn.
 */
class WpdbStub {
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
        return $this->stub_get_row;
    }

    public function get_var( string $query ): mixed {
        return $this->stub_get_var;
    }

    public function get_results( string $query, string $output = 'ARRAY_A' ): mixed {
        return $this->stub_get_results;
    }

    public function insert( string $table, array $data, array $formats = [] ): int|false {
        $this->insert_id = 1;
        return 1;
    }

    public function update( string $table, array $data, array $where, array $formats = [], array $whereFormats = [] ): int|false {
        return 1;
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
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type, int $gmt = 0 ): string {
        return gmdate( 'Y-m-d H:i:s' );
    }
}

$GLOBALS['wpdb'] = new WpdbStub();
