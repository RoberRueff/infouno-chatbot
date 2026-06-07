<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\TemplateVariableResolver;
use PHPUnit\Framework\TestCase;

final class TemplateVariableResolverTest extends TestCase {

    public function test_resolve_maps_schema_keys_to_context_values(): void {
        $schema  = [ [ 'key' => 'customer_name' ], [ 'key' => 'product' ] ];
        $context = [ 'customer_name' => 'Juan', 'product' => 'Plan Pro' ];

        $resolver  = new TemplateVariableResolver();
        $resolved  = $resolver->resolve( $schema, $context );

        $this->assertSame( [ 'Juan', 'Plan Pro' ], $resolved );
    }

    public function test_missing_context_key_defaults_to_empty_string(): void {
        $schema  = [ [ 'key' => 'customer_name' ], [ 'key' => 'missing_field' ] ];
        $context = [ 'customer_name' => 'Ana' ];

        $resolver = new TemplateVariableResolver();
        $resolved = $resolver->resolve( $schema, $context );

        $this->assertSame( [ 'Ana', '' ], $resolved );
    }

    public function test_empty_schema_returns_empty_array(): void {
        $resolver = new TemplateVariableResolver();
        $this->assertSame( [], $resolver->resolve( [], [ 'name' => 'x' ] ) );
    }

    public function test_buildComponentsArray_wraps_resolved_for_graph_api(): void {
        $schema  = [ [ 'key' => 'customer_name' ] ];
        $context = [ 'customer_name' => 'Pedro' ];

        $resolver   = new TemplateVariableResolver();
        $components = $resolver->buildComponentsArray( $schema, $context );

        $this->assertSame( [
            [
                'type'       => 'body',
                'parameters' => [
                    [ 'type' => 'text', 'text' => 'Pedro' ],
                ],
            ],
        ], $components );
    }
}
