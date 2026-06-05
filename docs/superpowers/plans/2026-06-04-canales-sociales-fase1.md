# Canales Sociales — Fase 1 (Columna Vertebral + Telegram) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Habilitar la recepción y respuesta autónoma de mensajes de redes sociales reutilizando el pipeline de chat existente, validado end-to-end con Telegram.

**Architecture:** Webhook entrante → ack inmediato + dedup → job en Action Scheduler → worker que normaliza el mensaje, resuelve bot/tenant, asegura consentimiento y ejecuta `ChatPipeline` con un `BufferedSink`, devolviendo la respuesta completa por el adapter del canal. El pipeline de chat se extrae de `ChatService` a un `ChatPipeline` transport-agnostic parametrizado por `OutputSink` (streaming para web, bufferizado para canales).

**Tech Stack:** PHP 8.1+ (tipado estricto), WordPress 6.4+, MySQL 5.7+, Composer PSR-4 (`Infouno\SaaS\`), PHPUnit 11, Action Scheduler (woocommerce/action-scheduler), libsodium (nativo PHP 8.1+).

**Spec de referencia:** `docs/superpowers/specs/2026-06-04-canales-sociales-fase1-design.md`

**Rama de trabajo:** `feature/social-channels`

---

## Estructura de archivos

**Nuevos (namespace `Infouno\SaaS\Channel`):**
- `src/Channel/ChannelAdapterInterface.php` — contrato de adapter
- `src/Channel/InboundMessage.php` — value object normalizado
- `src/Channel/TelegramAdapter.php` — implementación Telegram
- `src/Channel/ChannelRegistry.php` — resuelve type → adapter
- `src/Channel/ChannelRepository.php` — CRUD + routing de canales
- `src/Channel/ChannelEventRepository.php` — idempotencia (dedup)
- `src/Channel/ChannelConsentService.php` — consentimiento por primer mensaje
- `src/Channel/InboundDispatcher.php` — worker de Action Scheduler
- `src/Channel/Http/ChannelHttpClient.php` — interfaz HTTP
- `src/Channel/Http/WpHttpClient.php` — impl con wp_remote_post
- `src/Security/CredentialVault.php` — cifrado at-rest de credenciales
- `src/Chat/OutputSink.php` — interfaz de salida del pipeline
- `src/Chat/BufferedSink.php` — sink para canales (acumula)
- `src/Chat/StreamingSink.php` — sink para web (SSE)
- `src/Chat/ChatPipeline.php` — pipeline transport-agnostic (extraído de ChatService)
- `src/Chat/PipelineContext.php` — contexto de canal del pipeline
- `src/API/ChannelWebhookController.php` — endpoint REST por canal

**Modificados:**
- `composer.json` — dependencia Action Scheduler
- `infouno-custom.php` — `INFOUNO_DB_VERSION` → `'9'`, bootstrap de Action Scheduler
- `src/Core/Migrator.php` — `DB_VERSION` → `'9'`, tablas y columnas nuevas
- `src/Chat/ChatService.php` — pasa a fachada delgada sobre `ChatPipeline`
- `src/Bot/QuotaService.php` — clave secundaria opcional en rate limit
- `src/API/RestRouter.php` — registra el webhook controller
- `src/Plugin.php` — DI de los componentes de canal + hook del worker + cron de purga
- `tests/bootstrap.php` — extensiones del `WpdbStub` para las nuevas pruebas

---

## Task 1: Dependencia Action Scheduler

**Files:**
- Modify: `plugins/infouno-custom/composer.json:6-8`
- Modify: `plugins/infouno-custom/infouno-custom.php`

- [ ] **Step 1: Agregar la dependencia a composer.json**

En `composer.json`, reemplazar el bloque `"require"`:

```json
    "require": {
        "php": ">=8.1",
        "woocommerce/action-scheduler": "^3.8"
    },
```

- [ ] **Step 2: Instalar**

Run: `cd plugins/infouno-custom && composer require woocommerce/action-scheduler:^3.8`
Expected: descarga el paquete a `vendor/woocommerce/action-scheduler`, actualiza `composer.lock`.

- [ ] **Step 3: Cargar el bootstrap de Action Scheduler**

En `infouno-custom.php`, después de `require_once __DIR__ . '/vendor/autoload.php';` (o donde se cargue el autoloader), agregar:

```php
// Action Scheduler: cola de jobs en background para procesar webhooks de canales.
$infouno_as = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $infouno_as ) ) {
    require_once $infouno_as;
}
```

- [ ] **Step 4: Verificar que el plugin carga sin error fatal**

Run: `cd plugins/infouno-custom && php -l infouno-custom.php`
Expected: `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/composer.json plugins/infouno-custom/composer.lock plugins/infouno-custom/infouno-custom.php
git commit -m "chore: agrega Action Scheduler para jobs de canales en background"
```

---

## Task 2: CredentialVault (cifrado at-rest)

Cifra las credenciales de canal de cada tenant antes de guardarlas en BD. Usa libsodium (nativo en PHP 8.1+). La clave maestra vive en `wp-config.php` como `INFOUNO_ENCRYPTION_KEY` (32 bytes en hex).

**Files:**
- Create: `plugins/infouno-custom/src/Security/CredentialVault.php`
- Test: `plugins/infouno-custom/tests/Unit/Security/CredentialVaultTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Security/CredentialVaultTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Security\CredentialVault" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Security/CredentialVaultTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Security/CredentialVault.php plugins/infouno-custom/tests/Unit/Security/CredentialVaultTest.php
git commit -m "feat: CredentialVault para cifrado at-rest de credenciales de canal"
```

---

## Task 3: Migración v8 → v9 (tablas y columnas de canal)

**Files:**
- Modify: `plugins/infouno-custom/src/Core/Migrator.php:39` (DB_VERSION)
- Modify: `plugins/infouno-custom/src/Core/Migrator.php:65-82` (run)
- Modify: `plugins/infouno-custom/infouno-custom.php` (INFOUNO_DB_VERSION)

> Esta tarea no es TDD (toca DDL idempotente que requiere MySQL real). Verificación: lint + revisión de idempotencia siguiendo el patrón existente `migrateToN` con check en `INFORMATION_SCHEMA.COLUMNS`.

- [ ] **Step 1: Subir la constante de versión en Migrator**

En `src/Core/Migrator.php`, cambiar la línea `const DB_VERSION = '8';` por:

```php
    const DB_VERSION        = '9';
```

- [ ] **Step 2: Llamar migrateTo9 y crear las tablas nuevas en run()**

En `run()`, después del bloque `migrateTo8` (línea ~65-67), agregar:

```php
        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '9', '<' ) ) {
            $this->migrateTo9( $wpdb );
        }
```

Y en el bloque de fresh install (después de `$this->createAutomationLogsTable( $wpdb, $charset );`, línea ~78), agregar:

```php
        $this->createChannelsTable( $wpdb, $charset );
        $this->createChannelEventsTable( $wpdb, $charset );
```

- [ ] **Step 3: Implementar migrateTo9 y los create* nuevos**

Agregar estos métodos a la clase `Migrator` (antes del cierre de la clase):

```php
    /**
     * Upgrade path v8 → v9 — Canales Sociales (Fase 1).
     *
     * 1. Crea wp_infouno_channels y wp_infouno_channel_events (idempotente vía dbDelta).
     * 2. Agrega channel + external_user a wp_infouno_conversations.
     * 3. Agrega channel a wp_infouno_consents.
     */
    private function migrateTo9( \wpdb $wpdb ): void {
        $charset = $wpdb->get_charset_collate();
        $this->createChannelsTable( $wpdb, $charset );
        $this->createChannelEventsTable( $wpdb, $charset );

        $tableConv = $wpdb->prefix . 'infouno_conversations';
        $this->addColumnIfMissing(
            $wpdb,
            $tableConv,
            'channel',
            "ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'web' AFTER session_id"
        );
        $this->addColumnIfMissing(
            $wpdb,
            $tableConv,
            'external_user',
            'ADD COLUMN external_user VARCHAR(191) NULL AFTER channel'
        );

        $tableConsents = $wpdb->prefix . 'infouno_consents';
        $this->addColumnIfMissing(
            $wpdb,
            $tableConsents,
            'channel',
            "ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'web' AFTER scope"
        );
    }

    /** Agrega una columna solo si no existe — idempotente. MySQL 5.7+ / MariaDB 10.3+. */
    private function addColumnIfMissing( \wpdb $wpdb, string $table, string $column, string $alterClause ): void {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                $table,
                $column
            )
        );

        if ( ! $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
            $wpdb->query( "ALTER TABLE `{$table}` {$alterClause}" );
        }
    }

    /**
     * Conexión de canal por tenant/bot. Las credenciales van CIFRADAS (CredentialVault).
     * routing_key resuelve un webhook entrante → tenant + bot.
     */
    private function createChannelsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channels';
        $sql   = "CREATE TABLE {$table} (
            id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id      INT UNSIGNED NOT NULL,
            bot_id         INT UNSIGNED NOT NULL,
            channel_type   VARCHAR(20)  NOT NULL,
            routing_key    VARCHAR(191) NOT NULL,
            credentials    TEXT         NULL,
            webhook_secret VARCHAR(64)  NULL,
            status         VARCHAR(20)  NOT NULL DEFAULT 'active',
            display_name   VARCHAR(100) NULL,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY routing_key (routing_key),
            KEY tenant_id    (tenant_id),
            KEY bot_id       (bot_id),
            KEY channel_type (channel_type)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Idempotencia de webhooks: INSERT IGNORE sobre (channel_type, external_msg_id)
     * garantiza que cada mensaje entrante se procesa una sola vez.
     */
    private function createChannelEventsTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channel_events';
        $sql   = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel_type    VARCHAR(20)     NOT NULL,
            external_msg_id VARCHAR(191)    NOT NULL,
            received_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY chan_msg (channel_type, external_msg_id),
            KEY received_at (received_at)
        ) {$charset};";

        dbDelta( $sql );
    }
```

- [ ] **Step 4: Actualizar la constante del plugin**

En `infouno-custom.php`, cambiar la definición de `INFOUNO_DB_VERSION` (actualmente `'8'`) a:

```php
define( 'INFOUNO_DB_VERSION', '9' );
```

- [ ] **Step 5: Verificar sintaxis**

Run: `cd plugins/infouno-custom && php -l src/Core/Migrator.php && php -l infouno-custom.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Core/Migrator.php plugins/infouno-custom/infouno-custom.php
git commit -m "feat: migración v9 — tablas de canales y columnas channel/external_user"
```

---

## Task 4: OutputSink + BufferedSink + StreamingSink

**Files:**
- Create: `plugins/infouno-custom/src/Chat/OutputSink.php`
- Create: `plugins/infouno-custom/src/Chat/BufferedSink.php`
- Create: `plugins/infouno-custom/src/Chat/StreamingSink.php`
- Test: `plugins/infouno-custom/tests/Unit/Chat/BufferedSinkTest.php`

- [ ] **Step 1: Write the failing test (BufferedSink)**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\BufferedSink;
use PHPUnit\Framework\TestCase;

final class BufferedSinkTest extends TestCase {

    public function test_accumulates_writes_into_buffer(): void {
        $sink = new BufferedSink();
        $sink->write( 'Hola' );
        $sink->write( ' ' );
        $sink->write( 'mundo' );
        $sink->finish();

        $this->assertSame( 'Hola mundo', $sink->getBuffer() );
    }

    public function test_never_reports_aborted(): void {
        $sink = new BufferedSink();
        $this->assertFalse( $sink->isAborted() );
    }

    public function test_empty_buffer_by_default(): void {
        $this->assertSame( '', ( new BufferedSink() )->getBuffer() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Chat/BufferedSinkTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Chat\BufferedSink" not found`.

- [ ] **Step 3: Write the interface and both implementations**

`src/Chat/OutputSink.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Abstracción del transporte de salida del pipeline de chat.
 * Permite que ChatPipeline sea agnóstico del canal: web (SSE) vs redes (bufferizado).
 */
interface OutputSink {
    /** Emite un fragmento de la respuesta del LLM. */
    public function write( string $delta ): void;

    /** ¿El cliente cortó la conexión? (web: connection_aborted; canales: siempre false). */
    public function isAborted(): bool;

    /** Cierra el stream (web: evento 'done'; canales: no-op). */
    public function finish(): void;
}
```

`src/Chat/BufferedSink.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Sink para canales asíncronos: acumula la respuesta completa en memoria.
 * No hay cliente conectado, por lo que isAborted() siempre es false.
 */
final class BufferedSink implements OutputSink {

    private string $buffer = '';

    public function write( string $delta ): void {
        $this->buffer .= $delta;
    }

    public function isAborted(): bool {
        return false;
    }

    public function finish(): void {
        // no-op: el contenido se recupera con getBuffer()
    }

    public function getBuffer(): string {
        return $this->buffer;
    }
}
```

`src/Chat/StreamingSink.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Sink para el widget web: emite cada fragmento como evento SSE y respeta
 * la desconexión del cliente. Equivale al callback $onChunk del ChatController.
 *
 * @param callable $emit fn(string $delta): void — escribe el delta como SSE.
 */
final class StreamingSink implements OutputSink {

    /** @var callable */
    private $emit;

    public function __construct( callable $emit ) {
        $this->emit = $emit;
    }

    public function write( string $delta ): void {
        if ( $this->isAborted() ) {
            return;
        }
        ( $this->emit )( $delta );
    }

    public function isAborted(): bool {
        return function_exists( 'connection_aborted' ) && 1 === connection_aborted();
    }

    public function finish(): void {
        // El evento 'done' lo emite ChatController tras retornar el pipeline.
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Chat/BufferedSinkTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Chat/OutputSink.php plugins/infouno-custom/src/Chat/BufferedSink.php plugins/infouno-custom/src/Chat/StreamingSink.php plugins/infouno-custom/tests/Unit/Chat/BufferedSinkTest.php
git commit -m "feat: OutputSink + BufferedSink + StreamingSink (transporte del pipeline)"
```

---

## Task 5: PipelineContext + extracción de ChatPipeline

Extrae las 12 etapas de `ChatService::handle()` a `ChatPipeline::run()`, parametrizado por `OutputSink` y `PipelineContext`. La validación de Origin sale del pipeline (la hace el transporte web). `ChatService` queda como fachada.

**Files:**
- Create: `plugins/infouno-custom/src/Chat/PipelineContext.php`
- Create: `plugins/infouno-custom/src/Chat/ChatPipeline.php`
- Modify: `plugins/infouno-custom/src/Chat/ChatService.php` (fachada)
- Test: `plugins/infouno-custom/tests/Unit/Chat/ChatPipelineTest.php`

- [ ] **Step 1: Write PipelineContext**

`src/Chat/PipelineContext.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Contexto de transporte para una ejecución del pipeline.
 * 'web' usa la IP como clave secundaria de rate-limit; los canales usan external_user.
 */
final class PipelineContext {

    public function __construct(
        public readonly string  $channel = 'web',
        public readonly ?string $externalUser = null,
        public readonly ?string $rateLimitSecondaryKey = null,
    ) {}

    public static function web(): self {
        return new self( 'web', null, null );
    }

    /** secondaryKey = external_user para que un usuario de canal no evada el rate limit. */
    public static function forChannel( string $channel, string $externalUser ): self {
        return new self( $channel, $externalUser, $channel . ':' . $externalUser );
    }
}
```

- [ ] **Step 2: Write the failing test (ChatPipeline preserva el comportamiento)**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Chat\BufferedSink;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\Chat\PipelineContext;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class ChatPipelineTest extends TestCase {

    private function makeBot(): array {
        return [
            'id'            => 7,
            'tenant_id'     => 3,
            'system_prompt' => 'Sos un asistente comercial.',
            'settings'      => [ 'context_window' => 10, 'max_conv_tokens' => 20000 ],
        ];
    }

    public function test_runs_pipeline_and_buffers_full_response(): void {
        $tenantManager = $this->createMock( TenantManager::class );
        $tenantManager->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tenantManager->expects( $this->once() )->method( 'incrementQuota' )->with( 3, 30 );

        $botManager = $this->createMock( BotManager::class );

        $quota = $this->createMock( QuotaService::class );
        $quota->expects( $this->once() )->method( 'checkRateLimit' )->with( 'tg:55', 'telegram:55' );
        $quota->expects( $this->once() )->method( 'increment' )->with( 'tg:55', 'telegram:55' );

        $convRepo = $this->createMock( ConversationRepository::class );
        $convRepo->method( 'getOrCreate' )->willReturn( [ 'id' => 99 ] );
        $convRepo->method( 'totalTokensForConversation' )->willReturn( 0 );
        $convRepo->method( 'getRecentMessages' )->willReturn( [] );
        $convRepo->expects( $this->once() )->method( 'saveExchange' );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $messages, $onDelta, $plan ): StreamResult {
                $onDelta( 'Hola ' );
                $onDelta( 'PyME' );
                return new StreamResult( 10, 20, 'stop' );
            }
        );

        $pipeline = new ChatPipeline( $tenantManager, $botManager, $quota, $convRepo, $llm, null );
        $sink     = new BufferedSink();

        $pipeline->run(
            $this->makeBot(),
            'tg:55',
            'Quiero info',
            $sink,
            PipelineContext::forChannel( 'telegram', '55' )
        );

        $this->assertSame( 'Hola PyME', $sink->getBuffer() );
    }

    public function test_throws_402_when_conversation_token_ceiling_reached(): void {
        $tenantManager = $this->createMock( TenantManager::class );
        $tenantManager->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );

        $quota    = $this->createMock( QuotaService::class );
        $convRepo = $this->createMock( ConversationRepository::class );
        $convRepo->method( 'getOrCreate' )->willReturn( [ 'id' => 99 ] );
        $convRepo->method( 'totalTokensForConversation' )->willReturn( 20000 );

        $pipeline = new ChatPipeline(
            $tenantManager,
            $this->createMock( BotManager::class ),
            $quota,
            $convRepo,
            $this->createMock( LLMRouter::class ),
            null
        );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 402 );

        $pipeline->run( $this->makeBot(), 'tg:55', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '55' ) );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Chat/ChatPipelineTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Chat\ChatPipeline" not found`.

- [ ] **Step 4: Write ChatPipeline (etapas extraídas de ChatService, sin la validación de Origin)**

`src/Chat/ChatPipeline.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Lead\LeadService;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use Infouno\SaaS\Security\InputGuard;
use Infouno\SaaS\Tenant\TenantManager;

/**
 * Pipeline de chat transport-agnostic. Ejecuta las etapas de validación,
 * contexto, LLM, persistencia, cuota y captura de lead, escribiendo la
 * salida en un OutputSink (SSE para web, bufferizado para canales).
 *
 * La validación de Origin NO vive aquí: es responsabilidad del transporte web
 * (ChatController::preValidate). Los canales autentican vía firma de webhook.
 */
final class ChatPipeline {

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly QuotaService           $quotaService,
        private readonly ConversationRepository $conversationRepo,
        private readonly LLMRouter              $llmRouter,
        private readonly ?LeadService           $leadService = null,
    ) {}

    /**
     * @param array<string,mixed> $bot             Bot pre-validado.
     * @param string              $conversationKey session_id (web) | "tg:<chatid>" (canal).
     * @param string              $userMessage     Mensaje del usuario.
     * @param OutputSink          $sink            Transporte de salida.
     * @param PipelineContext     $ctx             Contexto de canal.
     * @throws \RuntimeException con código HTTP semántico en validaciones fallidas.
     */
    public function run(
        array           $bot,
        string          $conversationKey,
        string          $userMessage,
        OutputSink      $sink,
        PipelineContext $ctx
    ): StreamResult {
        // 1. Validar y sanitizar el mensaje — prompt injection + longitud
        $userMessage = InputGuard::validateMessage( $userMessage );

        $tenantId = (int) $bot['tenant_id'];

        // 2. Validar tenant (estado + cuota mensual) — guardrail financiero pre-vuelo
        $tenant     = $this->tenantManager->validateForChat( $tenantId );
        $tenantPlan = $tenant['plan'] ?? 'free';

        // 3. Rate limiting por sesión + clave secundaria (IP en web, external_user en canal)
        $this->quotaService->checkRateLimit( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 4. Obtener o crear la conversación de esta clave
        $conversation = $this->conversationRepo->getOrCreate(
            $tenantId,
            (int) $bot['id'],
            $conversationKey,
            $ctx->channel,
            $ctx->externalUser
        );
        $convId     = (int) $conversation['id'];
        $windowSize = (int) ( $bot['settings']['context_window'] ?? 10 );

        // 5. Techo de tokens por conversación — guardrail token-economy.md
        $maxConvTokens = (int) ( $bot['settings']['max_conv_tokens'] ?? 20_000 );
        $usedInConv    = $this->conversationRepo->totalTokensForConversation( $convId, $tenantId );
        if ( $usedInConv >= $maxConvTokens ) {
            throw new \RuntimeException(
                'Esta conversación alcanzó su límite. Iniciá una nueva para continuar.',
                402
            );
        }

        $history  = $this->conversationRepo->getRecentMessages( $convId, $tenantId, $windowSize );
        $messages = $this->buildMessages( (string) ( $bot['system_prompt'] ?? '' ), $history, $userMessage );

        // 6. Incrementar rate limit antes del LLM (evita retry flooding)
        $this->quotaService->increment( $conversationKey, $ctx->rateLimitSecondaryKey );

        // 7. Stream al sink — acumula la respuesta completa para persistirla
        $fullResponse = '';
        $result       = $this->llmRouter->stream(
            $bot,
            $messages,
            static function ( string $delta ) use ( $sink, &$fullResponse ) {
                $fullResponse .= $delta;
                $sink->write( $delta );
            },
            $tenantPlan
        );
        $sink->finish();

        // 8. Persistir el intercambio y actualizar cuota mensual
        $this->conversationRepo->saveExchange(
            $convId,
            $userMessage,
            $fullResponse,
            $result->inputTokens,
            $result->outputTokens,
            $tenantPlan
        );
        $this->tenantManager->incrementQuota( $tenantId, $result->totalTokens() );

        // 9. Lead Engine — best-effort, nunca interrumpe el chat
        if ( null !== $this->leadService ) {
            try {
                $this->leadService->processMessage(
                    $tenantId,
                    (int) $bot['id'],
                    $conversationKey,
                    $convId,
                    $userMessage,
                    $history
                );
            } catch ( \Throwable ) {
                // silencioso
            }
        }

        return $result;
    }

    /**
     * @param array<array{role:string,content:string}> $history
     * @return array<array{role:string,content:string}>
     */
    private function buildMessages( string $systemPrompt, array $history, string $userMessage ): array {
        $messages = [];

        if ( '' !== trim( $systemPrompt ) ) {
            $messages[] = [ 'role' => 'system', 'content' => $systemPrompt ];
        }
        foreach ( $history as $msg ) {
            $messages[] = [ 'role' => $msg['role'], 'content' => $msg['content'] ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $userMessage ];

        return $messages;
    }
}
```

> **Nota sobre `getOrCreate`:** este plan agrega dos parámetros opcionales (`$channel`, `$externalUser`) a `ConversationRepository::getOrCreate()`. En la Task 5b se ajusta su firma con defaults retrocompatibles (`'web'`, `null`) y se setean las nuevas columnas al insertar.

- [ ] **Step 5: Convertir ChatService en fachada delgada**

Reemplazar el método `handle()` de `src/Chat/ChatService.php` para que delegue en `ChatPipeline` usando un `StreamingSink`, conservando la firma pública (Origin sigue validándose acá como capa 2 para el flujo web):

```php
    public function handle(
        array    $bot,
        string   $sessionId,
        string   $userMessage,
        string   $origin,
        callable $onChunk
    ): void {
        // Capa 2 CORS — defensa en profundidad del transporte web (no aplica a canales).
        if ( ! $this->botManager->validateOrigin( $bot, $origin ) ) {
            throw new \RuntimeException( 'Origen no autorizado para este bot.', 403 );
        }

        $pipeline = new ChatPipeline(
            $this->tenantManager,
            $this->botManager,
            $this->quotaService,
            $this->conversationRepo,
            $this->llmRouter,
            $this->leadService,
        );

        $pipeline->run(
            $bot,
            $sessionId,
            $userMessage,
            new StreamingSink( $onChunk ),
            PipelineContext::web()
        );
    }
```

Agregar los `use` necesarios al inicio de `ChatService.php`:

```php
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\StreamingSink;
use Infouno\SaaS\Chat\PipelineContext;
```

Eliminar el método privado `buildMessages()` de `ChatService` (ahora vive en `ChatPipeline`).

- [ ] **Step 6: Run tests (nuevo + regresión)**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Chat/ChatPipelineTest.php && ./vendor/bin/phpunit`
Expected: ChatPipelineTest PASS (2 tests); toda la suite existente sigue verde.

- [ ] **Step 7: Commit**

```bash
git add plugins/infouno-custom/src/Chat/PipelineContext.php plugins/infouno-custom/src/Chat/ChatPipeline.php plugins/infouno-custom/src/Chat/ChatService.php plugins/infouno-custom/tests/Unit/Chat/ChatPipelineTest.php
git commit -m "refactor: extrae ChatPipeline transport-agnostic; ChatService pasa a fachada"
```

---

## Task 5b: ConversationRepository.getOrCreate con channel/external_user

**Files:**
- Modify: `plugins/infouno-custom/src/Chat/ConversationRepository.php` (firma de `getOrCreate`)

> Verificación por lint + revisión; `getOrCreate` toca SQL real. Mantener retrocompatibilidad: los nuevos parámetros tienen default (`'web'`, `null`) para que el flujo web no cambie.

- [ ] **Step 1: Ajustar la firma e INSERT de getOrCreate**

Localizar `public function getOrCreate( int $tenantId, int $botId, string $sessionId )` en `ConversationRepository.php` y cambiar la firma a:

```php
    public function getOrCreate(
        int $tenantId,
        int $botId,
        string $sessionId,
        string $channel = 'web',
        ?string $externalUser = null
    ): array {
```

En el `INSERT` interno (el `$wpdb->insert( $table, [...] )` que crea la fila), agregar las dos columnas nuevas al array de datos y sus formatos:

```php
        $wpdb->insert(
            $table,
            [
                'tenant_id'     => $tenantId,
                'bot_id'        => $botId,
                'session_id'    => $sessionId,
                'channel'       => $channel,
                'external_user' => $externalUser,
                'created_at'    => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s' ]
        );
```

> Si el INSERT existente tiene otra forma exacta (p. ej. distinto orden o columnas), agregar únicamente `'channel' => $channel` y `'external_user' => $externalUser` con sus `%s`, sin alterar el resto.

- [ ] **Step 2: Verificar sintaxis**

Run: `cd plugins/infouno-custom && php -l src/Chat/ConversationRepository.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run full suite (regresión)**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit`
Expected: toda la suite verde.

- [ ] **Step 4: Commit**

```bash
git add plugins/infouno-custom/src/Chat/ConversationRepository.php
git commit -m "feat: getOrCreate soporta channel/external_user (retrocompatible)"
```

---

## Task 6: QuotaService con clave secundaria opcional

**Files:**
- Modify: `plugins/infouno-custom/src/Bot/QuotaService.php:28-45`
- Test: `plugins/infouno-custom/tests/Unit/Bot/QuotaServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Bot;

use Infouno\SaaS\Bot\QuotaService;
use PHPUnit\Framework\TestCase;

final class QuotaServiceTest extends TestCase {

    private array $store;

    protected function setUp(): void {
        $this->store = [];
        $GLOBALS['__infouno_transients'] = &$this->store;
    }

    public function test_secondary_key_limits_channel_user_after_30(): void {
        $svc = new QuotaService();

        // 30 incrementos de la capa secundaria deben agotar el límite de IP/secundaria.
        for ( $i = 0; $i < 30; $i++ ) {
            $svc->increment( 'tg:55', 'telegram:55' );
        }

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 429 );
        $svc->checkRateLimit( 'tg:55', 'telegram:55' );
    }

    public function test_null_secondary_key_uses_ip_path_without_error(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $svc = new QuotaService();

        // Web: una sola llamada no debe lanzar.
        $svc->checkRateLimit( 'web-session-abc' );
        $svc->increment( 'web-session-abc' );

        $this->assertTrue( true );
    }
}
```

> Este test requiere stubs de `get_transient`/`set_transient` en `tests/bootstrap.php`. Agregarlos en el Step 2.

- [ ] **Step 2: Agregar stubs de transients a tests/bootstrap.php**

En `tests/bootstrap.php`, antes de la línea `$GLOBALS['wpdb'] = new WpdbStub();`, agregar:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Bot/QuotaServiceTest.php`
Expected: FAIL — `checkRateLimit()`/`increment()` aún no aceptan el segundo parámetro (ArgumentCountError o que el límite no se aplica a la clave secundaria).

- [ ] **Step 4: Modificar QuotaService**

Reemplazar `checkRateLimit()` e `increment()` y agregar `resolveSecondaryKey()` en `src/Bot/QuotaService.php`:

```php
    public function checkRateLimit( string $sessionId, ?string $secondaryRaw = null ): void {
        $this->checkLayer( $this->sessionKey( $sessionId ), self::MAX_PER_SESSION );
        $this->checkLayer( $this->resolveSecondaryKey( $secondaryRaw ), self::MAX_PER_IP );
    }

    public function increment( string $sessionId, ?string $secondaryRaw = null ): void {
        $this->incrementKey( $this->sessionKey( $sessionId ) );
        $this->incrementKey( $this->resolveSecondaryKey( $secondaryRaw ) );
    }

    /**
     * Clave de la capa 2: si no se provee una clave secundaria explícita (flujo web),
     * se usa la IP real. En canales se pasa "tipo:external_user" — no hay IP del usuario.
     */
    private function resolveSecondaryKey( ?string $secondaryRaw ): string {
        if ( null === $secondaryRaw || '' === $secondaryRaw ) {
            return $this->ipKey();
        }
        return 'infouno_rl_x_' . substr( hash( 'sha256', $secondaryRaw ), 0, 16 );
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Bot/QuotaServiceTest.php && ./vendor/bin/phpunit`
Expected: QuotaServiceTest PASS (2 tests); suite completa verde.

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Bot/QuotaService.php plugins/infouno-custom/tests/Unit/Bot/QuotaServiceTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: QuotaService acepta clave secundaria (rate limit por external_user en canales)"
```

---

## Task 7: InboundMessage (value object)

**Files:**
- Create: `plugins/infouno-custom/src/Channel/InboundMessage.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/InboundMessageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class InboundMessageTest extends TestCase {

    public function test_exposes_normalized_fields(): void {
        $msg = new InboundMessage( 'telegram', '55', 'Hola', 'upd-1001' );

        $this->assertSame( 'telegram', $msg->channelType );
        $this->assertSame( '55', $msg->externalUser );
        $this->assertSame( 'Hola', $msg->text );
        $this->assertSame( 'upd-1001', $msg->externalMsgId );
    }

    public function test_conversation_key_is_channel_prefixed(): void {
        $msg = new InboundMessage( 'telegram', '55', 'Hola', 'upd-1001' );
        $this->assertSame( 'tg:55', $msg->conversationKey() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/InboundMessageTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\InboundMessage" not found`.

- [ ] **Step 3: Write implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Mensaje entrante normalizado, independiente del proveedor.
 * conversationKey() produce el session_id sintético usado por ConversationRepository.
 */
final class InboundMessage {

    /** Prefijos cortos de session_id por canal. */
    private const PREFIX = [
        'telegram'  => 'tg',
        'whatsapp'  => 'wa',
        'instagram' => 'ig',
        'messenger' => 'fb',
    ];

    public function __construct(
        public readonly string $channelType,
        public readonly string $externalUser,
        public readonly string $text,
        public readonly string $externalMsgId,
    ) {}

    public function conversationKey(): string {
        $prefix = self::PREFIX[ $this->channelType ] ?? $this->channelType;
        return $prefix . ':' . $this->externalUser;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/InboundMessageTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/InboundMessage.php plugins/infouno-custom/tests/Unit/Channel/InboundMessageTest.php
git commit -m "feat: InboundMessage value object normalizado"
```

---

## Task 8: ChannelHttpClient (interfaz + impl WP)

Abstrae las llamadas HTTP salientes para poder testear adapters sin red.

**Files:**
- Create: `plugins/infouno-custom/src/Channel/Http/ChannelHttpClient.php`
- Create: `plugins/infouno-custom/src/Channel/Http/WpHttpClient.php`

> No-TDD (la impl WP envuelve `wp_remote_post`); se ejercita vía adapters con un fake en la Task 9.

- [ ] **Step 1: Write the interface**

`src/Channel/Http/ChannelHttpClient.php`:

```php
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
```

- [ ] **Step 2: Write the WP implementation**

`src/Channel/Http/WpHttpClient.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel\Http;

/** Implementación sobre wp_remote_post. Timeout corto: el worker corre en background. */
final class WpHttpClient implements ChannelHttpClient {

    public function postJson( string $url, array $headers, array $body ): array {
        $headers['Content-Type'] = 'application/json';

        $response = wp_remote_post( $url, [
            'headers' => $headers,
            'body'    => (string) wp_json_encode( $body ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'code' => 0, 'body' => $response->get_error_message() ];
        }

        return [
            'code' => (int) wp_remote_retrieve_response_code( $response ),
            'body' => (string) wp_remote_retrieve_body( $response ),
        ];
    }
}
```

- [ ] **Step 3: Verify syntax**

Run: `cd plugins/infouno-custom && php -l src/Channel/Http/ChannelHttpClient.php && php -l src/Channel/Http/WpHttpClient.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Commit**

```bash
git add plugins/infouno-custom/src/Channel/Http/
git commit -m "feat: ChannelHttpClient (interfaz + impl wp_remote_post)"
```

---

## Task 9: ChannelAdapterInterface + TelegramAdapter

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelAdapterInterface.php`
- Create: `plugins/infouno-custom/src/Channel/TelegramAdapter.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/TelegramAdapterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\TelegramAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class TelegramAdapterTest extends TestCase {

    private function adapter( ?ChannelHttpClient $http = null ): TelegramAdapter {
        $vault = new CredentialVault( str_repeat( 'a', 64 ) );
        $http ??= new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"ok":true}' ];
            }
        };
        return new TelegramAdapter( $vault, $http );
    }

    public function test_type(): void {
        $this->assertSame( 'telegram', $this->adapter()->type() );
    }

    public function test_parse_inbound_extracts_message(): void {
        $payload = [
            'update_id' => 7001,
            'message'   => [
                'message_id' => 12,
                'chat'       => [ 'id' => 55 ],
                'text'       => 'Hola, quiero info',
            ],
        ];

        $msg = $this->adapter()->parseInbound( $payload );

        $this->assertNotNull( $msg );
        $this->assertSame( 'telegram', $msg->channelType );
        $this->assertSame( '55', $msg->externalUser );
        $this->assertSame( 'Hola, quiero info', $msg->text );
        $this->assertSame( '7001', $msg->externalMsgId );
    }

    public function test_parse_inbound_returns_null_for_non_text(): void {
        $this->assertNull( $this->adapter()->parseInbound( [ 'update_id' => 1, 'message' => [ 'chat' => [ 'id' => 5 ] ] ] ) );
        $this->assertNull( $this->adapter()->parseInbound( [ 'edited_message' => [] ] ) );
    }

    public function test_split_message_respects_4096_limit(): void {
        $long   = str_repeat( 'x', 9000 );
        $chunks = $this->adapter()->splitMessage( $long );

        $this->assertCount( 3, $chunks );
        foreach ( $chunks as $chunk ) {
            $this->assertLessThanOrEqual( 4096, strlen( $chunk ) );
        }
        $this->assertSame( $long, implode( '', $chunks ) );
    }

    public function test_verify_webhook_compares_secret_header(): void {
        $adapter = $this->adapter();
        $channel = [ 'webhook_secret' => 's3cr3t' ];

        $ok  = new \WP_REST_Request();
        $ok->set_header( 'X-Telegram-Bot-Api-Secret-Token', 's3cr3t' );
        $bad = new \WP_REST_Request();
        $bad->set_header( 'X-Telegram-Bot-Api-Secret-Token', 'wrong' );

        $this->assertTrue( $adapter->verifyWebhook( $ok, $channel ) );
        $this->assertFalse( $adapter->verifyWebhook( $bad, $channel ) );
    }

    public function test_send_posts_to_telegram_api_with_decrypted_token(): void {
        $vault = new CredentialVault( str_repeat( 'a', 64 ) );
        $http  = new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"ok":true}' ];
            }
        };
        $adapter = new TelegramAdapter( $vault, $http );

        $channel = [ 'credentials' => $vault->encryptArray( [ 'bot_token' => '123:ABC' ] ) ];
        $adapter->send( $channel, '55', 'Respuesta del bot' );

        $this->assertCount( 1, $http->calls );
        $this->assertStringContainsString( '/bot123:ABC/sendMessage', $http->calls[0]['url'] );
        $this->assertSame( 55, $http->calls[0]['body']['chat_id'] ?? null );
        $this->assertSame( 'Respuesta del bot', $http->calls[0]['body']['text'] ?? null );
    }
}
```

> Requiere un stub mínimo de `WP_REST_Request` con `set_header`/`get_header` en `tests/bootstrap.php` (Step 2).

- [ ] **Step 2: Agregar stub de WP_REST_Request a tests/bootstrap.php**

En `tests/bootstrap.php`, antes de `$GLOBALS['wpdb'] = new WpdbStub();`, agregar:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/TelegramAdapterTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\TelegramAdapter" not found`.

- [ ] **Step 4: Write the interface and TelegramAdapter**

`src/Channel/ChannelAdapterInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Contrato de un canal social. Cada proveedor (Telegram, WhatsApp, Meta)
 * implementa esta interfaz; el resto del sistema es agnóstico del proveedor.
 */
interface ChannelAdapterInterface {

    /** Identificador del canal: 'telegram' | 'whatsapp' | 'instagram' | 'messenger'. */
    public function type(): string;

    /**
     * Verifica que el webhook entrante es legítimo (firma/secreto del proveedor).
     * @param array<string,mixed> $channel Fila de wp_infouno_channels.
     */
    public function verifyWebhook( \WP_REST_Request $request, array $channel ): bool;

    /**
     * Normaliza el payload del webhook a un InboundMessage, o null si no es
     * un mensaje de texto que debamos procesar.
     * @param array<string,mixed> $payload
     */
    public function parseInbound( array $payload ): ?InboundMessage;

    /**
     * Envía un mensaje de texto al usuario del canal.
     * @param array<string,mixed> $channel Fila de wp_infouno_channels (credentials cifradas).
     */
    public function send( array $channel, string $externalUser, string $text ): void;

    /** Trocea el texto respetando el límite de caracteres del canal. @return string[] */
    public function splitMessage( string $text ): array;
}
```

`src/Channel/TelegramAdapter.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Security\CredentialVault;

/**
 * Canal Telegram (Bot API). Verifica el secret_token del webhook, normaliza
 * updates de tipo 'message' con texto, y responde vía sendMessage.
 */
final class TelegramAdapter implements ChannelAdapterInterface {

    private const MAX_CHARS = 4096;
    private const API_BASE  = 'https://api.telegram.org';

    public function __construct(
        private readonly CredentialVault    $vault,
        private readonly ChannelHttpClient  $http,
    ) {}

    public function type(): string {
        return 'telegram';
    }

    public function verifyWebhook( \WP_REST_Request $request, array $channel ): bool {
        $expected = (string) ( $channel['webhook_secret'] ?? '' );
        $got      = (string) ( $request->get_header( 'X-Telegram-Bot-Api-Secret-Token' ) ?? '' );

        return '' !== $expected && hash_equals( $expected, $got );
    }

    public function parseInbound( array $payload ): ?InboundMessage {
        $message = $payload['message'] ?? null;
        if ( ! is_array( $message ) ) {
            return null; // ignora edited_message, callback_query, etc.
        }

        $text   = $message['text'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        if ( ! is_string( $text ) || '' === trim( $text ) || null === $chatId ) {
            return null;
        }

        // update_id es único por bot — clave de idempotencia robusta.
        $externalMsgId = (string) ( $payload['update_id'] ?? $message['message_id'] ?? '' );

        return new InboundMessage( 'telegram', (string) $chatId, $text, $externalMsgId );
    }

    public function send( array $channel, string $externalUser, string $text ): void {
        $creds = $this->vault->decryptArray( (string) ( $channel['credentials'] ?? '' ) );
        $token = (string) ( $creds['bot_token'] ?? '' );
        if ( '' === $token ) {
            throw new \RuntimeException( 'Canal Telegram sin bot_token configurado.' );
        }

        $url = self::API_BASE . '/bot' . $token . '/sendMessage';

        foreach ( $this->splitMessage( $text ) as $chunk ) {
            $this->http->postJson( $url, [], [
                'chat_id' => is_numeric( $externalUser ) ? (int) $externalUser : $externalUser,
                'text'    => $chunk,
            ] );
        }
    }

    public function splitMessage( string $text ): array {
        if ( '' === $text ) {
            return [ '' ];
        }
        // mb-safe: corta por longitud de bytes segura para Telegram (4096 chars).
        $chunks = str_split( $text, self::MAX_CHARS );
        return false === $chunks ? [ $text ] : $chunks;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/TelegramAdapterTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelAdapterInterface.php plugins/infouno-custom/src/Channel/TelegramAdapter.php plugins/infouno-custom/tests/Unit/Channel/TelegramAdapterTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: ChannelAdapterInterface + TelegramAdapter (parse, verify, send, split)"
```

---

## Task 10: ChannelRegistry

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelRegistry.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase {

    private function fakeAdapter( string $type ): ChannelAdapterInterface {
        return new class( $type ) implements ChannelAdapterInterface {
            public function __construct( private string $t ) {}
            public function type(): string { return $this->t; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage { return null; }
            public function send( array $c, string $u, string $t ): void {}
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_register_and_resolve(): void {
        $registry = new ChannelRegistry();
        $registry->register( $this->fakeAdapter( 'telegram' ) );

        $this->assertTrue( $registry->has( 'telegram' ) );
        $this->assertSame( 'telegram', $registry->get( 'telegram' )->type() );
    }

    public function test_get_unknown_throws(): void {
        $this->expectException( \RuntimeException::class );
        ( new ChannelRegistry() )->get( 'tiktok' );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelRegistryTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\ChannelRegistry" not found`.

- [ ] **Step 3: Write implementation**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelRegistryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelRegistry.php plugins/infouno-custom/tests/Unit/Channel/ChannelRegistryTest.php
git commit -m "feat: ChannelRegistry (resuelve type → adapter)"
```

---

## Task 11: ChannelRepository

CRUD + routing de canales con aislamiento multitenant. Descifra credenciales al leer.

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class ChannelRepositoryTest extends TestCase {

    public function test_resolve_by_routing_key_returns_channel_with_decrypted_creds(): void {
        $vault  = new CredentialVault( str_repeat( 'a', 64 ) );
        $cipher = $vault->encryptArray( [ 'bot_token' => '123:ABC' ] );

        $GLOBALS['wpdb']->stub_get_row = [
            'id'             => 4,
            'tenant_id'      => 3,
            'bot_id'         => 7,
            'channel_type'   => 'telegram',
            'routing_key'    => 'rk_abc',
            'credentials'    => $cipher,
            'webhook_secret' => 's3cr3t',
            'status'         => 'active',
        ];

        $repo    = new ChannelRepository( $vault );
        $channel = $repo->resolveByRoutingKey( 'rk_abc' );

        $this->assertNotNull( $channel );
        $this->assertSame( 7, $channel['bot_id'] );
        $this->assertSame( '123:ABC', $channel['credentials_decrypted']['bot_token'] );
    }

    public function test_resolve_unknown_returns_null(): void {
        $GLOBALS['wpdb']->stub_get_row = null;
        $repo = new ChannelRepository( new CredentialVault( str_repeat( 'a', 64 ) ) );

        $this->assertNull( $repo->resolveByRoutingKey( 'nope' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelRepositoryTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\ChannelRepository" not found`.

- [ ] **Step 3: Write implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Security\CredentialVault;

/**
 * CRUD y routing de conexiones de canal. Toda lectura por tenant filtra tenant_id;
 * resolveByRoutingKey() es la única consulta sin tenant_id (el routing_key, único y
 * de alta entropía, ES la clave de routing público — como el public_token del bot).
 */
final class ChannelRepository {

    public function __construct( private readonly CredentialVault $vault ) {}

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channels';
    }

    /**
     * Resuelve un canal por su routing_key (presente en la URL del webhook).
     * Devuelve la fila con 'credentials_decrypted' añadido, o null.
     * @return array<string,mixed>|null
     */
    public function resolveByRoutingKey( string $routingKey ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE routing_key = %s AND status = 'active' LIMIT 1",
                $routingKey
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['credentials_decrypted'] = '' !== (string) ( $row['credentials'] ?? '' )
            ? $this->vault->decryptArray( (string) $row['credentials'] )
            : [];

        return $row;
    }

    /**
     * Crea una conexión de canal. Cifra las credenciales antes de persistir.
     * @param array<string,mixed> $credentials
     */
    public function create(
        int $tenantId,
        int $botId,
        string $channelType,
        string $routingKey,
        array $credentials,
        string $webhookSecret,
        string $displayName = ''
    ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'tenant_id'      => $tenantId,
                'bot_id'         => $botId,
                'channel_type'   => $channelType,
                'routing_key'    => $routingKey,
                'credentials'    => $this->vault->encryptArray( $credentials ),
                'webhook_secret' => $webhookSecret,
                'status'         => 'active',
                'display_name'   => $displayName,
                'created_at'     => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Lista los canales de un tenant (sin credenciales). Aislamiento multitenant.
     * @return array<int,array<string,mixed>>
     */
    public function listForTenant( int $tenantId ): array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, tenant_id, bot_id, channel_type, status, display_name, created_at
                 FROM `{$table}` WHERE tenant_id = %d ORDER BY created_at DESC",
                $tenantId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }
}
```

- [ ] **Step 4: Asegurar stub get_results y constante ARRAY_A en bootstrap**

En `tests/bootstrap.php`, dentro de `WpdbStub` agregar la propiedad y método (si no existen):

```php
    public mixed $stub_get_results = [];

    public function get_results( string $query, string $output = 'ARRAY_A' ): mixed {
        return $this->stub_get_results;
    }
```

Y asegurar la constante (antes de `$GLOBALS['wpdb'] = ...`):

```php
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type, int $gmt = 0 ): string {
        return gmdate( 'Y-m-d H:i:s' );
    }
}
```

> El `get_row` del stub ignora el segundo argumento `$output`, así que devolver el array configurado en `stub_get_row` es compatible con la llamada `ARRAY_A`.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelRepositoryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelRepository.php plugins/infouno-custom/tests/Unit/Channel/ChannelRepositoryTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: ChannelRepository (routing + CRUD con credenciales cifradas)"
```

---

## Task 12: ChannelEventRepository (idempotencia)

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelEventRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelEventRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelEventRepository;
use PHPUnit\Framework\TestCase;

final class ChannelEventRepositoryTest extends TestCase {

    public function test_first_occurrence_is_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 1; // INSERT IGNORE insertó 1 fila
        $repo = new ChannelEventRepository();

        $this->assertTrue( $repo->markIfNew( 'telegram', 'upd-1' ) );
    }

    public function test_duplicate_is_not_new(): void {
        $GLOBALS['wpdb']->stub_query_result = 0; // INSERT IGNORE no insertó (ya existía)
        $repo = new ChannelEventRepository();

        $this->assertFalse( $repo->markIfNew( 'telegram', 'upd-1' ) );
    }
}
```

- [ ] **Step 2: Extender WpdbStub con query() configurable**

En `tests/bootstrap.php`, dentro de `WpdbStub`, agregar:

```php
    public mixed $stub_query_result = 0;

    public function query( string $query ): mixed {
        return $this->stub_query_result;
    }
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelEventRepositoryTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\ChannelEventRepository" not found`.

- [ ] **Step 4: Write implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Idempotencia de webhooks. markIfNew() hace INSERT IGNORE sobre la UNIQUE
 * (channel_type, external_msg_id): devuelve true si el evento es nuevo, false
 * si ya se había recibido (retry del proveedor).
 */
final class ChannelEventRepository {

    public function markIfNew( string $channelType, string $externalMsgId ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_channel_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $affected = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (channel_type, external_msg_id, received_at)
                 VALUES (%s, %s, %s)",
                $channelType,
                $externalMsgId,
                gmdate( 'Y-m-d H:i:s' )
            )
        );

        return (int) $affected === 1;
    }

    /** Purga eventos más viejos que $days (mantenimiento via wp_cron). */
    public function purgeOlderThan( int $days = 7 ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'infouno_channel_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE received_at < %s",
                gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
            )
        );
    }
}
```

- [ ] **Step 5: Asegurar constante DAY_IN_SECONDS en bootstrap**

En `tests/bootstrap.php`, agregar (si no existe):

```php
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelEventRepositoryTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelEventRepository.php plugins/infouno-custom/tests/Unit/Channel/ChannelEventRepositoryTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: ChannelEventRepository (idempotencia via INSERT IGNORE)"
```

---

## Task 13: ChannelConsentService

Consentimiento por primer mensaje (Ley 25.326). En el primer contacto de un usuario de canal: registra evidencia en `consents` + `lead_consents` y reporta que es primer contacto (para que el dispatcher envíe la bienvenida legal).

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelConsentService.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelConsentServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelConsentService;
use PHPUnit\Framework\TestCase;

final class ChannelConsentServiceTest extends TestCase {

    public function test_first_contact_records_consent_and_returns_true(): void {
        // get_var devuelve 0 → no hay consentimiento previo.
        $GLOBALS['wpdb']->stub_get_var = 0;
        $inserts = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$inserts ) {
            $inserts[] = $table;
        };

        $svc          = new ChannelConsentService();
        $isFirst      = $svc->ensure( 3, 7, 'telegram', 'tg:55' );

        $this->assertTrue( $isFirst );
        // Debe insertar en consents y lead_consents.
        $this->assertContains( 'wp_infouno_consents', $inserts );
        $this->assertContains( 'wp_infouno_lead_consents', $inserts );
    }

    public function test_existing_consent_returns_false_and_inserts_nothing(): void {
        $GLOBALS['wpdb']->stub_get_var = 1; // ya existe consentimiento
        $inserts = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table ) use ( &$inserts ) {
            $inserts[] = $table;
        };

        $svc = new ChannelConsentService();

        $this->assertFalse( $svc->ensure( 3, 7, 'telegram', 'tg:55' ) );
        $this->assertSame( [], $inserts );
    }
}
```

- [ ] **Step 2: Extender WpdbStub para observar inserts**

En `tests/bootstrap.php`, modificar el método `insert` de `WpdbStub` para invocar un callback opcional:

```php
    /** @var callable|null */
    public $onInsert = null;

    public function insert( string $table, array $data, array $formats = [] ): int|false {
        if ( is_callable( $this->onInsert ) ) {
            ( $this->onInsert )( $table, $data );
        }
        $this->insert_id = 1;
        return 1;
    }
```

> Si el `insert` actual ya está definido sin callback, reemplazarlo por esta versión.

- [ ] **Step 3: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelConsentServiceTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\ChannelConsentService" not found`.

- [ ] **Step 4: Write implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Consentimiento por primer mensaje (Ley 25.326) para canales sociales.
 *
 * En el primer contacto de un usuario de canal registra evidencia server-side
 * en consents (uso del chat) y lead_consents (captura PII granular). El modelo
 * legal elegido es "continuar la conversación = aceptación": el dispatcher envía
 * el aviso legal + link a la política como mensaje de bienvenida.
 */
final class ChannelConsentService {

    private const CONSENT_VERSION = '1.0';

    /** Para canales el consentimiento por primer mensaje habilita los 3 campos PII. */
    private const CAPTURE_NAME  = 1;
    private const CAPTURE_PHONE = 1;
    private const CAPTURE_EMAIL = 1;

    /**
     * Asegura el consentimiento para una sesión de canal.
     * @return bool true si es primer contacto (recién registrado) — el caller debe enviar la bienvenida legal.
     */
    public function ensure( int $tenantId, int $botId, string $channel, string $conversationKey ): bool {
        global $wpdb;

        $sessionHash    = hash( 'sha256', $conversationKey );
        $consentsTable  = $wpdb->prefix . 'infouno_consents';
        $leadConsents   = $wpdb->prefix . 'infouno_lead_consents';

        // ¿Ya existe consentimiento de chat para esta sesión de canal?
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$consentsTable}`
                 WHERE session_hash = %s AND tenant_id = %d AND scope = 'chat'",
                $sessionHash,
                $tenantId
            )
        );

        if ( $existing > 0 ) {
            return false;
        }

        $now = gmdate( 'Y-m-d H:i:s' );

        // Evidencia de consentimiento de uso del chat (sin IP en canales).
        $wpdb->insert(
            $consentsTable,
            [
                'bot_id'          => $botId,
                'tenant_id'       => $tenantId,
                'session_hash'    => $sessionHash,
                'consent_version' => self::CONSENT_VERSION,
                'scope'           => 'chat',
                'channel'         => $channel,
                'ip_hash'         => '',
                'user_agent_hash' => '',
                'accepted_at'     => $now,
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        // Consentimiento granular PII (por primer mensaje).
        $wpdb->insert(
            $leadConsents,
            [
                'tenant_id'         => $tenantId,
                'bot_id'            => $botId,
                'session_hash'      => $sessionHash,
                'can_capture_name'  => self::CAPTURE_NAME,
                'can_capture_phone' => self::CAPTURE_PHONE,
                'can_capture_email' => self::CAPTURE_EMAIL,
                'consent_version'   => self::CONSENT_VERSION,
                'ip_hash'           => '',
                'user_agent_hash'   => '',
                'accepted_at'       => $now,
            ],
            [ '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ]
        );

        return true;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/ChannelConsentServiceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelConsentService.php plugins/infouno-custom/tests/Unit/Channel/ChannelConsentServiceTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: ChannelConsentService (consentimiento por primer mensaje, Ley 25.326)"
```

---

## Task 14: InboundDispatcher (worker de Action Scheduler)

Orquesta: normaliza → resuelve bot → consentimiento → pipeline → envía respuesta. Mapea errores de pipeline a mensajes amables para el usuario.

**Files:**
- Create: `plugins/infouno-custom/src/Channel/InboundDispatcher.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/InboundDispatcherTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelConsentService;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\InboundDispatcher;
use Infouno\SaaS\Channel\InboundMessage;
use Infouno\SaaS\Chat\ChatPipeline;
use PHPUnit\Framework\TestCase;

final class InboundDispatcherTest extends TestCase {

    private function adapterThatSends( array &$sent ): ChannelAdapterInterface {
        return new class( $sent ) implements ChannelAdapterInterface {
            public function __construct( private array &$sent ) {}
            public function type(): string { return 'telegram'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage {
                return new InboundMessage( 'telegram', '55', $p['message']['text'], '1' );
            }
            public function send( array $c, string $u, string $t ): void { $this->sent[] = [ $u, $t ]; }
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_happy_path_runs_pipeline_and_sends_reply(): void {
        $sent     = [];
        $adapter  = $this->adapterThatSends( $sent );
        $registry = new ChannelRegistry();
        $registry->register( $adapter );

        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKeyId' )->willReturn( [
            'id' => 4, 'tenant_id' => 3, 'bot_id' => 7, 'channel_type' => 'telegram',
            'credentials_decrypted' => [ 'bot_token' => 'x' ],
        ] );

        $consent = $this->createMock( ChannelConsentService::class );
        $consent->method( 'ensure' )->willReturn( false ); // no primer contacto

        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->expects( $this->once() )->method( 'run' )->willReturnCallback(
            function ( $bot, $key, $text, $sink ) {
                $sink->write( 'Respuesta IA' );
                $sink->finish();
                return new \Infouno\SaaS\LLM\StreamResult( 1, 1, 'stop' );
            }
        );

        $botLoader = fn( int $tid, int $bid ) => [ 'id' => 7, 'tenant_id' => 3, 'system_prompt' => 'x', 'settings' => [] ];

        $dispatcher = new InboundDispatcher( $registry, $repo, $consent, $pipeline, $botLoader );
        $dispatcher->handle( 4, [ 'message' => [ 'text' => 'Hola' ] ] );

        $this->assertSame( [ [ '55', 'Respuesta IA' ] ], $sent );
    }
}
```

> El test usa `resolveByRoutingKeyId` (resolver por id de canal, usado por el worker porque el webhook ya resolvió el routing_key y encoló el channel_id). Se agrega ese método al repositorio en el Step 3.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/InboundDispatcherTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\InboundDispatcher" not found`.

- [ ] **Step 3: Agregar resolveByRoutingKeyId al ChannelRepository**

En `src/Channel/ChannelRepository.php`, agregar:

```php
    /**
     * Resuelve un canal por su id (el worker recibe el channel_id ya resuelto).
     * @return array<string,mixed>|null
     */
    public function resolveByRoutingKeyId( int $channelId ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d AND status = 'active' LIMIT 1", $channelId ),
            ARRAY_A
        );

        if ( ! is_array( $row ) ) {
            return null;
        }

        $row['credentials_decrypted'] = '' !== (string) ( $row['credentials'] ?? '' )
            ? $this->vault->decryptArray( (string) $row['credentials'] )
            : [];

        return $row;
    }
```

- [ ] **Step 4: Write InboundDispatcher**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Chat\BufferedSink;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Chat\PipelineContext;

/**
 * Worker de Action Scheduler: procesa un mensaje entrante de canal end-to-end.
 * Normaliza → resuelve bot/tenant → asegura consentimiento → ejecuta el pipeline
 * con BufferedSink → responde por el adapter. Mapea errores a mensajes amables.
 */
final class InboundDispatcher {

    /** Mensajes de fallback por código HTTP del pipeline (sin reintento). */
    private const FALLBACK = [
        402 => 'Alcanzaste el límite de esta conversación. Escribinos más tarde, ¡gracias!',
        403 => 'No pudimos procesar tu mensaje en este momento.',
        422 => 'No puedo responder a eso. ¿Te ayudo con algo sobre nuestros productos o servicios?',
        429 => 'Estás escribiendo muy rápido 🙂 Esperá unos segundos e intentá de nuevo.',
        503 => 'El servicio no está disponible por unos minutos. Probá de nuevo en un ratito.',
    ];

    private const WELCOME = "👋 ¡Hola! Te responde un asistente automático. "
        . "Al continuar, aceptás nuestra política de privacidad y el tratamiento de tus datos "
        . "según la Ley 25.326. Podés pedir la baja en cualquier momento.";

    /** @var callable fn(int $tenantId, int $botId): ?array<string,mixed> */
    private $botLoader;

    public function __construct(
        private readonly ChannelRegistry       $registry,
        private readonly ChannelRepository     $channelRepo,
        private readonly ChannelConsentService $consent,
        private readonly ChatPipeline          $pipeline,
        callable                               $botLoader,
    ) {
        $this->botLoader = $botLoader;
    }

    /**
     * Handler del job. Firma compatible con Action Scheduler (args posicionales).
     * @param array<string,mixed> $payload Payload crudo del webhook.
     */
    public function handle( int $channelId, array $payload ): void {
        $channel = $this->channelRepo->resolveByRoutingKeyId( $channelId );
        if ( null === $channel ) {
            return; // canal eliminado/desactivado entre el ack y el worker
        }

        $adapter = $this->registry->get( (string) $channel['channel_type'] );
        $inbound = $adapter->parseInbound( $payload );
        if ( null === $inbound ) {
            return; // no era un mensaje de texto procesable
        }

        $tenantId = (int) $channel['tenant_id'];
        $botId    = (int) $channel['bot_id'];
        $bot      = ( $this->botLoader )( $tenantId, $botId );
        if ( null === $bot ) {
            return;
        }

        // Consentimiento por primer mensaje: si es primer contacto, enviar bienvenida legal.
        $isFirstContact = $this->consent->ensure( $tenantId, $botId, $inbound->channelType, $inbound->conversationKey() );
        if ( $isFirstContact ) {
            $this->trySend( $adapter, $channel, $inbound->externalUser, self::WELCOME );
        }

        $sink = new BufferedSink();
        try {
            $this->pipeline->run(
                $bot,
                $inbound->conversationKey(),
                $inbound->text,
                $sink,
                PipelineContext::forChannel( $inbound->channelType, $inbound->externalUser )
            );
        } catch ( \RuntimeException $e ) {
            // Errores de negocio (cuota, rate limit, input): fallback amable, sin reintento.
            $msg = self::FALLBACK[ $e->getCode() ] ?? 'Ocurrió un problema. Probá de nuevo en un momento.';
            $this->trySend( $adapter, $channel, $inbound->externalUser, $msg );
            return;
        }
        // Errores transitorios (LLM 5xx/red) son \Throwable distinto de RuntimeException con código:
        // se dejan propagar para que Action Scheduler reintente con backoff.

        $reply = $sink->getBuffer();
        if ( '' !== trim( $reply ) ) {
            $adapter->send( $channel, $inbound->externalUser, $reply );
        }
    }

    private function trySend( ChannelAdapterInterface $adapter, array $channel, string $user, string $text ): void {
        try {
            $adapter->send( $channel, $user, $text );
        } catch ( \Throwable $e ) {
            error_log( '[INFOUNO-CHANNEL] Falló envío de fallback/bienvenida: ' . $e->getMessage() );
        }
    }
}
```

> **Decisión de error handling:** `RuntimeException` con código semántico (lanzada por el pipeline en validaciones) = error de negocio → fallback al usuario, sin reintento. Cualquier otro `\Throwable` (red, LLM caído) se propaga → Action Scheduler reintenta con backoff. El LLMRouter ya hace su propio fallback Anthropic↔OpenAI antes de propagar.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd plugins/infouno-custom && ./vendor/bin/phpunit tests/Unit/Channel/InboundDispatcherTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/InboundDispatcher.php plugins/infouno-custom/src/Channel/ChannelRepository.php plugins/infouno-custom/tests/Unit/Channel/InboundDispatcherTest.php
git commit -m "feat: InboundDispatcher (worker: normaliza → consent → pipeline → responde)"
```

---

## Task 15: ChannelWebhookController + registro en RestRouter

Endpoint REST por canal: resuelve, verifica firma, deduplica, encola, ack 200.

**Files:**
- Create: `plugins/infouno-custom/src/API/ChannelWebhookController.php`
- Modify: `plugins/infouno-custom/src/API/RestRouter.php` (constructor + register)

> El handler usa funciones WP (`register_rest_route`, `as_enqueue_async_action`) difíciles de unit-testear; se valida vía lint + revisión. La lógica testeable (verify, parse, dedup) ya está cubierta en sus componentes.

- [ ] **Step 1: Write ChannelWebhookController**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\API;

use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;

/**
 * Endpoint de webhooks de canales: /infouno/v1/channels/{type}/{key}.
 *
 * permission_callback __return_true (igual que /chat): la autorización real es la
 * verificación de firma del canal dentro del handler. Responde 200 de inmediato y
 * delega el procesamiento a Action Scheduler para no exceder el timeout del proveedor.
 */
final class ChannelWebhookController {

    public function __construct(
        private readonly ChannelRegistry        $registry,
        private readonly ChannelRepository      $channelRepo,
        private readonly ChannelEventRepository $eventRepo,
    ) {}

    public function registerRoutes( string $namespace ): void {
        register_rest_route( $namespace, '/channels/(?P<type>[a-z]+)/(?P<key>[A-Za-z0-9_\-]+)', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        $type       = (string) $request->get_param( 'type' );
        $routingKey = (string) $request->get_param( 'key' );

        if ( ! $this->registry->has( $type ) ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $channel = $this->channelRepo->resolveByRoutingKey( $routingKey );
        if ( null === $channel || (string) $channel['channel_type'] !== $type ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $adapter = $this->registry->get( $type );

        // Verificación de firma del proveedor — autorización real del endpoint.
        if ( ! $adapter->verifyWebhook( $request, $channel ) ) {
            error_log( sprintf( '[INFOUNO-CHANNEL] Firma inválida en webhook %s (canal %d).', $type, (int) $channel['id'] ) );
            return new \WP_REST_Response( [ 'ok' => false ], 403 );
        }

        $payload = (array) $request->get_json_params();

        // Idempotencia: descartar retries del proveedor.
        $inbound = $adapter->parseInbound( $payload );
        if ( null !== $inbound && ! $this->eventRepo->markIfNew( $type, $inbound->externalMsgId ) ) {
            return new \WP_REST_Response( [ 'ok' => true, 'dup' => true ], 200 );
        }

        // Encolar el procesamiento en background. Ack inmediato al proveedor.
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action(
                'infouno_process_inbound',
                [ 'channel_id' => (int) $channel['id'], 'payload' => $payload ],
                'infouno-channels'
            );
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }
}
```

- [ ] **Step 2: Registrar el controller en RestRouter**

En `src/API/RestRouter.php`:

a) Agregar el `use` al inicio:

```php
use Infouno\SaaS\API\ChannelWebhookController;
```

b) Agregar la propiedad y el parámetro al constructor. Cambiar la firma del constructor para recibir el controller ya construido (sigue el patrón de `LeadController`/`OpportunityController`):

```php
    private ChannelWebhookController $channelWebhookController;

    public function __construct(
        private readonly TenantManager          $tenantManager,
        private readonly BotManager             $botManager,
        private readonly QuotaService           $quotaService,
        private readonly ChatService            $chatService,
        private readonly ConversationRepository $conversationRepo,
        LeadController                          $leadController,
        OpportunityController                   $opportunityController,
        ChannelWebhookController                $channelWebhookController,
    ) {
        $this->botController         = new BotController( $this->botManager, $this->tenantManager );
        $this->chatController        = new ChatController( $this->chatService, $this->botManager );
        $this->sessionController     = new SessionController( $this->botManager, $this->conversationRepo );
        $this->consentController     = new ConsentController( $this->botManager, $this->conversationRepo );
        $this->leadController        = $leadController;
        $this->opportunityController = $opportunityController;
        $this->channelWebhookController = $channelWebhookController;
    }
```

c) En `register()`, después de `$this->opportunityController->registerRoutes( self::NAMESPACE );`:

```php
        $this->channelWebhookController->registerRoutes( self::NAMESPACE );
```

- [ ] **Step 3: Verify syntax**

Run: `cd plugins/infouno-custom && php -l src/API/ChannelWebhookController.php && php -l src/API/RestRouter.php`
Expected: `No syntax errors detected` en ambos.

- [ ] **Step 4: Commit**

```bash
git add plugins/infouno-custom/src/API/ChannelWebhookController.php plugins/infouno-custom/src/API/RestRouter.php
git commit -m "feat: ChannelWebhookController (verify + dedup + ack + enqueue) y registro REST"
```

---

## Task 16: Wiring en Plugin.php + cron de purga

Cablea todos los componentes de canal en el contenedor DI, registra el adapter de Telegram, el hook del worker de Action Scheduler y el cron de purga de eventos.

**Files:**
- Modify: `plugins/infouno-custom/src/Plugin.php`

> Wiring de DI + hooks; se valida con lint + suite completa. No introduce lógica nueva testeable (ya cubierta en sus unidades).

- [ ] **Step 1: Agregar los `use` de canal**

En `src/Plugin.php`, junto a los demás `use`, agregar:

```php
use Infouno\SaaS\API\ChannelWebhookController;
use Infouno\SaaS\Channel\ChannelConsentService;
use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\Http\WpHttpClient;
use Infouno\SaaS\Channel\InboundDispatcher;
use Infouno\SaaS\Channel\TelegramAdapter;
use Infouno\SaaS\Chat\ChatPipeline;
use Infouno\SaaS\Security\CredentialVault;
```

- [ ] **Step 2: Declarar las propiedades**

En la lista de propiedades de `Plugin` (tras `private BotWizard $botWizard;`), agregar:

```php
    private ChannelRegistry        $channelRegistry;
    private ChannelRepository      $channelRepository;
    private ChannelEventRepository $channelEventRepository;
    private InboundDispatcher      $inboundDispatcher;
```

- [ ] **Step 3: Instanciar y cablear en boot()**

En `boot()`, después del bloque `// ── Chat ──` (tras crear `$this->chatService`), agregar:

```php
        // ── Canales Sociales (Fase 1) ────────────────────────────────────────
        if ( ! defined( 'INFOUNO_ENCRYPTION_KEY' ) ) {
            // Sin clave de cifrado no se pueden manejar credenciales de canal.
            $vault = null;
        } else {
            $vault = new CredentialVault( INFOUNO_ENCRYPTION_KEY );
        }

        if ( null !== $vault ) {
            $this->channelRepository      = new ChannelRepository( $vault );
            $this->channelEventRepository = new ChannelEventRepository();
            $this->channelRegistry        = new ChannelRegistry();
            $this->channelRegistry->register( new TelegramAdapter( $vault, new WpHttpClient() ) );

            $pipeline  = new ChatPipeline(
                $this->tenantManager,
                $this->botManager,
                $this->quotaService,
                $this->conversationRepo,
                $this->llmRouter,
                $this->leadService,
            );
            $botLoader = fn( int $tid, int $bid ) => $this->botManager->getById( $bid, $tid );

            $this->inboundDispatcher = new InboundDispatcher(
                $this->channelRegistry,
                $this->channelRepository,
                new ChannelConsentService(),
                $pipeline,
                $botLoader,
            );
        }
```

> **Nota:** verificar el nombre real del método de `BotManager` para cargar un bot por id+tenant. El audit lo refiere como `getById( $botId, $tenantId )` con aislamiento por tenant. Si la firma difiere, ajustar el `$botLoader` a la firma real (debe devolver el bot con `settings` ya decodificado como array, igual que recibe `ChatService::handle`).

- [ ] **Step 4: Pasar el ChannelWebhookController al RestRouter**

En `boot()`, modificar la construcción de `$this->restRouter` para incluir el nuevo controller (solo si `$vault` existe; si no, pasar uno con un registry vacío para no romper rutas):

```php
        $webhookController = new ChannelWebhookController(
            $this->channelRegistry        ?? new ChannelRegistry(),
            $this->channelRepository      ?? new ChannelRepository( new CredentialVault( str_repeat( '0', 64 ) ) ),
            $this->channelEventRepository ?? new ChannelEventRepository(),
        );

        $this->restRouter = new RestRouter(
            $this->tenantManager,
            $this->botManager,
            $this->quotaService,
            $this->chatService,
            $this->conversationRepo,
            new LeadController( $this->tenantManager ),
            new OpportunityController( $this->opportunityService, $this->opportunityRepo, $this->tenantManager ),
            $webhookController,
        );
```

> El fallback de `ChannelRepository` con clave dummy solo se usa cuando no hay `INFOUNO_ENCRYPTION_KEY`: en ese caso no hay canales activos en BD, así que `resolveByRoutingKey` devolverá null y el endpoint responderá 404. No se descifra nada real con esa clave.

- [ ] **Step 5: Registrar el hook del worker y el cron de purga**

En `boot()`, junto a los demás `add_action` (después de los hooks de Sales Automation), agregar:

```php
        // Worker de canales (Action Scheduler) — solo si la infra de canal está activa.
        if ( isset( $this->inboundDispatcher ) ) {
            add_action( 'infouno_process_inbound', function ( $args ): void {
                $channelId = (int) ( $args['channel_id'] ?? 0 );
                $payload   = (array) ( $args['payload'] ?? [] );
                if ( $channelId > 0 ) {
                    $this->inboundDispatcher->handle( $channelId, $payload );
                }
            }, 10, 1 );
        }

        // Purga de eventos de idempotencia (mantenimiento).
        add_action( 'infouno_purge_channel_events', function (): void {
            ( new ChannelEventRepository() )->purgeOlderThan( 7 );
        } );
```

> Action Scheduler invoca el hook `infouno_process_inbound` con un único argumento: el array pasado a `as_enqueue_async_action`. Por eso el closure recibe `$args` y desempaqueta `channel_id`/`payload`.

- [ ] **Step 6: Programar el cron de purga en la activación**

Localizar dónde se programan los cron existentes (`infouno_purge_expired_messages`, `infouno_reset_monthly_quotas`) — en `src/Core/Activator.php`. Agregar junto a ellos:

```php
        if ( ! wp_next_scheduled( 'infouno_purge_channel_events' ) ) {
            wp_schedule_event( time(), 'daily', 'infouno_purge_channel_events' );
        }
```

Y en `src/Core/Deactivator.php`, junto a los `wp_clear_scheduled_hook` existentes:

```php
        wp_clear_scheduled_hook( 'infouno_purge_channel_events' );
```

- [ ] **Step 7: Verify syntax y correr toda la suite**

Run: `cd plugins/infouno-custom && php -l src/Plugin.php && php -l src/Core/Activator.php && php -l src/Core/Deactivator.php && ./vendor/bin/phpunit`
Expected: sin errores de sintaxis; **toda la suite verde** (unit nuevos + regresión).

- [ ] **Step 8: Lint del proyecto**

Run: `cd plugins/infouno-custom && composer package-lint`
Expected: PSR-12 sin errores en `src/` (corregir con `composer package-format` si hace falta y re-commitear).

- [ ] **Step 9: Commit**

```bash
git add plugins/infouno-custom/src/Plugin.php plugins/infouno-custom/src/Core/Activator.php plugins/infouno-custom/src/Core/Deactivator.php
git commit -m "feat: cablea infra de canales en Plugin (DI, worker AS, cron de purga)"
```

---

## Cierre de Fase 1

Al completar las 16 tareas:
- La suite PHPUnit cubre CredentialVault, BufferedSink, ChatPipeline (regresión del refactor), QuotaService, InboundMessage, TelegramAdapter, ChannelRegistry, ChannelRepository, ChannelEventRepository, ChannelConsentService e InboundDispatcher.
- El flujo web del widget queda intacto (mismo comportamiento vía StreamingSink).
- Telegram funciona end-to-end: webhook → ack/dedup → worker → pipeline → respuesta, con consentimiento por primer mensaje y credenciales cifradas.

**Pendiente operativo (fuera de código, documentar en el README de canales):** registrar el webhook en Telegram (`setWebhook` con la URL `/infouno/v1/channels/telegram/{routing_key}` y el `secret_token`), y definir `INFOUNO_ENCRYPTION_KEY` (32 bytes hex) en `wp-config.php`.

**Próximas fases (specs separados):** Fase 2 WhatsApp Cloud API (firma X-Hub-Signature-256, ventana 24h); Fase 3 Instagram DM + Messenger (infra de webhooks de Meta compartida).
