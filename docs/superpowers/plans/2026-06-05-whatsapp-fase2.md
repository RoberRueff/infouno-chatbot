# Fase 2: Canal WhatsApp (Cloud API) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agregar WhatsApp como segundo canal sobre la columna vertebral multicanal, con verificación de firma de Meta, handshake GET, y respuesta a no-texto.

**Architecture:** Un `WhatsAppAdapter` que implementa `ChannelAdapterInterface` + una interfaz segregada `WebhookChallengeInterface`. Extensiones puntuales: `InboundMessage` gana `kind` (text/unsupported), `InboundDispatcher` responde a no-texto, `ChannelWebhookController` agrega ruta GET (challenge) y deja de encolar webhooks sin mensaje. Reutiliza todo el resto (pipeline, consent, dedup, worker).

**Tech Stack:** PHP 8.1+ estricto, WordPress, PHPUnit 11 (dg/bypass-finals), Docker.

**Spec:** `docs/superpowers/specs/2026-06-05-whatsapp-fase2-design.md`
**Rama:** `feature/whatsapp-channel`

## Execution Environment (CRÍTICO — todos los subagentes)

NO hay PHP/Composer local. Docker desde el repo root `/Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot`:
- PHPUnit (archivo): `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage <ruta>`
- PHPUnit (suite): `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
- Lint: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli php -l <archivo>`

`tests/bootstrap.php` ya tiene: bypass-finals, ARRAY_A, stubs WP (`get_transient`/`set_transient`/`current_time`/`do_action`/`sanitize_*`), `WP_REST_Request` (con `set_header`/`get_header`/`set_param`/`get_param`/`set_body`/`get_body`), y `WpdbStub` completo. **Agregá solo lo que falte, con guards.** La suite actual está VERDE (96 tests). Rama `feature/whatsapp-channel`.

## File Structure

**Nuevos:**
- `src/Channel/WebhookChallengeInterface.php` — contrato del handshake GET.
- `src/Channel/WhatsAppAdapter.php` — adapter de WhatsApp.

**Modificados:**
- `src/Channel/InboundMessage.php` — `kind`.
- `src/Channel/InboundDispatcher.php` — branch no-texto + constante `UNSUPPORTED`.
- `src/API/ChannelWebhookController.php` — ruta GET (challenge) + no encolar si no hay mensaje.
- `src/Plugin.php` — registra `WhatsAppAdapter`.
- `tests/bootstrap.php` — stub `WP_REST_Response` (si falta).

---

## Task 1: InboundMessage gana `kind`

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/InboundMessage.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/InboundMessageKindTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\InboundMessage;
use PHPUnit\Framework\TestCase;

final class InboundMessageKindTest extends TestCase {

    public function test_kind_defaults_to_text(): void {
        $m = new InboundMessage( 'telegram', '55', 'hola', 'u1' );
        $this->assertSame( 'text', $m->kind );
    }

    public function test_kind_can_be_unsupported(): void {
        $m = new InboundMessage( 'whatsapp', '549', '', 'wamid.X', 'unsupported' );
        $this->assertSame( 'unsupported', $m->kind );
        $this->assertSame( 'wa:549', $m->conversationKey() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/InboundMessageKindTest.php`
Expected: FAIL — el constructor no acepta el 5º argumento `kind`.

- [ ] **Step 3: Add the `kind` parameter**

En `src/Channel/InboundMessage.php`, cambiar el constructor a:

```php
    public function __construct(
        public readonly string $channelType,
        public readonly string $externalUser,
        public readonly string $text,
        public readonly string $externalMsgId,
        public readonly string $kind = 'text',
    ) {}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/InboundMessageKindTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run full suite (regresión)**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage`
Expected: suite verde (Telegram construye InboundMessage sin kind → 'text', sin romper).

- [ ] **Step 6: Commit**

```bash
cd /Users/Rober/Desktop/Proyectos/infouno-chatbot/infouno-chatbot
git add plugins/infouno-custom/src/Channel/InboundMessage.php plugins/infouno-custom/tests/Unit/Channel/InboundMessageKindTest.php
git commit -m "feat: InboundMessage gana kind (text/unsupported)"
```

---

## Task 2: WebhookChallengeInterface

**Files:**
- Create: `plugins/infouno-custom/src/Channel/WebhookChallengeInterface.php`

> Interfaz pura sin test propio (se ejercita vía WhatsAppAdapter en Task 3 y el controller en Task 5).

- [ ] **Step 1: Write the interface**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Canales que requieren un handshake de verificación GET (p.ej. Meta/WhatsApp).
 * Interfaz segregada: solo la implementan los adapters que la necesitan; el
 * webhook controller la consulta vía instanceof. Telegram no la implementa.
 */
interface WebhookChallengeInterface {

    /**
     * Verifica el GET de suscripción del proveedor.
     * @param array<string,mixed> $channel Fila de wp_infouno_channels (con credentials_decrypted).
     * @return string|null El valor a devolver tal cual (challenge) si es válido; null si no.
     */
    public function verifyChallenge( \WP_REST_Request $request, array $channel ): ?string;
}
```

- [ ] **Step 2: Verify syntax**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli php -l src/Channel/WebhookChallengeInterface.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WebhookChallengeInterface.php
git commit -m "feat: WebhookChallengeInterface (handshake GET segregado)"
```

---

## Task 3: WhatsAppAdapter

**Files:**
- Create: `plugins/infouno-custom/src/Channel/WhatsAppAdapter.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterTest.php`

> Gotcha de WhatsApp: en el GET, Meta envía `hub.mode`, `hub.verify_token`, `hub.challenge`. PHP convierte los `.` en `_` al parsear el query string, así que en WP los params se leen como `hub_mode`, `hub_verify_token`, `hub_challenge`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function adapter( ?ChannelHttpClient $http = null ): WhatsAppAdapter {
        $http ??= new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"x"}]}' ];
            }
        };
        return new WhatsAppAdapter( $this->vault, $http );
    }

    private function channel( array $creds ): array {
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    public function test_type(): void {
        $this->assertSame( 'whatsapp', $this->adapter()->type() );
    }

    public function test_parse_inbound_text(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.ABC', 'type' => 'text', 'text' => [ 'body' => 'Hola precios?' ] ] ],
        ] ] ] ] ];
        $m = $this->adapter()->parseInbound( $payload );
        $this->assertNotNull( $m );
        $this->assertSame( 'whatsapp', $m->channelType );
        $this->assertSame( '5491111', $m->externalUser );
        $this->assertSame( 'Hola precios?', $m->text );
        $this->assertSame( 'wamid.ABC', $m->externalMsgId );
        $this->assertSame( 'text', $m->kind );
    }

    public function test_parse_inbound_non_text_is_unsupported(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.AUD', 'type' => 'audio', 'audio' => [ 'id' => 'm1' ] ] ],
        ] ] ] ] ];
        $m = $this->adapter()->parseInbound( $payload );
        $this->assertNotNull( $m );
        $this->assertSame( 'unsupported', $m->kind );
        $this->assertSame( 'wamid.AUD', $m->externalMsgId );
    }

    public function test_parse_inbound_status_is_null(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'statuses' => [ [ 'id' => 'wamid.X', 'status' => 'delivered' ] ],
        ] ] ] ] ];
        $this->assertNull( $this->adapter()->parseInbound( $payload ) );
    }

    public function test_verify_webhook_hmac(): void {
        $secret = 'app-secret-123';
        $body   = '{"entry":[]}';
        $sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

        $ok = new \WP_REST_Request();
        $ok->set_body( $body );
        $ok->set_header( 'X-Hub-Signature-256', $sig );

        $bad = new \WP_REST_Request();
        $bad->set_body( $body );
        $bad->set_header( 'X-Hub-Signature-256', 'sha256=deadbeef' );

        $channel = $this->channel( [ 'app_secret' => $secret ] );
        $this->assertTrue(  $this->adapter()->verifyWebhook( $ok, $channel ) );
        $this->assertFalse( $this->adapter()->verifyWebhook( $bad, $channel ) );
    }

    public function test_verify_challenge(): void {
        $channel = $this->channel( [ 'verify_token' => 'mytoken' ] );

        $ok = new \WP_REST_Request();
        $ok->set_param( 'hub_mode', 'subscribe' );
        $ok->set_param( 'hub_verify_token', 'mytoken' );
        $ok->set_param( 'hub_challenge', 'CHALLENGE-123' );
        $this->assertSame( 'CHALLENGE-123', $this->adapter()->verifyChallenge( $ok, $channel ) );

        $bad = new \WP_REST_Request();
        $bad->set_param( 'hub_mode', 'subscribe' );
        $bad->set_param( 'hub_verify_token', 'wrong' );
        $bad->set_param( 'hub_challenge', 'CHALLENGE-123' );
        $this->assertNull( $this->adapter()->verifyChallenge( $bad, $channel ) );
    }

    public function test_send_posts_to_graph_api(): void {
        $http = new class implements ChannelHttpClient {
            public array $calls = [];
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls[] = compact( 'url', 'headers', 'body' );
                return [ 'code' => 200, 'body' => 'ok' ];
            }
        };
        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOKEN', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '5491111', 'Respuesta' );

        $this->assertCount( 1, $http->calls );
        $this->assertStringContainsString( '/PNID/messages', $http->calls[0]['url'] );
        $this->assertSame( 'Bearer TOKEN', $http->calls[0]['headers']['Authorization'] ?? null );
        $this->assertSame( 'whatsapp', $http->calls[0]['body']['messaging_product'] ?? null );
        $this->assertSame( '5491111', $http->calls[0]['body']['to'] ?? null );
        $this->assertSame( 'Respuesta', $http->calls[0]['body']['text']['body'] ?? null );
    }

    public function test_split_message_respects_4096(): void {
        $chunks = $this->adapter()->splitMessage( str_repeat( 'x', 9000 ) );
        $this->assertCount( 3, $chunks );
        $this->assertSame( str_repeat( 'x', 9000 ), implode( '', $chunks ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/WhatsAppAdapterTest.php`
Expected: FAIL con `Class "Infouno\SaaS\Channel\WhatsAppAdapter" not found`.

- [ ] **Step 3: Write WhatsAppAdapter**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Security\CredentialVault;

/**
 * Canal WhatsApp (Cloud API de Meta). Verifica la firma X-Hub-Signature-256,
 * responde el handshake GET, normaliza mensajes de texto (y marca no-texto como
 * 'unsupported'), y responde vía la Graph API.
 */
final class WhatsAppAdapter implements ChannelAdapterInterface, WebhookChallengeInterface {

    private const MAX_CHARS    = 4096;
    private const GRAPH_BASE   = 'https://graph.facebook.com/v21.0';

    public function __construct(
        private readonly CredentialVault   $vault,
        private readonly ChannelHttpClient $http,
    ) {}

    public function type(): string {
        return 'whatsapp';
    }

    public function verifyWebhook( \WP_REST_Request $request, array $channel ): bool {
        $secret = (string) ( $channel['credentials_decrypted']['app_secret'] ?? '' );
        $header = (string) ( $request->get_header( 'X-Hub-Signature-256' ) ?? '' );
        if ( '' === $secret || '' === $header ) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac( 'sha256', (string) $request->get_body(), $secret );
        return hash_equals( $expected, $header );
    }

    public function verifyChallenge( \WP_REST_Request $request, array $channel ): ?string {
        // PHP convierte los puntos del query string en guiones bajos: hub.x → hub_x.
        $mode      = (string) ( $request->get_param( 'hub_mode' ) ?? '' );
        $token     = (string) ( $request->get_param( 'hub_verify_token' ) ?? '' );
        $challenge = (string) ( $request->get_param( 'hub_challenge' ) ?? '' );
        $expected  = (string) ( $channel['credentials_decrypted']['verify_token'] ?? '' );

        if ( 'subscribe' === $mode && '' !== $expected && hash_equals( $expected, $token ) ) {
            return $challenge;
        }
        return null;
    }

    public function parseInbound( array $payload ): ?InboundMessage {
        $value   = $payload['entry'][0]['changes'][0]['value'] ?? null;
        $message = is_array( $value ) ? ( $value['messages'][0] ?? null ) : null;
        if ( ! is_array( $message ) ) {
            return null; // statuses (receipts) u otros eventos sin mensaje
        }

        $from = (string) ( $message['from'] ?? '' );
        $id   = (string) ( $message['id'] ?? '' );
        if ( '' === $from || '' === $id ) {
            return null;
        }

        if ( 'text' === ( $message['type'] ?? '' ) ) {
            $text = (string) ( $message['text']['body'] ?? '' );
            if ( '' === trim( $text ) ) {
                return null;
            }
            return new InboundMessage( 'whatsapp', $from, $text, $id, 'text' );
        }

        // audio/image/sticker/location/... → no soportado (el dispatcher pide texto).
        return new InboundMessage( 'whatsapp', $from, '', $id, 'unsupported' );
    }

    public function send( array $channel, string $externalUser, string $text ): void {
        $creds = $this->vault->decryptArray( (string) ( $channel['credentials'] ?? '' ) );
        $token = (string) ( $creds['access_token'] ?? '' );
        $pnid  = (string) ( $creds['phone_number_id'] ?? '' );
        if ( '' === $token || '' === $pnid ) {
            throw new \RuntimeException( 'Canal WhatsApp sin access_token/phone_number_id.' );
        }

        $url = self::GRAPH_BASE . '/' . $pnid . '/messages';

        foreach ( $this->splitMessage( $text ) as $chunk ) {
            $res = $this->http->postJson(
                $url,
                [ 'Authorization' => 'Bearer ' . $token ],
                [
                    'messaging_product' => 'whatsapp',
                    'to'                => $externalUser,
                    'type'              => 'text',
                    'text'              => [ 'body' => $chunk ],
                ]
            );
            $code = (int) ( $res['code'] ?? 0 );
            if ( 0 === $code || $code >= 400 ) {
                error_log( '[INFOUNO-CHANNEL] WhatsApp send falló: HTTP ' . $code );
            }
        }
    }

    public function splitMessage( string $text ): array {
        if ( '' === $text ) {
            return [ '' ];
        }
        $chunks = str_split( $text, self::MAX_CHARS );
        return false === $chunks ? [ $text ] : $chunks;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/WhatsAppAdapterTest.php`
Expected: PASS (8 tests).

- [ ] **Step 5: Run full suite + commit**

```bash
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage   # verde
git add plugins/infouno-custom/src/Channel/WhatsAppAdapter.php plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterTest.php
git commit -m "feat: WhatsAppAdapter (firma HMAC, challenge GET, parse, send Graph API)"
```

---

## Task 4: InboundDispatcher responde a no-texto

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/InboundDispatcher.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/InboundDispatcherUnsupportedTest.php`

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

final class InboundDispatcherUnsupportedTest extends TestCase {

    public function test_unsupported_message_replies_without_pipeline(): void {
        $sent    = [];
        $adapter = new class( $sent ) implements ChannelAdapterInterface {
            public function __construct( public array &$sent ) {}
            public function type(): string { return 'whatsapp'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function parseInbound( array $p ): ?InboundMessage {
                return new InboundMessage( 'whatsapp', '549', '', 'wamid.A', 'unsupported' );
            }
            public function send( array $c, string $u, string $t ): void { $this->sent[] = [ $u, $t ]; }
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
        $registry = new ChannelRegistry();
        $registry->register( $adapter );

        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKeyId' )->willReturn( [
            'id' => 4, 'tenant_id' => 3, 'bot_id' => 7, 'channel_type' => 'whatsapp', 'credentials_decrypted' => [],
        ] );

        $consent  = $this->createMock( ChannelConsentService::class );
        $pipeline = $this->createMock( ChatPipeline::class );
        $pipeline->expects( $this->never() )->method( 'run' );   // no-texto NO corre el pipeline
        $consent->expects( $this->never() )->method( 'ensure' ); // ni consentimiento

        $dispatcher = new InboundDispatcher(
            $registry, $repo, $consent, $pipeline,
            fn( int $tid, int $bid ) => [ 'id' => 7, 'tenant_id' => 3, 'settings' => [] ]
        );
        $dispatcher->handle( 4, [ 'whatever' => true ] );

        $this->assertCount( 1, $sent );
        $this->assertSame( '549', $sent[0][0] );
        $this->assertStringContainsString( 'texto', $sent[0][1] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/InboundDispatcherUnsupportedTest.php`
Expected: FAIL — hoy el dispatcher corre consent/pipeline para cualquier InboundMessage (no distingue `unsupported`), así que `pipeline->run` se invoca (viola el `never()`).

- [ ] **Step 3: Add the unsupported branch**

En `src/Channel/InboundDispatcher.php`, agregá la constante junto a `WELCOME`/`FALLBACK`:

```php
    private const UNSUPPORTED = 'Por ahora solo puedo leer mensajes de texto. ¿Me contás tu consulta por escrito? 🙂';
```

Y en `handle()`, JUSTO DESPUÉS de la línea que descarta inbound null (`if ( null === $inbound ) { return; }`) y ANTES de cargar el bot (`$bot = ( $this->botLoader )(...)`), insertá:

```php
        // No-texto (audio, foto, sticker...): respondemos pidiendo texto, sin pipeline ni consentimiento.
        if ( 'unsupported' === $inbound->kind ) {
            $this->trySend( $adapter, $channel, $inbound->externalUser, self::UNSUPPORTED );
            return;
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/Channel/InboundDispatcherUnsupportedTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Run full suite + commit**

```bash
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage   # verde (incluye el InboundDispatcherTest existente del happy-path)
git add plugins/infouno-custom/src/Channel/InboundDispatcher.php plugins/infouno-custom/tests/Unit/Channel/InboundDispatcherUnsupportedTest.php
git commit -m "feat: InboundDispatcher responde a mensajes no-texto pidiendo texto"
```

---

## Task 5: ChannelWebhookController — challenge GET + no encolar sin mensaje

**Files:**
- Modify: `plugins/infouno-custom/src/API/ChannelWebhookController.php`
- Modify: `plugins/infouno-custom/tests/bootstrap.php` (stub `WP_REST_Response`, si falta)
- Test: `plugins/infouno-custom/tests/Unit/API/ChannelWebhookControllerTest.php`

- [ ] **Step 1: Add `WP_REST_Response` stub to bootstrap (si falta)**

En `tests/bootstrap.php`, antes de `$GLOBALS['wpdb'] = ...`, agregá (con guard):

```php
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( public mixed $data = null, public int $status = 200 ) {}
        public function get_status(): int { return $this->status; }
        public function get_data(): mixed { return $this->data; }
    }
}
```

Verificá si `WP_REST_Server` (con la constante `READABLE`/`CREATABLE`) hace falta: el controller usa `\WP_REST_Server::READABLE` solo dentro de `registerRoutes()`, que NO se invoca en estos tests (testeamos `handle`/`handleChallenge` directo). No hace falta stubearlo.

- [ ] **Step 2: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\API;

use Infouno\SaaS\API\ChannelWebhookController;
use Infouno\SaaS\Channel\ChannelAdapterInterface;
use Infouno\SaaS\Channel\ChannelEventRepository;
use Infouno\SaaS\Channel\ChannelRegistry;
use Infouno\SaaS\Channel\ChannelRepository;
use Infouno\SaaS\Channel\InboundMessage;
use Infouno\SaaS\Channel\WebhookChallengeInterface;
use PHPUnit\Framework\TestCase;

final class ChannelWebhookControllerTest extends TestCase {

    private function controller( ChannelAdapterInterface $adapter, array $channel ): ChannelWebhookController {
        $registry = new ChannelRegistry();
        $registry->register( $adapter );
        $repo = $this->createMock( ChannelRepository::class );
        $repo->method( 'resolveByRoutingKey' )->willReturn( $channel );
        $events = $this->createMock( ChannelEventRepository::class );
        $events->method( 'markIfNew' )->willReturn( true );
        return new ChannelWebhookController( $registry, $repo, $events );
    }

    /** Adapter que implementa el challenge GET. */
    private function challengeAdapter(): ChannelAdapterInterface {
        return new class implements ChannelAdapterInterface, WebhookChallengeInterface {
            public function type(): string { return 'whatsapp'; }
            public function verifyWebhook( \WP_REST_Request $r, array $c ): bool { return true; }
            public function verifyChallenge( \WP_REST_Request $r, array $c ): ?string {
                return 'subscribe' === $r->get_param( 'hub_mode' ) ? (string) $r->get_param( 'hub_challenge' ) : null;
            }
            public function parseInbound( array $p ): ?InboundMessage {
                return isset( $p['msg'] ) ? new InboundMessage( 'whatsapp', '549', 'hi', 'id1' ) : null;
            }
            public function send( array $c, string $u, string $t ): void {}
            public function splitMessage( string $t ): array { return [ $t ]; }
        };
    }

    public function test_get_challenge_echoes_valid_challenge(): void {
        $channel = [ 'id' => 4, 'channel_type' => 'whatsapp' ];
        $ctrl    = $this->controller( $this->challengeAdapter(), $channel );

        $req = new \WP_REST_Request();
        $req->set_param( 'type', 'whatsapp' );
        $req->set_param( 'key', 'rk_x' );
        $req->set_param( 'hub_mode', 'subscribe' );
        $req->set_param( 'hub_challenge', 'C-99' );

        $res = $ctrl->handleChallenge( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( 'C-99', $res->get_data() );
    }

    public function test_post_without_message_does_not_dedup_as_handled(): void {
        // parseInbound → null (status/receipt). El handler responde 200 ignorado, sin encolar.
        $channel = [ 'id' => 4, 'channel_type' => 'whatsapp' ];
        $ctrl    = $this->controller( $this->challengeAdapter(), $channel );

        $req = new \WP_REST_Request();
        $req->set_param( 'type', 'whatsapp' );
        $req->set_param( 'key', 'rk_x' );
        $req->set_body( '{}' );   // get_json_params → [] → parseInbound null

        $res = $ctrl->handle( $req );
        $this->assertSame( 200, $res->get_status() );
        $this->assertTrue( (bool) ( $res->get_data()['ignored'] ?? false ) );
    }
}
```

> El stub `WP_REST_Request` debe tener `get_json_params()`. Si no lo tiene, agregalo en el bootstrap: `public function get_json_params(): array { return json_decode( $this->body ?: '{}', true ) ?: []; }` (con guard de que el método no exista ya — si la clase stub ya está definida, agregá el método dentro de ella).

- [ ] **Step 3: Run test to verify it fails**

Run: `docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/API/ChannelWebhookControllerTest.php`
Expected: FAIL — `handleChallenge` no existe; y `handle` con inbound null hoy igual encola y devuelve `{ok:true}` sin `ignored`.

- [ ] **Step 4: Modify the controller**

a) En `registerRoutes()`, agregá una ruta GET para el mismo patrón (después del `register_rest_route` POST existente):

```php
        register_rest_route( $namespace, '/channels/(?P<type>[a-z]+)/(?P<key>[A-Za-z0-9_\-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'handleChallenge' ],
            'permission_callback' => '__return_true',
        ] );
```

b) Agregá el método `handleChallenge` (resuelve canal → si el adapter es `WebhookChallengeInterface` → verifyChallenge → echo del challenge en text/plain):

```php
    public function handleChallenge( \WP_REST_Request $request ): \WP_REST_Response {
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
        if ( ! $adapter instanceof \Infouno\SaaS\Channel\WebhookChallengeInterface ) {
            return new \WP_REST_Response( [ 'ok' => false ], 404 );
        }

        $challenge = $adapter->verifyChallenge( $request, $channel );
        if ( null === $challenge ) {
            return new \WP_REST_Response( [ 'ok' => false ], 403 );
        }

        // Meta espera el challenge crudo como body. WP_REST_Response con un string
        // lo serializa como JSON ("C-99"); Meta acepta el match exacto del valor.
        $response = new \WP_REST_Response( $challenge, 200 );
        $response->header( 'Content-Type', 'text/plain; charset=UTF-8' );
        return $response;
    }
```

> Nota: el stub de test de `WP_REST_Response` no necesita `header()`; agregale un método `header()` no-op si el test lo ejercita. Para mantener el test simple, NO llamamos `get_data()` esperando JSON-encoding — el test compara `get_data() === 'C-99'` (el string crudo), que es lo que guarda el stub. En runtime, WP serializa la respuesta; el smoke-test (Task 6) valida el echo real contra WordPress.

Agregá al stub `WP_REST_Response` del bootstrap un `header()` no-op:
```php
        public function header( string $k, string $v ): void {}
```

c) En `handle()` (POST), reestructurá para NO encolar si no hay mensaje. Reemplazá el bloque que hace parseInbound + dedup + enqueue por:

```php
        $payload = (array) $request->get_json_params();
        $inbound = $adapter->parseInbound( $payload );

        // Sin mensaje procesable (status, receipts, eventos no-mensaje): nada que encolar.
        if ( null === $inbound ) {
            return new \WP_REST_Response( [ 'ok' => true, 'ignored' => true ], 200 );
        }

        // Idempotencia: descartar retries del proveedor.
        if ( ! $this->eventRepo->markIfNew( (int) $channel['id'], $type, $inbound->externalMsgId ) ) {
            return new \WP_REST_Response( [ 'ok' => true, 'dup' => true ], 200 );
        }

        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action(
                'infouno_process_inbound',
                [ (int) $channel['id'], $payload ],
                'infouno-channels'
            );
        }

        return new \WP_REST_Response( [ 'ok' => true ], 200 );
```

- [ ] **Step 5: Run test + full suite + lint**

Run:
```bash
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli php -l src/API/ChannelWebhookController.php
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage tests/Unit/API/ChannelWebhookControllerTest.php
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage
```
Expected: lint OK; test PASS (2); suite completa verde (el flujo POST de Telegram sigue igual — un update con texto sí encola).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/API/ChannelWebhookController.php plugins/infouno-custom/tests/Unit/API/ChannelWebhookControllerTest.php plugins/infouno-custom/tests/bootstrap.php
git commit -m "feat: webhook GET challenge (Meta) + no encolar webhooks sin mensaje"
```

---

## Task 6: Registrar WhatsAppAdapter + smoke-test runtime

**Files:**
- Modify: `plugins/infouno-custom/src/Plugin.php`

- [ ] **Step 1: Registrar el adapter en Plugin.php**

En `src/Plugin.php`, en el bloque de canales (dentro del `if ( null !== $vault )`, junto a `$this->channelRegistry->register( new TelegramAdapter(...) )`), agregá:

```php
            $this->channelRegistry->register( new WhatsAppAdapter( $vault, new WpHttpClient() ) );
```

Y agregá el `use` al inicio:

```php
use Infouno\SaaS\Channel\WhatsAppAdapter;
```

- [ ] **Step 2: Lint + suite**

Run:
```bash
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli php -l src/Plugin.php
docker run --rm -v "$PWD/plugins/infouno-custom":/app -w /app php:8.3-cli vendor/bin/phpunit --no-coverage
```
Expected: lint OK; suite completa verde.

- [ ] **Step 3: Commit**

```bash
git add plugins/infouno-custom/src/Plugin.php
git commit -m "feat: registra WhatsAppAdapter en el ChannelRegistry"
```

- [ ] **Step 4: Smoke-test runtime (WordPress real)**

> Requiere el stack de `smoke-test/` arriba (`cd smoke-test && docker compose up -d`) y el plugin reactivado para tomar el código nuevo (`docker compose exec -T wpcli wp plugin deactivate infouno-custom --allow-root && docker compose exec -T wpcli wp plugin activate infouno-custom --allow-root`).

Sembrá un canal WhatsApp con `app_secret`/`verify_token` conocidos, probá el GET de verificación y un POST firmado. Ejecutá este script vía `wp eval-file` (copialo al contenedor con `docker compose cp`):

```php
<?php
// smoke-test/wa-seed.php — siembra un canal whatsapp y muestra routing_key/app_secret.
global $wpdb;
$tenantId = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}infouno_tenants ORDER BY id LIMIT 1" );
$botId    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}infouno_bots WHERE tenant_id=%d LIMIT 1", $tenantId ) );
$vault    = new \Infouno\SaaS\Security\CredentialVault( INFOUNO_ENCRYPTION_KEY );
$repo     = new \Infouno\SaaS\Channel\ChannelRepository( $vault );
$rk       = 'wa_' . bin2hex( random_bytes( 6 ) );
$repo->create( $tenantId, $botId, 'whatsapp', $rk, [
    'access_token' => 'fake-token', 'phone_number_id' => 'PNID-1',
    'app_secret'   => 'smoke-secret', 'verify_token' => 'smoke-verify',
], '', '@SmokeWA' );
echo "WA_ROUTING_KEY={$rk}\n";
```

Verificaciones (desde la raíz del repo, con la URL del stack `http://localhost:8080`):
```bash
RK=<WA_ROUTING_KEY del seed>
# 1) GET challenge OK → debe devolver "C-123"
curl -s "http://localhost:8080/?rest_route=/infouno/v1/channels/whatsapp/${RK}&hub.mode=subscribe&hub.verify_token=smoke-verify&hub.challenge=C-123"
# 2) GET challenge con token malo → 403
curl -s -o /dev/null -w "%{http_code}\n" "http://localhost:8080/?rest_route=/infouno/v1/channels/whatsapp/${RK}&hub.mode=subscribe&hub.verify_token=WRONG&hub.challenge=C-123"
# 3) POST firmado con un mensaje de texto → 200 {ok:true}
BODY='{"entry":[{"changes":[{"value":{"messages":[{"from":"5491122334455","id":"wamid.SMOKE1","type":"text","text":{"body":"hola precios?"}}]}}]}]}'
SIG="sha256=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac 'smoke-secret' | sed 's/^.* //')"
curl -s -X POST "http://localhost:8080/?rest_route=/infouno/v1/channels/whatsapp/${RK}" -H "Content-Type: application/json" -H "X-Hub-Signature-256: ${SIG}" -d "$BODY"
# 4) correr el worker y verificar conversación whatsapp
docker compose -f smoke-test/docker-compose.yml exec -T wpcli wp action-scheduler run --hooks=infouno_process_inbound --allow-root
docker compose -f smoke-test/docker-compose.yml exec -T db mysql -uwp -pwppw wordpress -e "SELECT channel,external_user,session_id FROM wp_infouno_conversations WHERE channel='whatsapp';"
```
Expected: (1) imprime `C-123`; (2) `403`; (3) `{"ok":true}`; (4) una conversación con `channel=whatsapp`, `session_id=wa:5491122334455`. (El LLM con clave dummy falla 503 → la acción queda failed para retry, igual que Telegram — eso confirma el ciclo, no es un error del canal.)

> Importante: la firma HMAC debe calcularse sobre el body EXACTO que se envía. Usá `printf '%s'` (sin newline). Si el `curl` re-serializa, mandá el mismo string crudo.

---

## Cierre

Al completar las 6 tareas: WhatsApp queda como segundo canal funcional — firma de Meta verificada, handshake GET, texto procesado por el pipeline (con cuota dura), no-texto respondido pidiendo texto, y validado end-to-end en WordPress real. La columna vertebral demostró su extensibilidad: agregar el canal de mayor valor comercial fue un adapter + dos extensiones pequeñas, sin tocar el pipeline, el consentimiento ni el worker.

**Pendiente operativo (documentar):** cada tenant crea su app de Meta (BYO), configura el webhook apuntando a `/wp-json/infouno/v1/channels/whatsapp/{routing_key}` con su `verify_token`, y carga `access_token`/`phone_number_id`/`app_secret`/`verify_token` vía `ChannelRepository::create`. Embedded Signup (onboarding self-service) es fase futura.
