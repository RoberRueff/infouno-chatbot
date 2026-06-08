<?php
declare(strict_types=1);

namespace Infouno\SaaS\Billing;

interface MercadoPagoClientInterface {
    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPreapproval( array $payload ): array;

    /** @return array<string,mixed> */
    public function getPreapproval( string $id ): array;

    /** @return array<string,mixed> */
    public function getPayment( string $id ): array;

    public function cancelPreapproval( string $id ): bool;
}
