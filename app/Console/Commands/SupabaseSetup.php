<?php

namespace App\Console\Commands;

use App\Supabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SupabaseSetup extends Command
{
    protected $signature = 'supabase:setup';

    protected $description = 'Prepara lo schema privato e crea il primo Super_user Supabase';

    public function handle(Supabase $supabase): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('Imposta DB_CONNECTION=pgsql nel file .env.');

            return self::FAILURE;
        }
        $schema = config('database.connections.pgsql.search_path');
        if ($schema !== 'app_private') {
            $this->error('Imposta DB_SCHEMA=app_private per isolare i dati dalle API pubbliche.');

            return self::FAILURE;
        }
        $email = config('services.supabase.super_email');
        $password = config('services.supabase.super_password');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 12) {
            $this->error('Compila SUPABASE_SUPER_USER_EMAIL e SUPABASE_SUPER_USER_PASSWORD (almeno 12 caratteri).');

            return self::FAILURE;
        }
        DB::statement('CREATE SCHEMA IF NOT EXISTS app_private');
        DB::statement('REVOKE ALL ON SCHEMA app_private FROM PUBLIC, anon, authenticated');
        if ($this->call('migrate', ['--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }
        $response = $supabase->client(admin: true)->post('/admin/users', [
            'email' => $email, 'password' => $password, 'email_confirm' => true,
            'app_metadata' => ['role' => 'Super_user'], 'user_metadata' => ['name' => 'Super user'],
        ]);
        if (! $response->successful()) {
            $this->error('Creazione Super_user non riuscita (HTTP '.$response->status().'). Se l’account esiste già, non è stato modificato.');

            return self::FAILURE;
        }
        $this->info('Database pronto e Super_user creato. Rimuovi SUPABASE_SUPER_USER_PASSWORD dal file .env.');

        return self::SUCCESS;
    }
}
