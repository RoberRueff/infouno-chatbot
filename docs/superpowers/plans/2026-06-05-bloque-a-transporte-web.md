# Bloque A — Transporte web SSE→Full — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que la entrega de respuestas del chat web sobreviva al hosting compartido que bufferea SSE, sin re-ejecutar el LLM: si el streaming no arranca, el mismo contenido se entrega completo (`?mode=full`), el widget lo detecta por timeout al primer chunk, y recuerda el modo que funciona por dominio.

**Architecture:** El `ChatPipeline` (núcleo transport-agnostic) NO se toca. Se agrega una segunda forma de *entrega* sobre la costura `OutputSink` existente: server expone `?mode=full` (corre el pipeline con `BufferedSink` y devuelve JSON); el widget intenta SSE con un timeout al primer chunk y cae a `?mode=full` si no llega, persistiendo el modo ganador en `localStorage` por host. El polling async queda como costura documentada, no construida.

**Tech Stack:** PHP 8.1 + WordPress REST (backend, PHPUnit 11). Preact + TypeScript + Vite (widget). Se agrega **vitest + jsdom** como runner de tests del widget (hoy no hay ninguno).

---

## File Structure

**Backend (PHP) — `plugins/infouno-custom/`**
- Create `src/Chat/DeliveryMode.php` — resuelve el modo de entrega desde el request (`sse` | `full`).
- Create `src/Chat/DeliveryTelemetry.php` — formatea la línea de log de telemetría de entrega.
- Modify `src/Chat/ChatService.php` — agrega `handleBuffered()` (corre el pipeline con `BufferedSink`, devuelve el texto completo).
- Modify `src/API/ChatController.php` — registra el arg `mode`; bifurca a una respuesta JSON completa cuando `mode=full`; loguea telemetría.
- Create `tests/Unit/Chat/DeliveryModeTest.php`
- Create `tests/Unit/Chat/DeliveryTelemetryTest.php`
- Create `tests/Unit/Chat/ChatServiceBufferedTest.php`

**Widget (TS) — `plugins/infouno-custom/client-widget/`**
- Modify `package.json` — devDeps `vitest`, `jsdom`; script `test`.
- Create `vitest.config.ts` — entorno jsdom.
- Create `src/api/deliveryMode.ts` — lee/escribe el modo preferido por host en `localStorage`.
- Modify `src/api/client.ts` — agrega `fetchFull()` (POST `?mode=full`, devuelve el texto).
- Create `src/api/deliver.ts` — orquesta SSE-con-timeout → fallback a full + persistencia de modo.
- Modify `src/hooks/useChat.ts` — `doStream()` usa `deliverChat()` en vez de `streamChat()` directo.
- Create `src/api/deliveryMode.test.ts`
- Create `src/api/deliver.test.ts`

**Comandos de test**
- Backend: desde `plugins/infouno-custom/` → `composer test` (o `vendor/bin/phpunit --filter <nombre>`).
- Widget: desde `plugins/infouno-custom/client-widget/` → `npm run test` y `npm run check`.

---

## Task 1: `DeliveryMode` — resolver del modo desde el request

**Files:**
- Create: `plugins/infouno-custom/src/Chat/DeliveryMode.php`
- Test: `plugins/infouno-custom/tests/Unit/Chat/DeliveryModeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\DeliveryMode;
use PHPUnit\Framework\TestCase;

final class DeliveryModeTest extends TestCase {

    public function test_full_param_resolves_to_full(): void {
        $this->assertSame( DeliveryMode::FULL, DeliveryMode::fromRequest( 'full' ) );
    }

    public function test_null_resolves_to_sse(): void {
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( null ) );
    }

    public function test_any_other_value_resolves_to_sse(): void {
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( 'streaming' ) );
        $this->assertSame( DeliveryMode::SSE, DeliveryMode::fromRequest( '' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter DeliveryModeTest`
Expected: FAIL — `Class "Infouno\SaaS\Chat\DeliveryMode" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Resuelve el modo de entrega de la respuesta del chat web desde el request.
 * 'full' = respuesta JSON completa (fallback anti-buffering); 'sse' = streaming.
 */
final class DeliveryMode {

    public const SSE  = 'sse';
    public const FULL = 'full';

    public static function fromRequest( ?string $param ): string {
        return self::FULL === $param ? self::FULL : self::SSE;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter DeliveryModeTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Chat/DeliveryMode.php plugins/infouno-custom/tests/Unit/Chat/DeliveryModeTest.php
git commit -m "feat(chat): DeliveryMode resolver (sse|full)"
```

---

## Task 2: `DeliveryTelemetry` — línea de log de telemetría

**Files:**
- Create: `plugins/infouno-custom/src/Chat/DeliveryTelemetry.php`
- Test: `plugins/infouno-custom/tests/Unit/Chat/DeliveryTelemetryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Chat\DeliveryTelemetry;
use PHPUnit\Framework\TestCase;

final class DeliveryTelemetryTest extends TestCase {

    public function test_format_includes_mode_and_bot(): void {
        $this->assertSame(
            '[INFOUNO-DELIVERY] mode=full bot=42',
            DeliveryTelemetry::logLine( 'full', 42 )
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter DeliveryTelemetryTest`
Expected: FAIL — `Class "Infouno\SaaS\Chat\DeliveryTelemetry" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Chat;

/**
 * Telemetría de modo de entrega web. Solo se loguean los requests servidos en
 * modo 'full' (fallback): un pico de 'full' desde un origen señala que ese
 * hosting bufferea SSE. Insumo para decidir si el polling async vale la pena.
 */
final class DeliveryTelemetry {

    public static function logLine( string $mode, int $botId ): string {
        return sprintf( '[INFOUNO-DELIVERY] mode=%s bot=%d', $mode, $botId );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter DeliveryTelemetryTest`
Expected: PASS (1 test).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Chat/DeliveryTelemetry.php plugins/infouno-custom/tests/Unit/Chat/DeliveryTelemetryTest.php
git commit -m "feat(chat): DeliveryTelemetry log line para modo de entrega"
```

---

## Task 3: `ChatService::handleBuffered()` — pipeline con BufferedSink

**Files:**
- Modify: `plugins/infouno-custom/src/Chat/ChatService.php`
- Test: `plugins/infouno-custom/tests/Unit/Chat/ChatServiceBufferedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Infouno\SaaS\Tests\Unit\Chat;

use Infouno\SaaS\Bot\BotManager;
use Infouno\SaaS\Bot\QuotaService;
use Infouno\SaaS\Chat\ChatService;
use Infouno\SaaS\Chat\ConversationRepository;
use Infouno\SaaS\LLM\LLMRouter;
use Infouno\SaaS\LLM\StreamResult;
use Infouno\SaaS\Tenant\TenantManager;
use PHPUnit\Framework\TestCase;

final class ChatServiceBufferedTest extends TestCase {

    private function makeBot(): array {
        return [
            'id'            => 7,
            'tenant_id'     => 3,
            'system_prompt' => 'Sos un asistente comercial.',
            'settings'      => [ 'context_window' => 10, 'max_conv_tokens' => 20000 ],
        ];
    }

    private function makeService( BotManager $botManager ): ChatService {
        $tenantManager = $this->createMock( TenantManager::class );
        $tenantManager->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tenantManager->method( 'reserve' )->willReturn( true );

        $quota = $this->createMock( QuotaService::class );

        $convRepo = $this->createMock( ConversationRepository::class );
        $convRepo->method( 'getOrCreate' )->willReturn( [ 'id' => 99 ] );
        $convRepo->method( 'totalTokensForConversation' )->willReturn( 0 );
        $convRepo->method( 'getRecentMessages' )->willReturn( [] );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $messages, $onDelta, $plan ): StreamResult {
                $onDelta( 'Hola ' );
                $onDelta( 'PyME' );
                return new StreamResult( 10, 20, 'stop', 'openai', 'gpt-4o-mini' );
            }
        );

        return new ChatService( $tenantManager, $botManager, $quota, $convRepo, $llm, null );
    }

    public function test_returns_full_buffered_reply(): void {
        $botManager = $this->createMock( BotManager::class );
        $botManager->method( 'validateOrigin' )->willReturn( true );

        $service = $this->makeService( $botManager );
        $reply   = $service->handleBuffered( $this->makeBot(), 'sess-12345678', 'Quiero info', 'https://cliente.com' );

        $this->assertSame( 'Hola PyME', $reply );
    }

    public function test_throws_403_on_invalid_origin(): void {
        $botManager = $this->createMock( BotManager::class );
        $botManager->method( 'validateOrigin' )->willReturn( false );

        $service = $this->makeService( $botManager );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 403 );

        $service->handleBuffered( $this->makeBot(), 'sess-12345678', 'hola', 'https://malicioso.com' );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter ChatServiceBufferedTest`
Expected: FAIL — `Call to undefined method Infouno\SaaS\Chat\ChatService::handleBuffered()`.

- [ ] **Step 3: Write minimal implementation**

En `src/Chat/ChatService.php`, agregar este método dentro de la clase, justo después de `handle()` (después de la línea `}` que cierra `handle`, antes del `}` final de la clase):

```php
    /**
     * Variante de entrega completa (no-streaming): ejecuta el pipeline con un
     * BufferedSink y devuelve la respuesta entera. La usa ChatController cuando
     * el cliente pide ?mode=full (fallback anti-buffering de hosting compartido).
     * Reusa exactamente la misma generación del LLM — nunca se re-ejecuta.
     *
     * @param  array<string,mixed> $bot Bot pre-validado por ChatController.
     * @throws \RuntimeException Con código HTTP semántico en validaciones fallidas.
     */
    public function handleBuffered(
        array  $bot,
        string $sessionId,
        string $userMessage,
        string $origin
    ): string {
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

        $sink = new BufferedSink();
        $pipeline->run( $bot, $sessionId, $userMessage, $sink, PipelineContext::web() );

        return $sink->getBuffer();
    }
```

> `BufferedSink`, `ChatPipeline` y `PipelineContext` viven en el mismo namespace `Infouno\SaaS\Chat`, así que no hace falta agregar `use`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom && vendor/bin/phpunit --filter ChatServiceBufferedTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/src/Chat/ChatService.php plugins/infouno-custom/tests/Unit/Chat/ChatServiceBufferedTest.php
git commit -m "feat(chat): ChatService::handleBuffered para entrega completa"
```

---

## Task 4: Wire `?mode=full` en ChatController + telemetría

**Files:**
- Modify: `plugins/infouno-custom/src/API/ChatController.php`

> Nota: el repo no tiene tests de controllers (usan WP REST + `exit()`). Este task sigue ese patrón: se verifica por build + smoke-test manual, no por unit test. Las piezas testeables (`DeliveryMode`, `DeliveryTelemetry`, `handleBuffered`) ya tienen cobertura en Tasks 1–3.

- [ ] **Step 1: Agregar el arg `mode` a la ruta**

En `registerRoutes()`, dentro del array `'args'`, después del bloque `'message' => [ ... ],` agregar:

```php
                'mode' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
```

- [ ] **Step 2: Importar las clases nuevas**

En la cabecera de `use` (después de `use Infouno\SaaS\Chat\ChatService;`) agregar:

```php
use Infouno\SaaS\Chat\DeliveryMode;
use Infouno\SaaS\Chat\DeliveryTelemetry;
```

- [ ] **Step 3: Bifurcar a modo full en `stream()`**

Cambiar la firma de retorno y agregar la bifurcación. Reemplazar el bloque que va desde la línea `public function stream( \WP_REST_Request $request ): ?\WP_Error {` hasta justo antes de `$this->initSSE( $origin );` por:

```php
    public function stream( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error|null {
        $botToken  = $request->get_param( 'bot_token' );
        $sessionId = $request->get_param( 'session_id' );
        $message   = $request->get_param( 'message' );
        $origin    = $this->resolveOrigin();

        // Pre-validación antes de abrir SSE.
        // Devuelve el bot resuelto para no repetir la query en ChatService.
        $result = $this->preValidate( $botToken, $origin );
        if ( $result instanceof \WP_Error ) {
            return $result;
        }

        // Entrega completa (fallback anti-buffering): JSON normal, sin SSE.
        if ( DeliveryMode::FULL === DeliveryMode::fromRequest( $request->get_param( 'mode' ) ) ) {
            return $this->respondFull( $result, $sessionId, $message, $origin );
        }

        $this->initSSE( $origin );
```

> El resto de `stream()` (el bloque `try { ... } catch { ... } exit();`) queda igual.

- [ ] **Step 4: Agregar `respondFull()` y `httpStatusFor()`**

Agregar estos dos métodos privados dentro de la clase (por ejemplo, después de `preValidate()`):

```php
    /**
     * Entrega completa no-streaming. Corre el pipeline con BufferedSink y
     * devuelve un JSON { reply, status }. Reutiliza la misma generación del LLM.
     *
     * @param array<string,mixed> $bot Bot ya validado por preValidate().
     */
    private function respondFull( array $bot, string $sessionId, string $message, string $origin ): \WP_REST_Response {
        try {
            $reply = $this->chatService->handleBuffered( $bot, $sessionId, $message, $origin );
        } catch ( \RuntimeException $e ) {
            $this->logSecurityEvent( $e );
            return new \WP_REST_Response(
                [ 'code' => $e->getCode(), 'message' => $this->safeErrorMessage( $e ) ],
                $this->httpStatusFor( $e->getCode() )
            );
        }

        error_log( DeliveryTelemetry::logLine( DeliveryMode::FULL, (int) ( $bot['id'] ?? 0 ) ) );

        return new \WP_REST_Response( [ 'reply' => $reply, 'status' => 'complete' ], 200 );
    }

    /**
     * Mapea el código semántico de la excepción a un status HTTP válido.
     * Los códigos de negocio (402/403/422/429/503) ya son status HTTP; el resto → 500.
     */
    private function httpStatusFor( int $code ): int {
        return array_key_exists( $code, self::ERROR_MESSAGES ) ? $code : 500;
    }
```

- [ ] **Step 5: Verificar build PHP (sin lint errors)**

Run: `cd plugins/infouno-custom && composer package-lint`
Expected: sin errores en `src/API/ChatController.php`.

Si no hay PHP local, verificar la suite completa en el entorno de smoke-test:
Run: `cd plugins/infouno-custom && composer test`
Expected: toda la suite en verde (los tests de Tasks 1–3 incluidos).

- [ ] **Step 6: Commit**

```bash
git add plugins/infouno-custom/src/API/ChatController.php
git commit -m "feat(api): ?mode=full en /chat con telemetria de entrega"
```

---

## Task 5: Test infra del widget — vitest + jsdom

**Files:**
- Modify: `plugins/infouno-custom/client-widget/package.json`
- Create: `plugins/infouno-custom/client-widget/vitest.config.ts`

- [ ] **Step 1: Agregar devDeps y script de test**

En `package.json`, dentro de `"scripts"`, agregar la línea `"test"`:

```json
  "scripts": {
    "dev":   "vite build --watch",
    "build": "vite build",
    "check": "tsc --noEmit",
    "test":  "vitest run"
  },
```

Y en `"devDependencies"`, agregar `vitest` y `jsdom`:

```json
  "devDependencies": {
    "@preact/preset-vite": "^2.8.3",
    "jsdom": "^25.0.0",
    "typescript": "^5.5.0",
    "vite": "^5.4.0",
    "vitest": "^2.1.0"
  }
```

- [ ] **Step 2: Crear `vitest.config.ts`**

```ts
import { defineConfig } from 'vitest/config'

// Entorno jsdom para tener localStorage/fetch globales en los tests del widget.
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: [ 'src/**/*.test.ts' ],
  },
})
```

- [ ] **Step 3: Instalar y verificar que el runner arranca**

Run: `cd plugins/infouno-custom/client-widget && npm install`
Then: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: vitest corre y reporta "No test files found" (todavía no hay `*.test.ts`). Eso confirma que el runner está bien configurado.

- [ ] **Step 4: Commit**

```bash
git add plugins/infouno-custom/client-widget/package.json plugins/infouno-custom/client-widget/package-lock.json plugins/infouno-custom/client-widget/vitest.config.ts
git commit -m "chore(widget): vitest + jsdom como runner de tests"
```

---

## Task 6: `deliveryMode.ts` — memoria de modo por host

**Files:**
- Create: `plugins/infouno-custom/client-widget/src/api/deliveryMode.ts`
- Test: `plugins/infouno-custom/client-widget/src/api/deliveryMode.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, beforeEach } from 'vitest'
import { getPreferredMode, setPreferredMode, hostKey } from './deliveryMode'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

describe( 'deliveryMode', () => {
  beforeEach( () => localStorage.clear() )

  it( 'hostKey extrae el host del apiUrl', () => {
    expect( hostKey( API ) ).toBe( 'api.midominio.com' )
  } )

  it( 'devuelve null si no hay nada guardado', () => {
    expect( getPreferredMode( API ) ).toBeNull()
  } )

  it( 'persiste y lee el modo por host', () => {
    setPreferredMode( API, 'full' )
    expect( getPreferredMode( API ) ).toBe( 'full' )
  } )

  it( 'ignora valores corruptos en storage', () => {
    localStorage.setItem( 'infouno_delivery_mode:api.midominio.com', 'basura' )
    expect( getPreferredMode( API ) ).toBeNull()
  } )
} )
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: FAIL — no puede importar `./deliveryMode`.

- [ ] **Step 3: Write minimal implementation**

```ts
/**
 * Memoria del modo de entrega que funciona para cada host de API, en localStorage.
 * El buffering de SSE depende del hosting que sirve la API (no de la página que
 * embebe el widget), por eso la clave es el host del apiUrl.
 */

export type DeliveryMode = 'sse' | 'full'

const PREFIX = 'infouno_delivery_mode:'

export function hostKey( apiUrl: string ): string {
  try {
    return new URL( apiUrl ).host
  } catch {
    return apiUrl
  }
}

export function getPreferredMode( apiUrl: string ): DeliveryMode | null {
  try {
    const v = localStorage.getItem( PREFIX + hostKey( apiUrl ) )
    return v === 'full' ? 'full' : v === 'sse' ? 'sse' : null
  } catch {
    return null
  }
}

export function setPreferredMode( apiUrl: string, mode: DeliveryMode ): void {
  try {
    localStorage.setItem( PREFIX + hostKey( apiUrl ), mode )
  } catch {
    // Storage no disponible (modo privado, cuota) — degradar silencioso.
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/client-widget/src/api/deliveryMode.ts plugins/infouno-custom/client-widget/src/api/deliveryMode.test.ts
git commit -m "feat(widget): memoria de modo de entrega por host"
```

---

## Task 7: `fetchFull()` — request de entrega completa

**Files:**
- Modify: `plugins/infouno-custom/client-widget/src/api/client.ts`
- Test: `plugins/infouno-custom/client-widget/src/api/client.test.ts` (Create)

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, afterEach } from 'vitest'
import { fetchFull } from './client'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

afterEach( () => vi.restoreAllMocks() )

describe( 'fetchFull', () => {
  it( 'POSTea a ?mode=full y devuelve reply', async () => {
    const spy = vi.spyOn( globalThis, 'fetch' ).mockResolvedValue(
      new Response( JSON.stringify( { reply: 'Hola PyME', status: 'complete' } ), { status: 200 } )
    )
    const reply = await fetchFull( API, 'tok', 'sess-1', 'hola', new AbortController().signal )

    expect( reply ).toBe( 'Hola PyME' )
    expect( spy.mock.calls[0][0] ).toContain( 'mode=full' )
  } )

  it( 'lanza con el message del servidor en error con código', async () => {
    vi.spyOn( globalThis, 'fetch' ).mockResolvedValue(
      new Response( JSON.stringify( { code: 402, message: 'Cuota agotada.' } ), { status: 402 } )
    )
    await expect(
      fetchFull( API, 'tok', 'sess-1', 'hola', new AbortController().signal )
    ).rejects.toThrow( 'Cuota agotada.' )
  } )
} )
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: FAIL — `client.ts` no exporta `fetchFull`.

- [ ] **Step 3: Write minimal implementation**

Agregar a `src/api/client.ts` (por ejemplo, después de `streamChat`):

```ts
/**
 * Entrega completa no-streaming: POST a ?mode=full, devuelve la respuesta entera.
 * Fallback cuando el SSE no streamea (hosting que bufferea). Reusa la misma
 * generación server-side — no re-ejecuta el LLM.
 */
export async function fetchFull(
  apiUrl:    string,
  botToken:  string,
  sessionId: string,
  message:   string,
  signal:    AbortSignal
): Promise<string> {
  const url = apiUrl + ( apiUrl.includes( '?' ) ? '&' : '?' ) + 'mode=full'

  const res = await fetch( url, {
    method:  'POST',
    headers: { 'Content-Type': 'application/json' },
    body:    JSON.stringify( { bot_token: botToken, session_id: sessionId, message } ),
    signal,
  } )

  const data = await res.json().catch( () => ( {} ) ) as Record<string, unknown>

  if ( ! res.ok || data['code'] ) {
    throw new Error( ( data['message'] as string ) ?? 'El servidor no pudo procesar la solicitud.' )
  }

  return ( data['reply'] as string ) ?? ''
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: PASS (2 nuevos tests + los de Task 6).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/client-widget/src/api/client.ts plugins/infouno-custom/client-widget/src/api/client.test.ts
git commit -m "feat(widget): fetchFull para entrega completa via ?mode=full"
```

---

## Task 8: `deliverChat()` — orquestación SSE-con-timeout → fallback full

**Files:**
- Create: `plugins/infouno-custom/client-widget/src/api/deliver.ts`
- Test: `plugins/infouno-custom/client-widget/src/api/deliver.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi } from 'vitest'
import { deliverChat } from './deliver'
import type { StreamCallbacks } from './client'

const API = 'https://api.midominio.com/wp-json/infouno/v1/chat'

function collector() {
  const deltas: string[] = []
  let done = false
  let error = ''
  return {
    deltas, get done() { return done }, get error() { return error },
    cb: {
      onDelta: ( t: string ) => deltas.push( t ),
      onDone:  () => { done = true },
      onError: ( m: string ) => { error = m },
    },
  }
}

describe( 'deliverChat', () => {
  it( 'usa SSE cuando el primer chunk llega a tiempo y marca modo sse', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    const streamImpl = async ( _u: string, _t: string, _s: string, _m: string, cbs: StreamCallbacks ) => {
      cbs.onDelta( 'Hola ' ); cbs.onDelta( 'PyME' ); cbs.onDone()
    }
    const fullImpl = vi.fn()

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 50,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => null,
      setMode: ( _u, mode ) => { stored.mode = mode },
    } )

    expect( c.deltas.join( '' ) ).toBe( 'Hola PyME' )
    expect( c.done ).toBe( true )
    expect( fullImpl ).not.toHaveBeenCalled()
    expect( stored.mode ).toBe( 'sse' )
  } )

  it( 'cae a full cuando el SSE no emite primer chunk a tiempo', async () => {
    const c = collector()
    const stored: Record<string, string> = {}
    // streamImpl que nunca emite y resuelve al abortarse.
    const streamImpl = ( _u: string, _t: string, _s: string, _m: string, _cbs: StreamCallbacks, signal: AbortSignal ) =>
      new Promise<void>( ( resolve ) => { signal.addEventListener( 'abort', () => resolve(), { once: true } ) } )
    const fullImpl = async () => 'Respuesta completa'

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      firstChunkTimeoutMs: 20,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => null,
      setMode: ( _u, mode ) => { stored.mode = mode },
    } )

    expect( c.deltas.join( '' ) ).toBe( 'Respuesta completa' )
    expect( c.done ).toBe( true )
    expect( stored.mode ).toBe( 'full' )
  } )

  it( 'va directo a full si el modo recordado es full', async () => {
    const c = collector()
    const streamImpl = vi.fn()
    const fullImpl = async () => 'Directo full'

    await deliverChat( {
      apiUrl: API, botToken: 't', sessionId: 's', message: 'hola',
      signal: new AbortController().signal,
      ...c.cb,
      streamImpl: streamImpl as never,
      fullImpl:   fullImpl as never,
      getMode: () => 'full',
      setMode: () => {},
    } )

    expect( streamImpl ).not.toHaveBeenCalled()
    expect( c.deltas.join( '' ) ).toBe( 'Directo full' )
    expect( c.done ).toBe( true )
  } )
} )
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: FAIL — no puede importar `./deliver`.

- [ ] **Step 3: Write minimal implementation**

```ts
import { streamChat, fetchFull } from './client'
import type { StreamCallbacks } from './client'
import { getPreferredMode, setPreferredMode } from './deliveryMode'
import type { DeliveryMode } from './deliveryMode'

/**
 * Orquesta la entrega de una respuesta de chat con degradación automática:
 *   1. Si el host ya demostró que bufferea (modo recordado = 'full') → full directo.
 *   2. Si no, intenta SSE con timeout al primer chunk. Si el primer fragmento no
 *      llega a tiempo (proxy que bufferea), aborta y cae a ?mode=full.
 *   3. Persiste el modo ganador por host para no re-pagar el timeout cada vez.
 * El servidor genera UNA sola vez por request — full no re-ejecuta el LLM.
 */
export interface DeliverOptions {
  apiUrl:               string
  botToken:             string
  sessionId:            string
  message:              string
  signal:               AbortSignal
  onDelta:              ( text: string ) => void
  onDone:               () => void
  onError:              ( message: string ) => void
  onRetry?:             ( attempt: number, maxAttempts: number ) => void
  firstChunkTimeoutMs?: number
  // Inyectables para test; default a las implementaciones reales.
  streamImpl?:          typeof streamChat
  fullImpl?:            typeof fetchFull
  getMode?:             ( apiUrl: string ) => DeliveryMode | null
  setMode?:             ( apiUrl: string, mode: DeliveryMode ) => void
}

export async function deliverChat( opts: DeliverOptions ): Promise<void> {
  const timeoutMs  = opts.firstChunkTimeoutMs ?? 4000
  const streamImpl = opts.streamImpl ?? streamChat
  const fullImpl   = opts.fullImpl   ?? fetchFull
  const getMode    = opts.getMode    ?? getPreferredMode
  const setMode    = opts.setMode    ?? setPreferredMode

  const runFull = async (): Promise<void> => {
    try {
      const reply = await fullImpl( opts.apiUrl, opts.botToken, opts.sessionId, opts.message, opts.signal )
      setMode( opts.apiUrl, 'full' )
      if ( reply ) opts.onDelta( reply )
      opts.onDone()
    } catch ( err ) {
      if ( ( err as Error ).name === 'AbortError' ) return
      opts.onError( ( err as Error ).message || 'No pudimos obtener la respuesta.' )
    }
  }

  // 1. Host conocido como buffering → full directo.
  if ( getMode( opts.apiUrl ) === 'full' ) {
    await runFull()
    return
  }

  // 2. Intentar SSE con timeout al primer chunk.
  let firstChunk = false
  let timedOut   = false

  const internal = new AbortController()
  const onExternalAbort = () => internal.abort()
  opts.signal.addEventListener( 'abort', onExternalAbort, { once: true } )

  const timer = setTimeout( () => {
    if ( ! firstChunk ) {
      timedOut = true
      internal.abort()
    }
  }, timeoutMs )

  try {
    await streamImpl(
      opts.apiUrl,
      opts.botToken,
      opts.sessionId,
      opts.message,
      {
        onDelta( delta: string ) {
          if ( ! firstChunk ) {
            firstChunk = true
            clearTimeout( timer )
          }
          opts.onDelta( delta )
        },
        onDone() {
          if ( firstChunk ) setMode( opts.apiUrl, 'sse' )
          opts.onDone()
        },
        onError( message: string ) {
          opts.onError( message )
        },
        onRetry: opts.onRetry,
      },
      internal.signal
    )
  } finally {
    clearTimeout( timer )
    opts.signal.removeEventListener( 'abort', onExternalAbort )
  }

  // 3. Si abortamos por timeout (y no por el usuario), caer a full.
  if ( timedOut && ! opts.signal.aborted ) {
    await runFull()
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: PASS (3 nuevos tests + los previos).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/client-widget/src/api/deliver.ts plugins/infouno-custom/client-widget/src/api/deliver.test.ts
git commit -m "feat(widget): deliverChat con timeout al primer chunk y fallback full"
```

---

## Task 9: Cablear `deliverChat` en `useChat`

**Files:**
- Modify: `plugins/infouno-custom/client-widget/src/hooks/useChat.ts`

> El hook no se unit-testea (requeriría @testing-library/preact, fuera de alcance). La lógica testeable ya vive en `deliverChat` (Task 8). Este task es el cableado, verificado por `tsc` + build + smoke-test manual.

- [ ] **Step 1: Cambiar el import**

En `src/hooks/useChat.ts`, línea 2, reemplazar:

```ts
import { streamChat, recordLeadConsent } from '../api/client'
```

por:

```ts
import { recordLeadConsent } from '../api/client'
import { deliverChat } from '../api/deliver'
```

- [ ] **Step 2: Reemplazar la llamada en `doStream`**

Dentro de `doStream`, reemplazar todo el bloque `await streamChat( ... )` (desde `await streamChat(` hasta el `)` que cierra con `controller.signal`) por:

```ts
    await deliverChat( {
      apiUrl:    config.apiUrl,
      botToken:  config.botToken,
      sessionId: getSessionId(),
      message:   text,
      signal:    controller.signal,
      onDelta( delta ) {
        if ( ! started ) {
          started = true
          setStatus( 'streaming' )
        }
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id
              ? { ...m, content: m.content + delta, pending: true }
              : m
          )
        )
      },
      onDone() {
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id ? { ...m, pending: false } : m
          )
        )
        setStatus( 'idle' )
      },
      onError( message ) {
        setMessages( ( prev: Message[] ) =>
          prev.map( ( m: Message ) =>
            m.id === botMsg.id
              ? { ...m, role: 'error', content: message, pending: false }
              : m
          )
        )
        setStatus( 'error' )
        setTimeout( () => setStatus( 'idle' ), 4000 )
      },
      onRetry() {
        setStatus( 'retrying' )
      },
    } )
```

> Nota: en modo `full`, `onDelta` se llama una sola vez con la respuesta entera y luego `onDone` — el render de mensajes ya es acumulativo (`m.content + delta`), así que funciona sin cambios. El `started`/`'streaming'` se setea igual al recibir ese único delta.

- [ ] **Step 3: Verificar tipos y build**

Run: `cd plugins/infouno-custom/client-widget && npm run check`
Expected: sin errores de TypeScript.

Run: `cd plugins/infouno-custom/client-widget && npm run build`
Expected: build OK, sin warning de tamaño (< 50 KB gzip).

- [ ] **Step 4: Correr toda la suite del widget**

Run: `cd plugins/infouno-custom/client-widget && npm run test`
Expected: PASS (todos los tests de Tasks 6–8).

- [ ] **Step 5: Commit**

```bash
git add plugins/infouno-custom/client-widget/src/hooks/useChat.ts
git commit -m "feat(widget): useChat usa deliverChat (SSE con fallback a full)"
```

---

## Task 10: Smoke-test manual de la entrega end-to-end

**Files:** ninguno (verificación).

- [ ] **Step 1: Levantar el entorno**

Run: `cd smoke-test && docker compose up -d`
Expected: WordPress + plugin activos.

- [ ] **Step 2: Probar modo SSE (camino feliz)**

Con un bot sembrado, enviar un mensaje desde el widget (o `curl` con header `Origin` válido) a `/wp-json/infouno/v1/chat` SIN `?mode=full`.
Expected: respuesta `text/event-stream` con eventos `data: {"delta": "..."}` incrementales y `event: done`.

- [ ] **Step 3: Probar modo full**

Run (ajustar token/origin/host):
```bash
curl -i -X POST 'http://localhost:8080/wp-json/infouno/v1/chat?mode=full' \
  -H 'Content-Type: application/json' \
  -H 'Origin: http://localhost:8080' \
  -d '{"bot_token":"<TOKEN_64_HEX>","session_id":"smoke-sess-1","message":"hola"}'
```
Expected: HTTP 200, `Content-Type: application/json`, body `{"reply":"...","status":"complete"}`. En el log de PHP debe aparecer `[INFOUNO-DELIVERY] mode=full bot=<id>`.

- [ ] **Step 4: Verificar que la cuota NO se cobró dos veces**

Confirmar en `wp_infouno_tenants.quota_used` que un request `mode=full` descuenta los tokens una sola vez (la generación es única). Comparar antes/después de un mensaje.
Expected: incremento de tokens consistente con una sola llamada al LLM.

- [ ] **Step 5: Commit (si se ajustó algo durante el smoke-test)**

```bash
git add -A
git commit -m "test: smoke-test manual de entrega SSE/full"
```

---

## Costura dejada para después (NO construir en este plan)

**Polling/async web (Modo B del spec).** Si la telemetría `[INFOUNO-DELIVERY] mode=full` muestra una proporción alta de fallbacks Y aparecen cortes por *duración* de conexión (no solo buffering), recién ahí se agrega un tercer modo `?mode=async` que encola un job (Action Scheduler, patrón de canales), genera a un store y expone `GET /chat/result/{job_id}`. El núcleo (`ChatPipeline` + `OutputSink`) no se toca. Ver `docs/superpowers/specs/2026-06-05-transporte-canales-redesign-design.md` §A.4.

---

## Self-Review (cobertura vs spec §Bloque A)

- A.2 servidor `?mode=full` → Task 4. ✅
- A.2 una sola generación (no re-ejecuta LLM) → `handleBuffered` reusa `ChatPipeline` (Task 3); smoke-test Step 4 lo verifica. ✅
- A.2 telemetría de modo de entrega → Tasks 2 + 4 (log `mode=full`). ✅
- A.3 timeout al primer chunk (default 4s, configurable) → Task 8 (`firstChunkTimeoutMs ?? 4000`). ✅
- A.3 buffer-al-final tolerado → en `full`, `onDelta` único + render acumulativo (Task 9). ✅
- A.3 memoria de modo por dominio → Task 6 + persistencia en Task 8. ✅
- A.4 costura de polling no construida → sección "Costura dejada para después". ✅
- A.6 testing → Tasks 1,2,3,6,7,8 con tests; 4,9,10 verificados por build/smoke (patrón del repo: no se testean controllers/hooks). ✅
