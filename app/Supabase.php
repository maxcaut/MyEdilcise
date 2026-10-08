<?php

namespace App;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class Supabase
{
    public const ROLES = ['Super_user', 'amministratore', 'condomino'];

    public function client(?string $token = null, bool $admin = false): PendingRequest
    {
        $url = config('services.supabase.url');
        $key = config('services.supabase.'.($admin ? 'secret_key' : 'publishable_key'));
        abort_unless($url && $key, 503, 'Completa la configurazione Supabase nel file .env.');

        $client = Http::baseUrl(rtrim($url, '/').'/auth/v1')->acceptJson()
            ->withHeaders(['apikey' => $key])->connectTimeout(5)->timeout(20);

        if ($token) {
            $client->withToken($token);
        } elseif ($admin && str_starts_with($key, 'eyJ')) {
            $client->withToken($key);
        }

        return $client;
    }

    public function login(string $email, string $password): Response
    {
        return $this->client()->post('/token?grant_type=password', compact('email', 'password'));
    }

    /** @return list<array<string, mixed>> */
    public function enabledUsers(): array
    {
        $users = [];
        $page = 1;
        do {
            $response = $this->client(admin: true)->get('/admin/users', ['page' => $page++, 'per_page' => 100])->throw();
            $batch = $response->json('users');
            abort_unless(is_array($batch), 502, 'Elenco utenti Supabase non valido.');
            foreach ($batch as $user) {
                if (in_array($user['app_metadata']['role'] ?? null, self::ROLES, true)
                    && empty($user['deleted_at'])
                    && (empty($user['banned_until']) || strtotime($user['banned_until']) <= time())) {
                    $users[] = $user;
                }
            }
        } while (count($batch) === 100);

        return $users;
    }

    /** @param array<string, mixed> $tokens */
    public function storeSession(array $tokens): void
    {
        abort_unless(isset($tokens['access_token'], $tokens['refresh_token']), 502, 'Risposta Supabase non valida.');
        session()->put('supabase', [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => time() + ($tokens['expires_in'] ?? 3600),
        ]);
    }
}
