<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/** Registro de adapters por tipo de canal. Punto único de extensión. */
final class ChannelRegistry {

    /** @var array<string,ChannelAdapterInterface> */
    private array $adapters = [];

    public function register( ChannelAdapterInterface $adapter ): void {
        $this->adapters[ $adapter->type() ] = $adapter;
    }

    public function has( string $type ): bool {
        return isset( $this->adapters[ $type ] );
    }

    public function get( string $type ): ChannelAdapterInterface {
        if ( ! $this->has( $type ) ) {
            throw new \RuntimeException( "Canal no soportado: {$type}", 404 );
        }
        return $this->adapters[ $type ];
    }
}
