<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

interface HttpClientInterface {
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function post( string $url, array $headers, string $body ): array;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     */
    public function get( string $url, array $headers ): array;
}
