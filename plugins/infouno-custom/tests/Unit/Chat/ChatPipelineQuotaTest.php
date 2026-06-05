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

final class ChatPipelineQuotaTest extends TestCase {

    private function bot(): array {
        return [
            'id'            => 7,
            'tenant_id'     => 3,
            'system_prompt' => 'Sos un asistente.',
            'settings'      => [ 'context_window' => 10, 'max_conv_tokens' => 20000, 'max_tokens' => 1024 ],
        ];
    }

    private function convRepo(): ConversationRepository {
        $repo = $this->createMock( ConversationRepository::class );
        $repo->method( 'getOrCreate' )->willReturn( [ 'id' => 99 ] );
        $repo->method( 'totalTokensForConversation' )->willReturn( 0 );
        $repo->method( 'getRecentMessages' )->willReturn( [] );
        return $repo;
    }

    public function test_reserves_before_llm_and_reconciles_to_actual(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        $tm->expects( $this->once() )->method( 'reconcile' )
           ->with( 3, $this->greaterThan( 0 ), 30 );   // actual = 10 + 20
        $tm->expects( $this->never() )->method( 'release' );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $msgs, $cb ) { $cb( 'ok' ); return new StreamResult( 10, 20, 'stop', 'anthropic', 'm' ); }
        );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_rejects_402_when_reserve_fails_without_calling_llm(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( false );

        $llm = $this->createMock( LLMRouter::class );
        $llm->expects( $this->never() )->method( 'stream' );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 402 );
        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_releases_reservation_when_llm_throws(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        $tm->expects( $this->once() )->method( 'release' )->with( 3, $this->greaterThan( 0 ) );
        $tm->expects( $this->never() )->method( 'reconcile' );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willThrowException( new \RuntimeException( 'IA caída', 503 ) );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $this->expectException( \RuntimeException::class );
        $this->expectExceptionCode( 503 );
        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }

    public function test_charges_estimate_when_usage_missing_but_text_emitted(): void {
        $tm = $this->createMock( TenantManager::class );
        $tm->method( 'validateForChat' )->willReturn( [ 'plan' => 'free' ] );
        $tm->method( 'reserve' )->willReturn( true );
        // result con 0 tokens pero hubo texto → reconcile con actual > 0
        $tm->expects( $this->once() )->method( 'reconcile' )
           ->with( 3, $this->greaterThan( 0 ), $this->greaterThan( 0 ) );

        $llm = $this->createMock( LLMRouter::class );
        $llm->method( 'stream' )->willReturnCallback(
            function ( $bot, $msgs, $cb ) { $cb( 'respuesta con texto real' ); return new StreamResult( 0, 0, 'stop', 'anthropic', 'm' ); }
        );

        $pipeline = new ChatPipeline( $tm, $this->createMock( BotManager::class ),
            $this->createMock( QuotaService::class ), $this->convRepo(), $llm, null );

        $pipeline->run( $this->bot(), 'tg:1', 'hola', new BufferedSink(), PipelineContext::forChannel( 'telegram', '1' ) );
    }
}
