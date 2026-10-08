<?php

namespace App\Console\Commands;

use App\Telegram;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TelegramSetup extends Command
{
    protected $signature = 'telegram:setup';

    protected $description = 'Verifica il bot e registra il webhook HTTPS di Telegram';

    public function handle(Telegram $telegram): int
    {
        $url = route('telegram.webhook');
        if (! $telegram->configured() || ! preg_match('/^[a-zA-Z0-9_-]{32,256}$/D', config('services.telegram.webhook_secret', ''))) {
            $this->error('Configura TELEGRAM_BOT_TOKEN, TELEGRAM_BOT_USERNAME e TELEGRAM_WEBHOOK_SECRET (almeno 32 caratteri).');

            return self::FAILURE;
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || config('services.telegram.queue_connection') !== 'database') {
            $this->error('Imposta APP_URL con HTTPS pubblico e TELEGRAM_QUEUE_CONNECTION=database.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('telegram_accounts') || ! Schema::hasTable('telegram_updates') || ! Schema::hasTable('jobs')) {
            $this->error('Esegui prima php artisan migrate --no-interaction.');

            return self::FAILURE;
        }
        if ((int) config('queue.connections.database.retry_after') <= 80
            || ! in_array(config('queue.connections.database.connection'), [null, config('database.default')], true)) {
            $this->error('La coda database deve usare il database dell’app e DB_QUEUE_RETRY_AFTER deve essere maggiore di 80.');

            return self::FAILURE;
        }
        try {
            $bot = $telegram->call('getMe')['result'];
            if (strcasecmp($bot['username'] ?? '', config('services.telegram.username')) !== 0) {
                $this->error('TELEGRAM_BOT_USERNAME non corrisponde al bot del token configurato.');

                return self::FAILURE;
            }
            $telegram->call('setWebhook', [
                'url' => $url, 'secret_token' => config('services.telegram.webhook_secret'),
                'allowed_updates' => ['message'], 'max_connections' => 1,
            ]);
        } catch (Throwable) {
            $this->error('Configurazione non riuscita. Verifica token, segreto webhook e connessione a Telegram.');

            return self::FAILURE;
        }
        $this->info('Webhook registrato. Avvia un solo worker: php artisan queue:work database --queue=telegram --timeout=80 --sleep=1');

        return self::SUCCESS;
    }
}
