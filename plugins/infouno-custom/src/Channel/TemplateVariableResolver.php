<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Resuelve los placeholders posicionales de un template de WhatsApp ({{1}}, {{2}}...).
 * El esquema de variables viene de channel_templates.variables_schema (JSON decodificado).
 * El contexto es un mapa de datos de la conversación/lead.
 */
final class TemplateVariableResolver {

    /**
     * Resuelve el esquema y devuelve los valores en orden posicional.
     *
     * @param  array<int,array<string,string>> $schema  Ej: [['key'=>'customer_name'], ['key'=>'product']].
     * @param  array<string,string>            $context Ej: ['customer_name' => 'Juan', 'product' => 'Pro'].
     * @return string[]
     */
    public function resolve( array $schema, array $context ): array {
        $resolved = [];
        foreach ( $schema as $varDef ) {
            $key        = (string) ( $varDef['key'] ?? '' );
            $resolved[] = (string) ( $context[ $key ] ?? '' );
        }
        return $resolved;
    }

    /**
     * Genera el array `components` listo para la Graph API de Meta.
     * Formato esperado: type=template, components=[{type:body, parameters:[{type:text,text:val}...]}].
     *
     * @param  array<int,array<string,string>> $schema
     * @param  array<string,string>            $context
     * @return array<int,array<string,mixed>>
     */
    public function buildComponentsArray( array $schema, array $context ): array {
        $values = $this->resolve( $schema, $context );
        if ( empty( $values ) ) {
            return [];
        }

        $parameters = array_map(
            fn( string $v ) => [ 'type' => 'text', 'text' => $v ],
            $values
        );

        return [
            [
                'type'       => 'body',
                'parameters' => $parameters,
            ],
        ];
    }
}
