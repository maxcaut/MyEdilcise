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
