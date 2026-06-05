<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Security;

use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class CredentialVaultTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        // Clave de prueba: 32 bytes en hex (64 chars).
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    public function test_encrypt_then_decrypt_returns_original(): void {
        $plaintext = '123456:ABC-DEF_telegram-bot-token';
        $cipher    = $this->vault->encrypt( $plaintext );

        $this->assertNotSame( $plaintext, $cipher );
        $this->assertSame( $plaintext, $this->vault->decrypt( $cipher ) );
    }

    public function test_encrypt_produces_different_ciphertext_each_time(): void {
        // Nonce aleatorio → dos cifrados del mismo texto difieren.
        $a = $this->vault->encrypt( 'same' );
        $b = $this->vault->encrypt( 'same' );

        $this->assertNotSame( $a, $b );
        $this->assertSame( 'same', $this->vault->decrypt( $a ) );
        $this->assertSame( 'same', $this->vault->decrypt( $b ) );
    }

    public function test_encrypt_decrypt_array_roundtrip(): void {
        $data   = [ 'bot_token' => 'xyz', 'secret' => '987' ];
        $cipher = $this->vault->encryptArray( $data );

        $this->assertSame( $data, $this->vault->decryptArray( $cipher ) );
    }

    public function test_decrypt_tampered_ciphertext_throws(): void {
        $cipher  = $this->vault->encrypt( 'secret' );
        $tampered = substr( $cipher, 0, -4 ) . 'XXXX';

        $this->expectException( \RuntimeException::class );
        $this->vault->decrypt( $tampered );
    }
}
