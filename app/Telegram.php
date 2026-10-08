<?php

namespace App;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Telegram
{
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function configured(): bool
    {
        return filled(config('services.telegram.token'))
            && filled(config('services.telegram.username'))
            && filled(config('services.telegram.webhook_secret'));
    }

    /** @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    public function call(string $method, array $parameters = []): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->post(
                'https://api.telegram.org/bot'.config('services.telegram.token').'/'.$method, $parameters,
            )->throw();
        } catch (ConnectionException|RequestException) {
            throw new RuntimeException('Telegram non disponibile. Riprova tra poco.');
        }
        if ($response->json('ok') !== true) {
            throw new RuntimeException('Telegram non ha accettato la richiesta.');
        }

        return $response->json();
    }

    /** @param array<string, mixed> $markup */
    public function send(string $chatId, string $text, array $markup = ['remove_keyboard' => true]): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId, 'text' => $text, 'reply_markup' => $markup,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /** @return array{content: string, mime_type: string, extension: string} */
    public function download(string $fileId): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId])['result'] ?? [];
        $path = $file['file_path'] ?? '';
        if (($file['file_size'] ?? 0) > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages(['file' => 'Il file supera il limite di 10 MB.']);
        }
        if (! is_string($path) || ! preg_match('~^[a-zA-Z0-9_/-]+\.[a-zA-Z0-9]+$~D', $path) || str_contains($path, '..')) {
            throw new RuntimeException('Percorso del file Telegram non valido.');
        }
        try {
            $response = Http::connectTimeout(5)->timeout(25)->withOptions(['stream' => true])->get(
                'https://api.telegram.org/file/bot'.config('services.telegram.token').'/'.$path,
            )->throw();
            $stream = $response->toPsrResponse()->getBody();
            $content = '';
            try {
                while (! $stream->eof() && strlen($content) <= self::MAX_FILE_SIZE) {
                    $content .= $stream->read(min(65536, self::MAX_FILE_SIZE + 1 - strlen($content)));
                }
            } finally {
                $stream->close();
            }
        } catch (ConnectionException|RequestException) {
            throw new RuntimeException('Download Telegram non disponibile. Riprova tra poco.');
        }
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw ValidationException::withMessages(['file' => 'Il file supera il limite di 10 MB.']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => 'Invia un PDF, JPG o PNG valido, fino a 10 MB.']);
        }

        return ['content' => base64_encode($content), 'mime_type' => $mime, 'extension' => $extension];
    }
}
