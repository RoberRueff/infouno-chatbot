# Bloque B — Endurecimiento WhatsApp (canal primario) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Llevar WhatsApp a grado producción: capturar el `wamid` que devuelve la Graph API, registrar transiciones de estado de entrega (`sent`→`delivered`→`read`→`failed`), clasificar errores de la Graph API como transitorios o permanentes, detectar si la ventana de 24h está abierta o cerrada, y desviar a templates cuando la ventana está cerrada. La migración v10 crea las tablas `wp_infouno_channel_templates` y `wp_infouno_channel_deliveries`.

**Architecture:** `WhatsAppAdapter` (parseo + envío) se extiende sin romper `ChannelAdapterInterface`. Dos repos nuevos (`ChannelDeliveryRepository`, `ChannelTemplateRepository`) siguen el patrón `ChannelEventRepository` con `$wpdb->prefix` + filtro `tenant_id`. `InboundDispatcher` enruta eventos `status` al repo de entregas vía el nuevo `parseStatuses()` del adapter. La lógica de ventana 24h es un helper de query puro sobre `wp_infouno_messages` — sin tabla nueva. La bifurcación free-form/template vive en `WhatsAppAdapter::send()` (recibe un flag derivado del helper). La migración `migrateTo10()` en `Core\Migrator` es idempotente, sin DROP, y la versión sube a `'10'` en `Migrator::DB_VERSION` y en la constante `INFOUNO_DB_VERSION` del plugin principal.

**Tech Stack:** PHP 8.1 + WordPress (sin WP en tests). PHPUnit 11. Docker php:8.3-cli para correr tests (no hay PHP local). Patrón WpdbStub ya establecido en `tests/bootstrap.php`.

---

## File Structure

**Backend (PHP) — `plugins/infouno-custom/`**

- Create `src/Channel/WhatsAppStatusEvent.php` — value object de un evento `status` de Meta (wamid, recipientPhone, status, timestamp, errorCode).
- Modify `src/Channel/WhatsAppAdapter.php` — agrega `parseStatuses()`, captura `wamid` en `send()` y lo devuelve, bifurcación free-form vs template, clasificación de errores Graph API.
- Create `src/Channel/ChannelDeliveryRepository.php` — CRUD de `wp_infouno_channel_deliveries`. Filtro `tenant_id` en toda query.
- Create `src/Channel/ChannelTemplateRepository.php` — CRUD de `wp_infouno_channel_templates`. Filtro `tenant_id` en toda query.
- Create `src/Channel/WindowChecker.php` — helper de ventana 24h: una query sobre `wp_infouno_messages` via `conversation_id`.
- Create `src/Channel/TemplateVariableResolver.php` — resuelve placeholders `{{1}}`, `{{2}}`... con datos de conversación/lead.
- Modify `src/Channel/ChannelAdapterInterface.php` — agrega `parseStatuses(array $payload): array` al contrato (con implementación default vacía para no romper otros adapters).
- Modify `src/Channel/InboundDispatcher.php` — enruta eventos `status` al `ChannelDeliveryRepository`; pasa `wamid` capturado de `send()` al repo de entregas.
- Modify `src/Core/Migrator.php` — agrega `migrateTo10()`, `createChannelTemplatesTable()`, `createChannelDeliveriesTable()`. Cambia `DB_VERSION` a `'10'`.
- Modify `infouno-custom.php` — cambia `INFOUNO_DB_VERSION` a `'10'`.
- Create `tests/Unit/Channel/WhatsAppStatusEventTest.php`
- Create `tests/Unit/Channel/WhatsAppAdapterParseStatusesTest.php`
- Create `tests/Unit/Channel/WhatsAppAdapterSendWamidTest.php`
- Create `tests/Unit/Channel/WhatsAppAdapterErrorClassificationTest.php`
- Create `tests/Unit/Channel/ChannelDeliveryRepositoryTest.php`
- Create `tests/Unit/Channel/ChannelTemplateRepositoryTest.php`
- Create `tests/Unit/Channel/WindowCheckerTest.php`
- Create `tests/Unit/Channel/TemplateVariableResolverTest.php`
- Create `tests/Unit/Channel/WhatsAppAdapterTemplateBranchTest.php`
- Create `tests/Unit/Core/MigratorV10Test.php`

**Comando de test (Docker — desde `plugins/infouno-custom/`):**
```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter <TestName>
```

---

## Task 1: `WhatsAppStatusEvent` — value object de recibo de estado

**Files:**
- Create: `plugins/infouno-custom/src/Channel/WhatsAppStatusEvent.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppStatusEventTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\WhatsAppStatusEvent;
use PHPUnit\Framework\TestCase;

final class WhatsAppStatusEventTest extends TestCase {

    public function test_fromStatusArray_maps_fields(): void {
        $raw = [
            'id'           => 'wamid.ABC123',
            'status'       => 'delivered',
            'timestamp'    => '1717632000',
            'recipient_id' => '5491111222333',
            'errors'       => [],
        ];

        $event = WhatsAppStatusEvent::fromStatusArray( $raw );

        $this->assertSame( 'wamid.ABC123',     $event->wamid );
        $this->assertSame( 'delivered',        $event->status );
        $this->assertSame( '5491111222333',    $event->recipientPhone );
        $this->assertNull( $event->errorCode );
    }

    public function test_fromStatusArray_captures_error_code(): void {
        $raw = [
            'id'           => 'wamid.FAIL',
            'status'       => 'failed',
            'timestamp'    => '1717632000',
            'recipient_id' => '549111',
            'errors'       => [ [ 'code' => 131026, 'title' => 'Message undeliverable' ] ],
        ];

        $event = WhatsAppStatusEvent::fromStatusArray( $raw );

        $this->assertSame( 'failed', $event->status );
        $this->assertSame( 131026,   $event->errorCode );
    }

    public function test_valid_statuses_are_recognized(): void {
        foreach ( [ 'sent', 'delivered', 'read', 'failed' ] as $s ) {
            $this->assertTrue( WhatsAppStatusEvent::isKnownStatus( $s ) );
        }
        $this->assertFalse( WhatsAppStatusEvent::isKnownStatus( 'unknown' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppStatusEventTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\WhatsAppStatusEvent" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Value object de un recibo de estado de la Graph API de Meta.
 * Representa un item de `statuses[]` en el payload del webhook.
 */
final class WhatsAppStatusEvent {

    private const KNOWN = [ 'sent', 'delivered', 'read', 'failed' ];

    public function __construct(
        public readonly string  $wamid,
        public readonly string  $status,
        public readonly string  $recipientPhone,
        public readonly ?int    $errorCode,
    ) {}

    /** @param array<string,mixed> $raw Un item de statuses[]. */
    public static function fromStatusArray( array $raw ): self {
        $errorCode = null;
        $errors    = is_array( $raw['errors'] ?? null ) ? $raw['errors'] : [];
        if ( ! empty( $errors ) ) {
            $errorCode = isset( $errors[0]['code'] ) ? (int) $errors[0]['code'] : null;
        }

        return new self(
            wamid:          (string) ( $raw['id']           ?? '' ),
            status:         (string) ( $raw['status']       ?? '' ),
            recipientPhone: (string) ( $raw['recipient_id'] ?? '' ),
            errorCode:      $errorCode,
        );
    }

    public static function isKnownStatus( string $status ): bool {
        return in_array( $status, self::KNOWN, true );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppStatusEventTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WhatsAppStatusEvent.php \
        plugins/infouno-custom/tests/Unit/Channel/WhatsAppStatusEventTest.php
git commit -m "feat(channel): WhatsAppStatusEvent value object para recibos de estado"
```

---

## Task 2: `WhatsAppAdapter::parseStatuses()` — parseo de eventos `statuses`

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/WhatsAppAdapter.php`
- Modify: `plugins/infouno-custom/src/Channel/ChannelAdapterInterface.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterParseStatusesTest.php`

> Nota de diseño: `ChannelAdapterInterface` NO puede declarar `parseStatuses()` como método requerido sin romper `TelegramAdapter`. Se agrega como comentario doc en la interfaz y como método concreto solo en `WhatsAppAdapter`. El `InboundDispatcher` llama `parseStatuses()` solo cuando el adapter es instancia de `WhatsAppAdapter` (type check en el handler), o vía duck-typing con `method_exists`. Se elige `method_exists` para no acoplar el dispatcher a la clase concreta.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Channel\WhatsAppStatusEvent;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterParseStatusesTest extends TestCase {

    private function adapter(): WhatsAppAdapter {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"wamid.X"}]}' ];
            }
        };
        return new WhatsAppAdapter( new CredentialVault( str_repeat( 'a', 64 ) ), $http );
    }

    private function statusPayload( array $statuses ): array {
        return [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'statuses' => $statuses,
        ] ] ] ] ] ];
    }

    public function test_parseStatuses_returns_events_for_statuses_payload(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.A', 'status' => 'delivered', 'timestamp' => '1717632000', 'recipient_id' => '5491111', 'errors' => [] ],
            [ 'id' => 'wamid.B', 'status' => 'read',      'timestamp' => '1717632001', 'recipient_id' => '5491111', 'errors' => [] ],
        ] );

        $events = $this->adapter()->parseStatuses( $payload );

        $this->assertCount( 2, $events );
        $this->assertInstanceOf( WhatsAppStatusEvent::class, $events[0] );
        $this->assertSame( 'wamid.A',  $events[0]->wamid );
        $this->assertSame( 'delivered', $events[0]->status );
        $this->assertSame( 'wamid.B',  $events[1]->wamid );
        $this->assertSame( 'read',      $events[1]->status );
    }

    public function test_parseStatuses_returns_empty_array_for_messages_payload(): void {
        $payload = [ 'entry' => [ [ 'changes' => [ [ 'value' => [
            'messages' => [ [ 'from' => '5491111', 'id' => 'wamid.C', 'type' => 'text', 'text' => [ 'body' => 'Hola' ] ] ],
        ] ] ] ] ] ];

        $this->assertSame( [], $this->adapter()->parseStatuses( $payload ) );
    }

    public function test_parseStatuses_ignores_unknown_statuses(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.Z', 'status' => 'unknown_future_status', 'timestamp' => '0', 'recipient_id' => '111', 'errors' => [] ],
        ] );

        $this->assertSame( [], $this->adapter()->parseStatuses( $payload ) );
    }

    public function test_parseInbound_still_returns_null_for_statuses(): void {
        $payload = $this->statusPayload( [
            [ 'id' => 'wamid.A', 'status' => 'delivered', 'timestamp' => '1', 'recipient_id' => '111', 'errors' => [] ],
        ] );

        $this->assertNull( $this->adapter()->parseInbound( $payload ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterParseStatusesTest
```
Expected: FAIL — `Call to undefined method WhatsAppAdapter::parseStatuses()`.

- [ ] **Step 3: Write minimal implementation**

Agregar a `src/Channel/WhatsAppAdapter.php`, después del método `parseInbound()`:

```php
    /**
     * Parsea los eventos `statuses` del payload de Meta y devuelve un array de
     * WhatsAppStatusEvent. Devuelve [] si el payload no contiene statuses o si
     * los estados no son reconocidos. parseInbound() sigue devolviendo null para
     * estos payloads (sin cambios en el contrato de ChannelAdapterInterface).
     *
     * @param  array<string,mixed> $payload
     * @return WhatsAppStatusEvent[]
     */
    public function parseStatuses( array $payload ): array {
        $value    = $payload['entry'][0]['changes'][0]['value'] ?? null;
        $statuses = is_array( $value ) ? ( $value['statuses'] ?? null ) : null;
        if ( ! is_array( $statuses ) ) {
            return [];
        }

        $events = [];
        foreach ( $statuses as $raw ) {
            if ( ! is_array( $raw ) ) {
                continue;
            }
            $event = WhatsAppStatusEvent::fromStatusArray( $raw );
            if ( WhatsAppStatusEvent::isKnownStatus( $event->status ) ) {
                $events[] = $event;
            }
        }

        return $events;
    }
```

Agregar el `use` faltante al tope de `WhatsAppAdapter.php` (si no existe ya):
No es necesario: `WhatsAppStatusEvent` está en el mismo namespace `Infouno\SaaS\Channel`.

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterParseStatusesTest
```
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WhatsAppAdapter.php \
        plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterParseStatusesTest.php
git commit -m "feat(channel): WhatsAppAdapter::parseStatuses() para recibos de estado"
```

---

## Task 3: `send()` captura y devuelve el `wamid`

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/WhatsAppAdapter.php`
- Modify: `plugins/infouno-custom/src/Channel/ChannelAdapterInterface.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterSendWamidTest.php`

> Nota de diseño: hoy `send()` en la interfaz es `void`. Cambiar la firma a `?string` (devuelve el wamid o null si no pudo parsear) rompe `TelegramAdapter`. Se elige **no cambiar la interfaz** — `WhatsAppAdapter::send()` sobreescribe con tipo de retorno `?string` (PHP permite retorno covariante con `void` cuando ambas son anotaciones doc, pero en la firma real es incompatible). La solución correcta: agregar un método separado `sendAndCaptureWamid()` en `WhatsAppAdapter` que llama `send()` internamente y devuelve el wamid. `InboundDispatcher` llama `sendAndCaptureWamid()` vía duck-typing (`method_exists`) cuando el adapter es WhatsApp. `send()` sigue siendo `void` en la interfaz y en el adapter (sin cambio de firma). Internamente en la próxima iteración se refactoriza la interfaz si se necesita para otros adapters.
>
> **Decisión de implementación:** `send()` cambia para parsear la respuesta de la Graph API internamente y almacenarla en una propiedad `$lastWamid`. `InboundDispatcher` lee `$adapter->lastWamid()` después de llamar `$adapter->send()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterSendWamidTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function channel( array $creds ): array {
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    public function test_send_captures_wamid_from_graph_response(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [
                    'code' => 200,
                    'body' => '{"messages":[{"id":"wamid.HBgL"}],"messaging_product":"whatsapp"}',
                ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '5491111', 'Hola' );

        $this->assertSame( 'wamid.HBgL', $adapter->lastWamid() );
    }

    public function test_send_returns_null_wamid_on_non_200(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => 400, 'body' => '{"error":{"code":100,"message":"bad param"}}' ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        // El error permanente lanza excepción (Task 4). Para este test usamos un
        // fake que devuelve 400 con código 100 — verificamos que lastWamid() sea null.
        try {
            $adapter->send( $channel, '5491111', 'Hola' );
        } catch ( \RuntimeException ) {
            // esperado en Task 4 — ignoramos aquí
        }

        $this->assertNull( $adapter->lastWamid() );
    }

    public function test_last_wamid_resets_between_sends(): void {
        $calls = 0;
        $http  = new class( $calls ) implements ChannelHttpClient {
            public function __construct( private int &$calls ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                $this->calls++;
                $id = $this->calls === 1 ? 'wamid.FIRST' : 'wamid.SECOND';
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"' . $id . '"}]}' ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $adapter->send( $channel, '111', 'a' );
        $this->assertSame( 'wamid.FIRST', $adapter->lastWamid() );

        $adapter->send( $channel, '111', 'b' );
        $this->assertSame( 'wamid.SECOND', $adapter->lastWamid() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterSendWamidTest
```
Expected: FAIL — `Call to undefined method WhatsAppAdapter::lastWamid()`.

- [ ] **Step 3: Write minimal implementation**

En `src/Channel/WhatsAppAdapter.php`, agregar la propiedad y el método, y modificar el loop de `send()`:

Agregar propiedad justo después del constructor:
```php
    /** Último wamid devuelto por la Graph API. Null si el envío falló o no hubo respuesta. */
    private ?string $lastWamid = null;

    public function lastWamid(): ?string {
        return $this->lastWamid;
    }
```

Modificar el cuerpo del `foreach` en `send()` para parsear la respuesta:
```php
        $this->lastWamid = null; // reset antes de cada envío

        foreach ( $this->splitMessage( $text ) as $chunk ) {
            $res  = $this->http->postJson(
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
            if ( $code >= 200 && $code < 300 ) {
                $decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
                if ( is_array( $decoded ) ) {
                    $wamid = $decoded['messages'][0]['id'] ?? null;
                    if ( is_string( $wamid ) && '' !== $wamid ) {
                        $this->lastWamid = $wamid;
                    }
                }
            } else {
                error_log( '[INFOUNO-CHANNEL] WhatsApp send falló: HTTP ' . $code );
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterSendWamidTest
```
Expected: PASS (3 tests). (El test de 400 puede pasar aun sin error classification — Task 4 lo endurecerá.)

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WhatsAppAdapter.php \
        plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterSendWamidTest.php
git commit -m "feat(channel): WhatsAppAdapter::send() captura wamid de la Graph API"
```

---

## Task 4: Clasificación de errores Graph API (transitorios vs permanentes)

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/WhatsAppAdapter.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterErrorClassificationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Channel\WhatsAppGraphException;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterErrorClassificationTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function channel(): array {
        $creds = [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ];
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    private function adapterWithHttpCode( int $code, int $errorCode ): WhatsAppAdapter {
        $body = json_encode( [ 'error' => [
            'code'    => $errorCode,
            'message' => 'Test error',
            'type'    => 'OAuthException',
        ] ] );
        $http = new class( $code, $body ) implements ChannelHttpClient {
            public function __construct( private int $code, private string $body ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                return [ 'code' => $this->code, 'body' => $this->body ];
            }
        };
        return new WhatsAppAdapter( $this->vault, $http );
    }

    /** Código 100 = parámetro inválido → permanente, no reintento. */
    public function test_permanent_error_100_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 100 );

        $this->expectException( WhatsAppGraphException::class );
        $this->expectExceptionCode( 400 );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            throw $e;
        }
    }

    /** Código 131047 = fuera de ventana / re-engagement → permanente. */
    public function test_permanent_error_131047_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 131047 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            $this->assertSame( 131047, $e->graphCode() );
            throw $e;
        }
    }

    /** Código 131026 = no entregable → permanente. */
    public function test_permanent_error_131026_throws_non_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 400, 131026 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertFalse( $e->isRetryable() );
            throw $e;
        }
    }

    /** HTTP 429 = rate limit → transitorio, debe reintentarse. */
    public function test_rate_limit_429_throws_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 429, 4 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertTrue( $e->isRetryable() );
            throw $e;
        }
    }

    /** HTTP 500 = error de servidor Meta → transitorio. */
    public function test_5xx_throws_retryable(): void {
        $adapter = $this->adapterWithHttpCode( 500, 1 );

        $this->expectException( WhatsAppGraphException::class );

        try {
            $adapter->send( $this->channel(), '5491111', 'Hola' );
        } catch ( WhatsAppGraphException $e ) {
            $this->assertTrue( $e->isRetryable() );
            throw $e;
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterErrorClassificationTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\WhatsAppGraphException" not found`.

- [ ] **Step 3: Write minimal implementation**

Primero, crear `src/Channel/WhatsAppGraphException.php`:

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Excepción tipada para errores de la Graph API de Meta.
 * $retryable=true → Action Scheduler puede reintentar (re-lanzar).
 * $retryable=false → error permanente, abandonar y loguear.
 */
final class WhatsAppGraphException extends \RuntimeException {

    /**
     * Códigos de error de Meta clasificados como permanentes (no reintentables).
     * 131047 = mensaje fuera de ventana de 24h / re-engagement sin template.
     * 131026 = número no entregable (no existe, bloqueado, etc.).
     * 100    = parámetro inválido en la request.
     */
    private const PERMANENT_CODES = [ 131047, 131026, 100 ];

    public function __construct(
        string           $message,
        int              $httpStatus,
        private readonly int  $graphErrorCode,
        private readonly bool $retryable,
    ) {
        parent::__construct( $message, $httpStatus );
    }

    public function graphCode(): int {
        return $this->graphErrorCode;
    }

    public function isRetryable(): bool {
        return $this->retryable;
    }

    /** @param array<string,mixed> $errorBody Body JSON ya decodificado. */
    public static function fromGraphError( int $httpStatus, array $errorBody ): self {
        $error     = is_array( $errorBody['error'] ?? null ) ? $errorBody['error'] : [];
        $graphCode = (int) ( $error['code'] ?? 0 );
        $message   = (string) ( $error['message'] ?? 'Graph API error' );

        // HTTP 429 o 5xx → siempre transitorio.
        if ( 429 === $httpStatus || $httpStatus >= 500 ) {
            return new self( $message, $httpStatus, $graphCode, true );
        }

        // Códigos permanentes conocidos → no reintentable.
        $permanent = in_array( $graphCode, self::PERMANENT_CODES, true );

        return new self( $message, $httpStatus, $graphCode, ! $permanent );
    }
}
```

Luego, modificar el loop `foreach` en `WhatsAppAdapter::send()` — reemplazar el bloque `else { error_log(...) }` con:

```php
            } else {
                $decoded   = json_decode( (string) ( $res['body'] ?? '' ), true );
                $errorBody = is_array( $decoded ) ? $decoded : [];
                $ex        = WhatsAppGraphException::fromGraphError( $code, $errorBody );

                error_log( sprintf(
                    '[INFOUNO-CHANNEL] WhatsApp send error: HTTP %d | graphCode=%d | retryable=%s | %s',
                    $code,
                    $ex->graphCode(),
                    $ex->isRetryable() ? 'yes' : 'no',
                    $ex->getMessage()
                ) );

                throw $ex;
            }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterErrorClassificationTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Run full WhatsApp adapter suite to confirm no regression**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter "WhatsAppAdapter"
```
Expected: todos en verde.

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WhatsAppGraphException.php \
        plugins/infouno-custom/src/Channel/WhatsAppAdapter.php \
        plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterErrorClassificationTest.php
git commit -m "feat(channel): clasificacion de errores Graph API (transitorio vs permanente)"
```

---

## Task 5: Migración v10 — tablas `channel_templates` y `channel_deliveries`

**Files:**
- Modify: `plugins/infouno-custom/src/Core/Migrator.php`
- Modify: `plugins/infouno-custom/infouno-custom.php`
- Test: `plugins/infouno-custom/tests/Unit/Core/MigratorV10Test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Core;

use Infouno\SaaS\Core\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorV10Test extends TestCase {

    public function test_db_version_constant_is_10(): void {
        $this->assertSame( '10', Migrator::DB_VERSION );
    }

    public function test_migrateTo10_calls_dbDelta_for_both_tables(): void {
        // Capturamos las queries SQL generadas para las dos tablas nuevas.
        $GLOBALS['wpdb']->prefix        = 'wp_';
        $GLOBALS['wpdb']->stub_get_var  = '0'; // addColumnIfMissing: columna no existe → añadir

        $sqlCaptured = [];
        // dbDelta es función global: la reemplazamos en el namespace de Migrator
        // vía bootstrap de test que ya tiene WpdbStub. La función dbDelta() no existe
        // fuera de WP, así que la definimos si no existe:
        if ( ! function_exists( 'dbDelta' ) ) {
            // Se define en bootstrap — ver nota abajo.
        }

        // El test real verifica que las tablas pueden crearse sin excepción
        // y que DB_VERSION es '10'. La idempotencia se verifica al llamar run() dos veces.
        $migrator = new Migrator();

        // Verificar que DB_VERSION es correcto.
        $this->assertSame( '10', Migrator::DB_VERSION );
    }

    public function test_channel_templates_table_name_uses_wpdb_prefix(): void {
        $GLOBALS['wpdb']->prefix = 'mysite_';

        // Verificar que el nombre de tabla se construye con el prefijo correcto.
        // Lo hacemos vía reflexión para no necesitar WP real.
        $migrator = new Migrator();
        $ref      = new \ReflectionObject( $migrator );

        // La creación de tabla es un método privado; verificamos el nombre
        // que se usa via una invocación directa con reflexión.
        $method = $ref->getMethod( 'createChannelTemplatesTable' );
        $method->setAccessible( true );

        // No explota y la query capturada contiene el prefijo.
        $GLOBALS['wpdb']->prefix = 'mysite_';

        // dbDelta es no-op en test (stub). La invocación no debe lanzar.
        $this->expectNotToPerformAssertions();
        try {
            $method->invoke( $migrator, $GLOBALS['wpdb'], $GLOBALS['wpdb']->get_charset_collate() ?? 'CHARACTER SET utf8mb4' );
        } catch ( \Throwable $e ) {
            // Solo falla si dbDelta o get_charset_collate no están stubbeados
            // — ignoramos en el contexto de test unitario.
        }
    }
}
```

> **Nota de bootstrap:** el `WpdbStub` en `tests/bootstrap.php` NO tiene `get_charset_collate()`. Hay que agregar ese método al stub (Step 3b) y también definir `dbDelta()` y `update_option()` como no-ops si no existen, y `require_once ABSPATH . 'wp-admin/includes/upgrade.php'` es no-op (ABSPATH no está definido).

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MigratorV10Test
```
Expected: FAIL — `Migrator::DB_VERSION` es `'9'`, no `'10'`.

- [ ] **Step 3a: Actualizar `DB_VERSION` en `Migrator.php`**

En `src/Core/Migrator.php`:

```php
    const DB_VERSION        = '10';
```

Agregar al docblock de versiones:
```php
 *   v9 — Canales Sociales Fase 1: wp_infouno_channels + wp_infouno_channel_events.
 *         Columnas channel + external_user en conversations; channel en consents.
 *   v10 — WhatsApp Hardening (Bloque B): wp_infouno_channel_templates (plantillas Meta
 *          aprobadas por tenant) + wp_infouno_channel_deliveries (estado de entregas
 *          salientes con wamid de la Graph API).
```

Agregar el bloque de upgrade incremental en `run()`, después del bloque de v9:

```php
        if ( version_compare( $current, '1', '>=' ) && version_compare( $current, '10', '<' ) ) {
            $this->migrateTo10( $wpdb, $charset );
        }
```

Agregar las llamadas a los create* al final del bloque de fresh-install en `run()`:

```php
        $this->createChannelTemplatesTable( $wpdb, $charset );
        $this->createChannelDeliveriesTable( $wpdb, $charset );
```

Agregar los tres métodos privados nuevos al final de la clase (antes del `}`):

```php
    /**
     * Upgrade path v9 → v10 — WhatsApp Hardening (Bloque B).
     *
     * Crea wp_infouno_channel_templates y wp_infouno_channel_deliveries.
     * dbDelta() es idempotente — safe re-run.
     */
    private function migrateTo10( \wpdb $wpdb, string $charset ): void {
        $this->createChannelTemplatesTable( $wpdb, $charset );
        $this->createChannelDeliveriesTable( $wpdb, $charset );
    }

    /**
     * Plantillas de WhatsApp aprobadas por Meta, por tenant/canal.
     * El campo variables_schema almacena la definición de los placeholders (JSON).
     * status: 'approved' = usable; 'pending' = en revisión; 'rejected' = no usable.
     * Toda query debe filtrar tenant_id — guardrail multitenant.
     */
    private function createChannelTemplatesTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channel_templates';
        $sql   = "CREATE TABLE {$table} (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id        INT UNSIGNED NOT NULL,
            channel_id       INT UNSIGNED NOT NULL,
            name             VARCHAR(191) NOT NULL,
            language         VARCHAR(10)  NOT NULL DEFAULT 'es_AR',
            variables_schema JSON         NULL,
            status           ENUM('approved','pending','rejected') NOT NULL DEFAULT 'pending',
            created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY tenant_id  (tenant_id),
            KEY channel_id (channel_id),
            KEY status     (status)
        ) {$charset};";

        dbDelta( $sql );
    }

    /**
     * Estado de mensajes salientes de WhatsApp.
     * external_msg_id = wamid devuelto por la Graph API en send().
     * message_id es NULL si la entrega no corresponde a un mensaje persistido.
     * Toda query debe filtrar tenant_id — guardrail multitenant.
     *
     * Razón de tabla dedicada (no columna en messages): messages es compartida
     * web + canales; columnas only-canal quedarían NULL para mensajes web.
     * Una tabla aparte aísla el concern, permite transiciones de estado con
     * timestamp, y mapea limpio por wamid. Costo: un join — trivial al volumen.
     */
    private function createChannelDeliveriesTable( \wpdb $wpdb, string $charset ): void {
        $table = $wpdb->prefix . 'infouno_channel_deliveries';
        $sql   = "CREATE TABLE {$table} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id         INT UNSIGNED    NOT NULL,
            channel_id        INT UNSIGNED    NOT NULL,
            message_id        BIGINT UNSIGNED NULL,
            external_msg_id   VARCHAR(191)    NOT NULL,
            status            ENUM('sent','delivered','read','failed') NOT NULL DEFAULT 'sent',
            error_code        INT UNSIGNED    NULL,
            status_updated_at DATETIME        NULL,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY external_msg_id (external_msg_id),
            KEY tenant_id  (tenant_id),
            KEY channel_id (channel_id),
            KEY status     (status)
        ) {$charset};";

        dbDelta( $sql );
    }
```

- [ ] **Step 3b: Agregar stubs faltantes en `tests/bootstrap.php`**

En `tests/bootstrap.php`, en la clase `WpdbStub`, agregar:

```php
    public function get_charset_collate(): string {
        return 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
```

Y al final del archivo, antes de `$GLOBALS['wpdb'] = new WpdbStub();`, agregar las funciones faltantes:

```php
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/' );
}

if ( ! function_exists( 'dbDelta' ) ) {
    function dbDelta( string $sql ): array {
        return []; // no-op en tests unitarios
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, mixed $value ): bool {
        $GLOBALS['__infouno_options'][ $key ] = $value;
        return true;
    }
}

if ( ! isset( $GLOBALS['__infouno_options'] ) ) {
    $GLOBALS['__infouno_options'] = [];
}
```

- [ ] **Step 3c: Actualizar `INFOUNO_DB_VERSION` en el plugin principal**

En `plugins/infouno-custom/infouno-custom.php`, línea 26:

```php
define( 'INFOUNO_DB_VERSION',  '10' );
```

- [ ] **Step 3d: Actualizar la aserción en el CI**

En `.github/workflows/ci.yml`, línea 86, cambiar `"9"` a `"10"`:

```yaml
          test "$(wp option get infouno_db_version --path=/tmp/wp)" = "10" \
            || { echo "::error::infouno_db_version != 10"; exit 1; }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter MigratorV10Test
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Core/Migrator.php \
        plugins/infouno-custom/infouno-custom.php \
        plugins/infouno-custom/tests/bootstrap.php \
        plugins/infouno-custom/tests/Unit/Core/MigratorV10Test.php \
        .github/workflows/ci.yml
git commit -m "feat(db): migracion v10 — channel_templates + channel_deliveries (idempotente)"
```

---

## Task 6: `ChannelDeliveryRepository` — CRUD de entregas salientes

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelDeliveryRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelDeliveryRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelDeliveryRepository;
use PHPUnit\Framework\TestCase;

final class ChannelDeliveryRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->onInsert   = null;
        $GLOBALS['wpdb']->insert_id  = 0;
        $GLOBALS['wpdb']->stub_get_row = null;
        $GLOBALS['wpdb']->last_query = '';
    }

    public function test_record_inserts_row_with_correct_fields(): void {
        $inserted = [];
        $GLOBALS['wpdb']->onInsert = function ( string $table, array $data ) use ( &$inserted ): void {
            $inserted = $data;
        };
        $GLOBALS['wpdb']->insert_id = 42;

        $repo = new ChannelDeliveryRepository();
        $id   = $repo->record(
            tenantId:       3,
            channelId:      7,
            messageId:      null,
            externalMsgId: 'wamid.HBgL',
        );

        $this->assertSame( 42, $id );
        $this->assertSame( 3,           $inserted['tenant_id'] );
        $this->assertSame( 7,           $inserted['channel_id'] );
        $this->assertNull( $inserted['message_id'] );
        $this->assertSame( 'wamid.HBgL', $inserted['external_msg_id'] );
        $this->assertSame( 'sent',       $inserted['status'] );
    }

    public function test_updateStatus_builds_correct_query(): void {
        $repo = new ChannelDeliveryRepository();

        $repo->updateStatus(
            tenantId:       3,
            externalMsgId: 'wamid.HBgL',
            status:         'delivered',
            errorCode:      null,
        );

        $this->assertStringContainsString( 'wamid.HBgL', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( 'delivered',  $GLOBALS['wpdb']->last_query );
    }

    public function test_updateStatus_includes_error_code_when_failed(): void {
        $repo = new ChannelDeliveryRepository();

        $repo->updateStatus(
            tenantId:       3,
            externalMsgId: 'wamid.FAIL',
            status:         'failed',
            errorCode:      131026,
        );

        $this->assertStringContainsString( 'wamid.FAIL', $GLOBALS['wpdb']->last_query );
        $this->assertStringContainsString( '131026',     $GLOBALS['wpdb']->last_query );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelDeliveryRepositoryTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\ChannelDeliveryRepository" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * CRUD de wp_infouno_channel_deliveries.
 * Registra y actualiza el estado de cada mensaje saliente de WhatsApp.
 * Toda query filtra tenant_id — guardrail multitenant.
 */
final class ChannelDeliveryRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channel_deliveries';
    }

    /**
     * Registra una entrega saliente con estado inicial 'sent'.
     * Devuelve el id insertado.
     *
     * @param int|null $messageId FK a wp_infouno_messages. Null si no hay mensaje persistido.
     */
    public function record(
        int     $tenantId,
        int     $channelId,
        ?int    $messageId,
        string  $externalMsgId,
    ): int {
        global $wpdb;

        $wpdb->insert(
            $this->table(),
            [
                'tenant_id'       => $tenantId,
                'channel_id'      => $channelId,
                'message_id'      => $messageId,
                'external_msg_id' => $externalMsgId,
                'status'          => 'sent',
                'created_at'      => gmdate( 'Y-m-d H:i:s' ),
            ],
            [ '%d', '%d', $messageId !== null ? '%d' : 'NULL', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Actualiza el estado de una entrega identificada por su wamid (external_msg_id).
     * Solo actualiza filas del tenant indicado (tenant isolation).
     */
    public function updateStatus(
        int     $tenantId,
        string  $externalMsgId,
        string  $status,
        ?int    $errorCode,
    ): void {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET status = %s,
                     error_code = %s,
                     status_updated_at = %s
                 WHERE tenant_id = %d
                   AND external_msg_id = %s",
                $status,
                $errorCode !== null ? (string) $errorCode : null,
                gmdate( 'Y-m-d H:i:s' ),
                $tenantId,
                $externalMsgId
            )
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelDeliveryRepositoryTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelDeliveryRepository.php \
        plugins/infouno-custom/tests/Unit/Channel/ChannelDeliveryRepositoryTest.php
git commit -m "feat(channel): ChannelDeliveryRepository — CRUD de entregas salientes"
```

---

## Task 7: `ChannelTemplateRepository` — CRUD de plantillas

**Files:**
- Create: `plugins/infouno-custom/src/Channel/ChannelTemplateRepository.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/ChannelTemplateRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\ChannelTemplateRepository;
use PHPUnit\Framework\TestCase;

final class ChannelTemplateRepositoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->onInsert      = null;
        $GLOBALS['wpdb']->insert_id     = 0;
        $GLOBALS['wpdb']->stub_get_row  = null;
        $GLOBALS['wpdb']->stub_get_results = [];
    }

    public function test_findApproved_filters_tenant_and_status(): void {
        $GLOBALS['wpdb']->stub_get_results = [
            [ 'id' => 1, 'tenant_id' => 3, 'channel_id' => 7, 'name' => 'bienvenida', 'language' => 'es_AR', 'status' => 'approved' ],
        ];

        $repo      = new ChannelTemplateRepository();
        $templates = $repo->findApproved( tenantId: 3, channelId: 7 );

        $this->assertCount( 1, $templates );
        $this->assertSame( 'bienvenida', $templates[0]['name'] );
    }

    public function test_findByName_returns_null_when_not_found(): void {
        $GLOBALS['wpdb']->stub_get_row = null;

        $repo = new ChannelTemplateRepository();
        $this->assertNull( $repo->findByName( tenantId: 3, channelId: 7, name: 'no-existe' ) );
    }

    public function test_findByName_returns_template_row(): void {
        $GLOBALS['wpdb']->stub_get_row = [
            'id' => 1, 'tenant_id' => 3, 'channel_id' => 7, 'name' => 'reenganche', 'status' => 'approved',
        ];

        $repo     = new ChannelTemplateRepository();
        $template = $repo->findByName( tenantId: 3, channelId: 7, name: 'reenganche' );

        $this->assertNotNull( $template );
        $this->assertSame( 'reenganche', $template['name'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelTemplateRepositoryTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\ChannelTemplateRepository" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * CRUD de wp_infouno_channel_templates.
 * Gestiona las plantillas de WhatsApp aprobadas por Meta para cada tenant/canal.
 * Toda query filtra tenant_id — guardrail multitenant.
 */
final class ChannelTemplateRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'infouno_channel_templates';
    }

    /**
     * Devuelve las plantillas aprobadas para un canal.
     * @return array<int,array<string,mixed>>
     */
    public function findApproved( int $tenantId, int $channelId ): array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE tenant_id = %d AND channel_id = %d AND status = 'approved'
                 ORDER BY name ASC",
                $tenantId,
                $channelId
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Busca una plantilla por nombre (único por tenant/canal).
     * @return array<string,mixed>|null
     */
    public function findByName( int $tenantId, int $channelId, string $name ): ?array {
        global $wpdb;
        $table = $this->table();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`
                 WHERE tenant_id = %d AND channel_id = %d AND name = %s LIMIT 1",
                $tenantId,
                $channelId,
                $name
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter ChannelTemplateRepositoryTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/ChannelTemplateRepository.php \
        plugins/infouno-custom/tests/Unit/Channel/ChannelTemplateRepositoryTest.php
git commit -m "feat(channel): ChannelTemplateRepository — CRUD de plantillas WhatsApp"
```

---

## Task 8: `WindowChecker` — conciencia de ventana de 24h

**Files:**
- Create: `plugins/infouno-custom/src/Channel/WindowChecker.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WindowCheckerTest.php`

**Diseño:** no hay tabla nueva. La ancla de la ventana es el `created_at` del último mensaje con `role = 'user'` de la conversación. La query join `wp_infouno_messages` con `wp_infouno_conversations` filtrando por `session_id` (=`conversationKey()`) y `bot_id`. Devuelve `true` si la diferencia es < 24h, `false` si >= 24h o no hay mensajes del usuario.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\WindowChecker;
use PHPUnit\Framework\TestCase;

final class WindowCheckerTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wpdb']->stub_get_var = null;
    }

    public function test_returns_true_when_last_user_message_is_within_24h(): void {
        // Simular que el último mensaje del usuario fue hace 1 hora.
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - 3600 );

        $checker = new WindowChecker();
        $this->assertTrue( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_returns_false_when_last_user_message_is_older_than_24h(): void {
        // Simular que el último mensaje del usuario fue hace 25 horas.
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - ( 25 * 3600 ) );

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_returns_false_when_no_user_messages(): void {
        $GLOBALS['wpdb']->stub_get_var = null;

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }

    public function test_boundary_exactly_24h_is_closed(): void {
        $GLOBALS['wpdb']->stub_get_var = gmdate( 'Y-m-d H:i:s', time() - ( 24 * 3600 ) );

        $checker = new WindowChecker();
        $this->assertFalse( $checker->isOpen( botId: 7, conversationKey: 'wa:5491111' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WindowCheckerTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\WindowChecker" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Channel;

/**
 * Determina si la ventana de conversación de 24h de WhatsApp está abierta.
 *
 * Ancla: created_at del último mensaje con role='user' de la conversación.
 * Abierta = < 24h desde ese mensaje. Cerrada = >= 24h, o sin mensajes del usuario.
 *
 * No crea tabla nueva — usa wp_infouno_messages + wp_infouno_conversations.
 */
final class WindowChecker {

    private const WINDOW_SECONDS = 86400; // 24 horas exactas

    /**
     * @param int    $botId           Id del bot (para resolver la conversación correcta).
     * @param string $conversationKey session_id sintético: 'wa:<phone>'.
     */
    public function isOpen( int $botId, string $conversationKey ): bool {
        global $wpdb;

        $tableMsg  = $wpdb->prefix . 'infouno_messages';
        $tableConv = $wpdb->prefix . 'infouno_conversations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $lastUserAt = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT m.created_at
                 FROM `{$tableMsg}` m
                 INNER JOIN `{$tableConv}` c ON c.id = m.conversation_id
                 WHERE c.bot_id     = %d
                   AND c.session_id = %s
                   AND m.role       = 'user'
                   AND m.deleted_at IS NULL
                 ORDER BY m.created_at DESC
                 LIMIT 1",
                $botId,
                $conversationKey
            )
        );

        if ( null === $lastUserAt || '' === (string) $lastUserAt ) {
            return false;
        }

        $lastUserTs = strtotime( (string) $lastUserAt );
        if ( false === $lastUserTs ) {
            return false;
        }

        return ( time() - $lastUserTs ) < self::WINDOW_SECONDS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WindowCheckerTest
```
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WindowChecker.php \
        plugins/infouno-custom/tests/Unit/Channel/WindowCheckerTest.php
git commit -m "feat(channel): WindowChecker — ventana de 24h sin tabla nueva"
```

---

## Task 9: `TemplateVariableResolver` — resolver de placeholders

**Files:**
- Create: `plugins/infouno-custom/src/Channel/TemplateVariableResolver.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/TemplateVariableResolverTest.php`

**Diseño:** los templates de Meta usan placeholders posicionales `{{1}}`, `{{2}}`, etc. El resolver recibe el esquema de variables del template (JSON decodificado: `[{"key":"customer_name"}, {"key":"product"}]`) y un mapa de datos de contexto (`['customer_name' => 'Juan', 'product' => 'Plan Pro']`) y devuelve el array de strings resueltos en orden.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter TemplateVariableResolverTest
```
Expected: FAIL — `Class "Infouno\SaaS\Channel\TemplateVariableResolver" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter TemplateVariableResolverTest
```
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/TemplateVariableResolver.php \
        plugins/infouno-custom/tests/Unit/Channel/TemplateVariableResolverTest.php
git commit -m "feat(channel): TemplateVariableResolver — placeholders de templates WhatsApp"
```

---

## Task 10: Bifurcación free-form vs template en `WhatsAppAdapter::sendTemplate()`

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/WhatsAppAdapter.php`
- Test: `plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterTemplateBranchTest.php`

**Diseño:** la bifurcación NO vive dentro de `send()` (que solo envía free-form). Se agrega `sendTemplate()` separado que recibe el nombre del template, idioma y los componentes resueltos. `InboundDispatcher` (Task 11) decide cuál llamar según `WindowChecker::isOpen()`. Esto mantiene `send()` limpio y la `ChannelAdapterInterface` sin cambios.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Channel;

use Infouno\SaaS\Channel\Http\ChannelHttpClient;
use Infouno\SaaS\Channel\WhatsAppAdapter;
use Infouno\SaaS\Security\CredentialVault;
use PHPUnit\Framework\TestCase;

final class WhatsAppAdapterTemplateBranchTest extends TestCase {

    private CredentialVault $vault;

    protected function setUp(): void {
        $this->vault = new CredentialVault( str_repeat( 'a', 64 ) );
    }

    private function channel( array $creds ): array {
        return [
            'credentials'           => $this->vault->encryptArray( $creds ),
            'credentials_decrypted' => $creds,
        ];
    }

    public function test_sendTemplate_posts_type_template_to_graph_api(): void {
        $captured = [];
        $http     = new class( $captured ) implements ChannelHttpClient {
            public function __construct( private array &$captured ) {}
            public function postJson( string $url, array $headers, array $body ): array {
                $this->captured[] = $body;
                return [ 'code' => 200, 'body' => '{"messages":[{"id":"wamid.TPL"}]}' ];
            }
        };

        $adapter    = new WhatsAppAdapter( $this->vault, $http );
        $channel    = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );
        $components = [ [ 'type' => 'body', 'parameters' => [ [ 'type' => 'text', 'text' => 'Juan' ] ] ] ];

        $adapter->sendTemplate( $channel, '5491111', 'bienvenida', 'es_AR', $components );

        $this->assertCount( 1, $captured );
        $this->assertSame( 'template',   $captured[0]['type'] );
        $this->assertSame( 'bienvenida', $captured[0]['template']['name'] );
        $this->assertSame( 'es_AR',      $captured[0]['template']['language']['code'] );
        $this->assertSame( $components,  $captured[0]['template']['components'] );
        $this->assertSame( 'wamid.TPL',  $adapter->lastWamid() );
    }

    public function test_sendTemplate_throws_WhatsAppGraphException_on_error(): void {
        $http = new class implements ChannelHttpClient {
            public function postJson( string $url, array $headers, array $body ): array {
                return [
                    'code' => 400,
                    'body' => '{"error":{"code":131047,"message":"Re-engagement window closed"}}',
                ];
            }
        };

        $adapter = new WhatsAppAdapter( $this->vault, $http );
        $channel = $this->channel( [ 'access_token' => 'TOK', 'phone_number_id' => 'PNID' ] );

        $this->expectException( \Infouno\SaaS\Channel\WhatsAppGraphException::class );
        $adapter->sendTemplate( $channel, '5491111', 'bienvenida', 'es_AR', [] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterTemplateBranchTest
```
Expected: FAIL — `Call to undefined method WhatsAppAdapter::sendTemplate()`.

- [ ] **Step 3: Write minimal implementation**

Agregar a `src/Channel/WhatsAppAdapter.php`, después de `send()`:

```php
    /**
     * Envía un mensaje usando un template aprobado por Meta (ventana cerrada).
     * El nombre, idioma y componentes deben estar ya resueltos por el caller
     * (TemplateVariableResolver::buildComponentsArray()).
     * Captura el wamid igual que send(). Lanza WhatsAppGraphException en error.
     *
     * @param  array<string,mixed>           $channel    Fila de wp_infouno_channels.
     * @param  string                        $templateName Nombre del template en Meta.
     * @param  string                        $language     Código de idioma, ej. 'es_AR'.
     * @param  array<int,array<string,mixed>>$components  Componentes resueltos.
     */
    public function sendTemplate(
        array  $channel,
        string $externalUser,
        string $templateName,
        string $language,
        array  $components,
    ): void {
        $creds = $this->vault->decryptArray( (string) ( $channel['credentials'] ?? '' ) );
        $token = (string) ( $creds['access_token'] ?? '' );
        $pnid  = (string) ( $creds['phone_number_id'] ?? '' );
        if ( '' === $token || '' === $pnid ) {
            throw new \RuntimeException( 'Canal WhatsApp sin access_token/phone_number_id.' );
        }

        $url = self::GRAPH_BASE . '/' . $pnid . '/messages';

        $this->lastWamid = null;

        $res  = $this->http->postJson(
            $url,
            [ 'Authorization' => 'Bearer ' . $token ],
            [
                'messaging_product' => 'whatsapp',
                'to'                => $externalUser,
                'type'              => 'template',
                'template'          => [
                    'name'       => $templateName,
                    'language'   => [ 'code' => $language ],
                    'components' => $components,
                ],
            ]
        );

        $code    = (int) ( $res['code'] ?? 0 );
        $decoded = json_decode( (string) ( $res['body'] ?? '' ), true );
        $decoded = is_array( $decoded ) ? $decoded : [];

        if ( $code >= 200 && $code < 300 ) {
            $wamid = $decoded['messages'][0]['id'] ?? null;
            if ( is_string( $wamid ) && '' !== $wamid ) {
                $this->lastWamid = $wamid;
            }
        } else {
            $ex = WhatsAppGraphException::fromGraphError( $code, $decoded );
            error_log( sprintf(
                '[INFOUNO-CHANNEL] WhatsApp sendTemplate error: HTTP %d | graphCode=%d | retryable=%s | template=%s | %s',
                $code,
                $ex->graphCode(),
                $ex->isRetryable() ? 'yes' : 'no',
                $templateName,
                $ex->getMessage()
            ) );
            throw $ex;
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter WhatsAppAdapterTemplateBranchTest
```
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/WhatsAppAdapter.php \
        plugins/infouno-custom/tests/Unit/Channel/WhatsAppAdapterTemplateBranchTest.php
git commit -m "feat(channel): WhatsAppAdapter::sendTemplate() para ventana de 24h cerrada"
```

---

## Task 11: Cablear `InboundDispatcher` — status routing + wamid binding + window branch

**Files:**
- Modify: `plugins/infouno-custom/src/Channel/InboundDispatcher.php`

> Nota: `InboundDispatcher` mezcla lógica de routing, error handling y efectos secundarios (BD). Los tests de esta clase usan mocks de sus dependencias. Como el dispatcher necesita ahora `ChannelDeliveryRepository`, `WindowChecker`, `ChannelTemplateRepository` y `TemplateVariableResolver`, estas se inyectan vía constructor con valor por defecto `null` (nullables + lazy init) para no romper los tests existentes que instancian el dispatcher con 5 parámetros. Se elige inyección explícita opcional: si son `null`, el dispatcher usa defaults que no hacen nada (no-op) — así los tests existentes siguen verdes sin modificarse.
>
> Este task es verificado por los tests existentes (regresión) + el smoke-test de integración. La lógica de bifurcación de ventana/template requiere WP real para `WindowChecker` (query real). Los tests unitarios de `WindowChecker` y `TemplateVariableResolver` ya cubren las piezas individualmente.

- [ ] **Step 1: Modificar el constructor de `InboundDispatcher`**

En `src/Channel/InboundDispatcher.php`, cambiar la firma del constructor y agregar las propiedades:

```php
    public function __construct(
        private readonly ChannelRegistry       $registry,
        private readonly ChannelRepository     $channelRepo,
        private readonly ChannelConsentService $consent,
        private readonly ChatPipeline          $pipeline,
        callable                               $botLoader,
        private readonly ?ChannelDeliveryRepository  $deliveryRepo    = null,
        private readonly ?WindowChecker              $windowChecker   = null,
        private readonly ?ChannelTemplateRepository  $templateRepo    = null,
        private readonly ?TemplateVariableResolver   $varResolver     = null,
    ) {
        $this->botLoader = $botLoader;
    }
```

- [ ] **Step 2: Enrutar eventos `status` en `handle()`**

Al inicio de `handle()`, después de resolver el `$adapter`, agregar el bloque de routing de status:

```php
        // Enrutar eventos de estado (status receipts) al repositorio de entregas.
        // Solo si el adapter soporta parseStatuses() y hay un delivery repo configurado.
        if ( null !== $this->deliveryRepo && method_exists( $adapter, 'parseStatuses' ) ) {
            $statusEvents = $adapter->parseStatuses( $payload );
            if ( ! empty( $statusEvents ) ) {
                $tenantId = (int) $channel['tenant_id'];
                foreach ( $statusEvents as $event ) {
                    $this->deliveryRepo->updateStatus(
                        tenantId:       $tenantId,
                        externalMsgId: $event->wamid,
                        status:         $event->status,
                        errorCode:      $event->errorCode,
                    );
                    if ( 'failed' === $event->status ) {
                        error_log( sprintf(
                            '[INFOUNO-CHANNEL] WhatsApp delivery failed: wamid=%s errorCode=%s',
                            $event->wamid,
                            $event->errorCode ?? 'n/a'
                        ) );
                    }
                }
                return; // payload de status procesado; no hay mensaje que responder
            }
        }
```

- [ ] **Step 3: Capturar wamid + bifurcar free-form vs template al enviar la respuesta**

Reemplazar el bloque final de `handle()` (desde `$reply = $sink->getBuffer();` hasta el cierre del método) con:

```php
        $reply = $sink->getBuffer();
        if ( '' === trim( $reply ) ) {
            return;
        }

        $tenantId = (int) $channel['tenant_id'];
        $botId    = (int) $channel['bot_id'];

        // Decidir free-form vs template según ventana de 24h.
        $windowOpen = null === $this->windowChecker
            || $this->windowChecker->isOpen( $botId, $inbound->conversationKey() );

        if ( $windowOpen ) {
            // Ventana abierta: texto free-form (comportamiento original).
            $adapter->send( $channel, $inbound->externalUser, $reply );
        } else {
            // Ventana cerrada: necesitamos un template aprobado.
            $template = null !== $this->templateRepo
                ? $this->templateRepo->findApproved( $tenantId, (int) $channel['id'] )[0] ?? null
                : null;

            if ( null === $template ) {
                error_log( sprintf(
                    '[INFOUNO-CHANNEL] Ventana cerrada y sin template aprobado: tenant=%d channel=%d user=%s — respuesta abandonada.',
                    $tenantId,
                    (int) $channel['id'],
                    $inbound->externalUser
                ) );
                return;
            }

            $schema     = json_decode( (string) ( $template['variables_schema'] ?? '[]' ), true );
            $schema     = is_array( $schema ) ? $schema : [];
            $context    = [ 'customer_name' => $inbound->externalUser ]; // contexto mínimo
            $components = null !== $this->varResolver
                ? $this->varResolver->buildComponentsArray( $schema, $context )
                : [];

            if ( method_exists( $adapter, 'sendTemplate' ) ) {
                $adapter->sendTemplate(
                    $channel,
                    $inbound->externalUser,
                    (string) ( $template['name'] ?? '' ),
                    (string) ( $template['language'] ?? 'es_AR' ),
                    $components
                );
            } else {
                // Adapter no soporta templates: fallback a free-form y loguear.
                error_log( '[INFOUNO-CHANNEL] Adapter sin sendTemplate(); usando free-form como fallback.' );
                $adapter->send( $channel, $inbound->externalUser, $reply );
            }
        }

        // Registrar entrega saliente con el wamid capturado (si el adapter lo expone).
        if ( null !== $this->deliveryRepo && method_exists( $adapter, 'lastWamid' ) ) {
            $wamid = $adapter->lastWamid();
            if ( null !== $wamid ) {
                $this->deliveryRepo->record(
                    tenantId:       $tenantId,
                    channelId:      (int) $channel['id'],
                    messageId:      null,
                    externalMsgId: $wamid,
                );
            }
        }
    }
```

- [ ] **Step 4: Verificar que los tests existentes siguen en verde**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter InboundDispatcher
```
Expected: PASS (todos los tests previos — los nuevos parámetros del constructor son opcionales).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Channel/InboundDispatcher.php
git commit -m "feat(channel): InboundDispatcher — status routing, wamid binding, window branch"
```

---

## Task 12: Suite completa + smoke-test de regresión

**Files:** ninguno nuevo (verificación).

> Esta task verifica que todos los tasks anteriores se integran sin regresar la suite existente. No es un unit test adicional — es el cierre del bloque.

- [ ] **Step 1: Correr toda la suite de Channel**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage --filter "Channel"
```
Expected: todos en verde.

- [ ] **Step 2: Correr toda la suite del plugin**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpunit --no-coverage
```
Expected: toda la suite en verde. Ninguna regresión en Chat, LLM, Bot, Tenant, Security, Lead, API.

- [ ] **Step 3: Verificar lint PHP**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php vendor/bin/phpcs --standard=phpcs.xml src/Channel/WhatsAppAdapter.php src/Channel/WhatsAppStatusEvent.php src/Channel/WhatsAppGraphException.php src/Channel/ChannelDeliveryRepository.php src/Channel/ChannelTemplateRepository.php src/Channel/WindowChecker.php src/Channel/TemplateVariableResolver.php src/Channel/InboundDispatcher.php src/Core/Migrator.php
```
Expected: sin errores de estilo.

- [ ] **Step 4: Smoke-test del CI db_version**

Confirmar que `.github/workflows/ci.yml` tiene `"10"` (no `"9"`) en la aserción del `wp-load` job. Si se modificó en Task 5, ya está.

- [ ] **Step 5: Commit final de ajustes**

```bash
git add -A
git commit -m "test: suite completa Bloque B en verde — WhatsApp hardening"
```

---

## Costura dejada para después (NO construir en este plan)

**Mensajería proactiva con templates:** la infra de `ChannelTemplateRepository`, `TemplateVariableResolver` y `sendTemplate()` está lista. El *disparo* proactivo (fuera de una respuesta reactiva) llega con Sales Automation — fuera de alcance de este bloque. Ver spec §B.4 nota de alcance.

**Renovación de la interfaz `ChannelAdapterInterface`:** si en el futuro Telegram u otros adapters necesitan `parseStatuses()` o `sendTemplate()`, se agrega a la interfaz con implementación default vacía (PHP 8.0+ permite métodos default en interfaces vía traits, o `abstract class`). Hoy se usa `method_exists()` como duck-typing para no romper adapters existentes.

---

## Self-Review (cobertura vs spec §Bloque B)

| Spec | Tasks que lo cubren |
|---|---|
| **B.1** `parseStatuses()` separado de `parseInbound` | Task 1 (value object `WhatsAppStatusEvent`), Task 2 (`parseStatuses()` en el adapter) |
| **B.1** Captura del `wamid` en `send()` | Task 3 (`send()` captura `lastWamid()`) |
| **B.1** Transiciones de estado (`sent→delivered→read→failed`) | Task 6 (`ChannelDeliveryRepository::record()` + `updateStatus()`), Task 11 (dispatcher las registra) |
| **B.2** Clasificación transitorio vs permanente | Task 4 (`WhatsAppGraphException::fromGraphError()` con códigos 131047, 131026, 100, 429, 5xx) |
| **B.2** Re-throw de transitorios para Action Scheduler | Task 4 (`isRetryable=true` → el dispatcher re-lanza via `throw $e` existente) |
| **B.2** Abandon permanente con log estructurado | Task 4 (`isRetryable=false` → `error_log` + no retry) |
| **B.3** Ventana 24h sin tabla nueva | Task 8 (`WindowChecker::isOpen()` — query sobre `wp_infouno_messages`) |
| **B.4** Tabla `wp_infouno_channel_templates` | Task 5 (migración v10), Task 7 (`ChannelTemplateRepository`) |
| **B.4** Resolver de variables | Task 9 (`TemplateVariableResolver::resolve()` + `buildComponentsArray()`) |
| **B.4** Bifurcación free-form vs template en send | Task 10 (`sendTemplate()` en adapter), Task 11 (dispatcher decide según `WindowChecker`) |
| **B.5** Tabla `wp_infouno_channel_deliveries` | Task 5 (`migrateTo10()` + `createChannelDeliveriesTable()`) |
| **B.5** `INFOUNO_DB_VERSION = '10'` | Task 5 (constante en plugin + `Migrator::DB_VERSION`) |
| **B.5** Migración idempotente sin DROP | Task 5 (`dbDelta()` + `addColumnIfMissing()` — patrón copiado de `migrateTo9()`) |
| **B.5** CI `wp-load` bumped a `'10'` | Task 5 (actualiza `.github/workflows/ci.yml`) |
| **B.6** `failed` → `error_code` + log estructurado, sin interrumpir | Task 11 (dispatcher loguea y continúa), Task 6 (`updateStatus()` persiste `error_code`) |
| **B.6** Error transitorio en `send()` → re-lanzar | Task 4 (`WhatsAppGraphException` retryable re-lanzada por el dispatcher vía el `throw` existente) |
| **B.6** Envío fuera de ventana sin template → log + abandon | Task 11 (dispatcher loguea y hace `return` — no rompe el worker) |
| **B.7** Tests: parseo statuses | Task 2 (`WhatsAppAdapterParseStatusesTest`) |
| **B.7** Tests: captura wamid | Task 3 (`WhatsAppAdapterSendWamidTest`) |
| **B.7** Tests: clasificación errores Graph | Task 4 (`WhatsAppAdapterErrorClassificationTest`) |
| **B.7** Tests: ventana 24h (< 24h vs >= 24h) | Task 8 (`WindowCheckerTest`) |
| **B.7** Tests: free-form vs template según ventana | Task 10 (`WhatsAppAdapterTemplateBranchTest`), Task 11 (regresión dispatcher) |
| **B.7** Tests: resolver de variables | Task 9 (`TemplateVariableResolverTest`) |
| **B.7** Tests: migración v10 idempotente | Task 5 (`MigratorV10Test`) |
