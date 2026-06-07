<?php

namespace Lily\Services;

/**
 * Class TelegramNotifier
 *
 * Handles sending notifications via the Telegram Bot API.
 *
 * @package Lily\Services
 */
class TelegramNotifier
{
    /**
     * @var string The Telegram bot token.
     */
    private string $botToken;

    /**
     * @var string The destination chat ID.
     */
    private string $chatId;

    /**
     * TelegramNotifier constructor.
     *
     * @param string $botToken The Telegram bot token.
     * @param string $chatId The ID of the chat to send messages to.
     */
    public function __construct(string $botToken, string $chatId)
    {
        $this->botToken = $botToken;
        $this->chatId = $chatId;
    }

    /**
     * Send a text message to the configured chat.
     *
     * @param string $message The text message to send.
     * @return bool True on success, false on failure.
     */
    public function sendMessage(string $message): bool
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $data = [
            'chat_id' => $this->chatId,
            'text' => $message,
        ];
        
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
            ],
        ];
        
        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        return $result !== false;
    }
}
