<?php

namespace App\Http\Middleware;

use App\Supabase;
use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SupabaseSession
{
    public function __construct(private Supabase $supabase) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tokens = $request->session()->get('supabase');
        if (! $tokens) {
            return redirect()->route('login');
        }

        if (($tokens['expires_at'] ?? 0) <= time() + 30) {
            $response = $this->supabase->client()->post('/token?grant_type=refresh_token', [
                'refresh_token' => $tokens['refresh_token'],
            ]);
            if ($response->clientError()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }
            $response->throw();
            $this->supabase->storeSession($response->json());
            $tokens = $request->session()->get('supabase');
        }

        $response = $this->supabase->client($tokens['access_token'])->get('/user');
        if ($response->status() === 401 || $response->status() === 403) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }
        $data = $response->throw()->json();
        $sessionToken = $request->session()->get('supabase_session_token');
        $cacheKey = 'supabase.active_session.'.$data['id'];
        if (! $sessionToken) {
            $sessionToken = (string) Str::uuid();
            Cache::add($cacheKey, $sessionToken);
            $request->session()->put('supabase_session_token', $sessionToken);
        }
        if (Cache::get($cacheKey) !== $sessionToken) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Sessione terminata: è stato effettuato un accesso da un altro dispositivo. Accedi nuovamente.',
            ]);
        }
        $role = $data['app_metadata']['role'] ?? null;
        abort_unless(in_array($role, Supabase::ROLES, true), 403, 'Account senza un ruolo abilitato. Contatta il gestore.');
        $user = new GenericUser([
            'id' => $data['id'], 'email' => $data['email'], 'role' => $role,
            'name' => $data['user_metadata']['name'] ?? '',
            'surname' => $data['user_metadata']['surname'] ?? '',
            'phone' => $data['user_metadata']['phone'] ?? '',
        ]);
        $request->setUserResolver(fn (): GenericUser => $user);
        view()->share('account', $user);

        return $next($request);
    }
}
