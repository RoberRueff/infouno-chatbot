<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel\Http;

/**
 * Cliente HTTP mínimo para llamadas salientes a APIs de canales.
 * Permite inyectar un fake en tests sin tocar la red.
 */
interface ChannelHttpClient {
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>  $body     Se envía como JSON.
     * @return array{code:int,body:string}
     */
    public function postJson( string $url, array $headers, array $body ): array;
}
