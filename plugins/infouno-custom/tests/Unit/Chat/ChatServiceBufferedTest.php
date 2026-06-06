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
