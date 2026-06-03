<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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
    public string $prefix       = 'wp_';
    public int    $insert_id    = 0;
    public mixed  $stub_get_row = null;
    public mixed  $stub_get_var = null;

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

    public function insert( string $table, array $data, array $formats = [] ): int|false {
        $this->insert_id = 1;
        return 1;
    }

    public function update( string $table, array $data, array $where, array $formats = [], array $whereFormats = [] ): int|false {
        return 1;
    }
}

$GLOBALS['wpdb'] = new WpdbStub();
