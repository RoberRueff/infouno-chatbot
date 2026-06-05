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
        $tenantManager->method( 'reserve' )->willReturn( true );
        // Nuevo flujo: reserva pre-LLM + reconcile al consumo real (10 + 20 = 30).
        $tenantManager->expects( $this->once() )->method( 'reconcile' )->with( 3, $this->greaterThan( 0 ), 30 );

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
                return new StreamResult( 10, 20, 'stop', 'openai', 'gpt-4o-mini' );
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
