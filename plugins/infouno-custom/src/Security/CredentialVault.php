<?php
declare(strict_types=1);

namespace Infouno\SaaS\Security;

/**
 * Cifrado simétrico autenticado (XChaCha20-Poly1305 via libsodium) para
 * credenciales de canal almacenadas en BD. La clave maestra (32 bytes) se
 * provee en hex desde wp-config.php (INFOUNO_ENCRYPTION_KEY).
 *
 * Guardrail code-quality.md: sin credenciales en texto plano en BD.
 */
final class CredentialVault {

    private string $key;

    /** @param string $keyHex 64 caracteres hex (32 bytes). */
    public function __construct( string $keyHex ) {
        $key = @hex2bin( $keyHex );
        if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
            throw new \RuntimeException( 'INFOUNO_ENCRYPTION_KEY inválida: se esperan 32 bytes en hex.' );
        }
        $this->key = $key;
    }

    public function encrypt( string $plaintext ): string {
        $nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
        return base64_encode( $nonce . $cipher );
    }

    public function decrypt( string $encoded ): string {
        $raw = base64_decode( $encoded, true );
        if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
            throw new \RuntimeException( 'Credencial cifrada inválida.' );
        }

        $nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

        $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $this->key );
        if ( false === $plain ) {
            throw new \RuntimeException( 'No se pudo descifrar la credencial (clave o datos corruptos).' );
        }

        return $plain;
    }

    /** @param array<string,mixed> $data */
    public function encryptArray( array $data ): string {
        return $this->encrypt( (string) json_encode( $data ) );
    }

    /** @return array<string,mixed> */
    public function decryptArray( string $encoded ): array {
        $decoded = json_decode( $this->decrypt( $encoded ), true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
