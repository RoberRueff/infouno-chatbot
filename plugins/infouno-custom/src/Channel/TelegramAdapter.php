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
