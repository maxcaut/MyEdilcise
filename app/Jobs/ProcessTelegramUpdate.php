<?php

namespace App\Jobs;

use App\Telegram;
use App\TelegramConversation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 100;

    public int $maxExceptions = 4;

    public int $timeout = 80;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    public function __construct(public int $updateId) {}

    public function handle(TelegramConversation $conversation, Telegram $telegram): void
    {
        DB::transaction(function () use ($conversation): void {
            $update = DB::table('telegram_updates')->where('id', $this->updateId)->lockForUpdate()->first();
            if (! $update || $update->sent_at) {
                return;
            }
            if (! $update->processed_at) {
                if (DB::table('telegram_updates')->where('chat_id', $update->chat_id)
                    ->where('id', '<', $update->id)->whereNull('processed_at')->exists()) {
                    $this->release(5);

                    return;
                }
                $message = json_decode(Crypt::decryptString($update->payload), true, flags: JSON_THROW_ON_ERROR);
                try {
                    $reply = $conversation->handle($message);
                } catch (ValidationException $exception) {
                    $reply = ['text' => collect($exception->errors())->flatten()->implode("\n")];
                }
                DB::table('telegram_updates')->where('id', $this->updateId)->update([
                    'reply' => Crypt::encryptString(json_encode($reply, JSON_THROW_ON_ERROR)),
                    'payload' => null, 'processed_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        DB::transaction(function () use ($telegram): void {
            $update = DB::table('telegram_updates')->where('id', $this->updateId)->lockForUpdate()->first();
            if (! $update || ! $update->processed_at || $update->sent_at) {
                return;
            }
            $reply = json_decode(Crypt::decryptString($update->reply), true, flags: JSON_THROW_ON_ERROR);
            $telegram->send($update->chat_id, $reply['text'], $reply['markup'] ?? ['remove_keyboard' => true]);
            DB::table('telegram_updates')->where('id', $this->updateId)->update(['sent_at' => now(), 'reply' => null, 'updated_at' => now()]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $update = DB::table('telegram_updates')->where('id', $this->updateId)->lockForUpdate()->first();
            if (! $update || $update->processed_at) {
                return;
            }
            DB::table('telegram_accounts')->where('telegram_id', $update->chat_id)->update(['draft' => null, 'draft_expires_at' => null]);
            DB::table('telegram_updates')->where('id', $this->updateId)->update([
                'payload' => null, 'processed_at' => now(), 'updated_at' => now(),
                'reply' => Crypt::encryptString(json_encode([
                    'text' => 'Non sono riuscito a elaborare il messaggio. Il caricamento incompleto è stato annullato; invia nuovamente il file.',
                ], JSON_THROW_ON_ERROR)),
            ]);
            self::dispatch($this->updateId)->onConnection(config('services.telegram.queue_connection'))->onQueue('telegram')->beforeCommit();
        });
    }
}
