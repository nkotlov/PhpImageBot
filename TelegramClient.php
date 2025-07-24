<?php
namespace App;

class TelegramClient
{
    private string $token, $apiUrl;

    public function __construct(array $cfg)
    {
        $this->token  = $cfg['bot_token'];
        $this->apiUrl = $cfg['api_url'];
    }

    private function request(string $method, array $params = []): array
    {
        $url = sprintf($this->apiUrl, $this->token, $method);
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,  0);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $params,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return json_decode($resp, true) ?: [];
    }

    public function getUpdates(int $offset = 0, int $timeout = 30): array
    {
        return $this->request('getUpdates', [
            'offset'  => $offset,
            'timeout' => $timeout,
        ]);
    }

    public function sendMessage(int $chatId, string $text, array $replyMarkup = null): array
    {
        $data = ['chat_id'=>$chatId,'text'=>$text];
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        return $this->request('sendMessage', $data);
    }

    public function sendDocument(int $chatId, string $path): array
    {
        return $this->request('sendDocument', [
            'chat_id'  => $chatId,
            'document' => new \CURLFile($path),
        ]);
    }

    public function getFile(string $fileId): string
    {
        $r  = $this->request('getFile',['file_id'=>$fileId]);
        $fp = $r['result']['file_path'] ?? '';
        return "https://api.telegram.org/file/bot{$this->token}/{$fp}";
    }

    public function answerCallbackQuery(string $callbackQueryId): array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId
        ]);
    }

    public function editMessageReplyMarkup(
        int $chatId,
        int $messageId,
        array $replyMarkup = []
    ): array {
        return $this->request('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => json_encode($replyMarkup),
        ]);
    }
}
