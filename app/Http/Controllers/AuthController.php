<?php

namespace App\Http\Controllers;

use App\Supabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private Supabase $supabase) {}

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:255']]);
        $response = $this->supabase->login($data['email'], $data['password']);
        if ($response->clientError()) {
            throw ValidationException::withMessages(['email' => 'Credenziali non valide o account non confermato.']);
        }
        $response->throw();
        $request->session()->regenerate();
        $this->supabase->storeSession($response->json());

        return to_route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        try {
            $this->supabase->client($request->session()->get('supabase.access_token'))->post('/logout?scope=local')->throw();
        } finally {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return to_route('login');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);
        $email = $data['email'];
        unset($data['email']);
        $this->supabase->client($request->session()->get('supabase.access_token'))
            ->put('/user', ['email' => $email, 'data' => $data])->throw();

        return to_route('profile')->with('status', 'Profilo aggiornato. Se hai cambiato email, conferma i messaggi inviati da Supabase.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);
        $response = $this->supabase->client($request->session()->get('supabase.access_token'))
            ->put('/user', $data);
        if ($response->clientError()) {
            throw ValidationException::withMessages(['current_password' => 'Password attuale non valida o nuova password non accettata.']);
        }
        $response->throw();

        return to_route('profile')->with('status', 'Password aggiornata.');
    }

    public function createUser(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'Super_user', 403);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'name' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'role' => ['required', Rule::in(Supabase::ROLES)],
        ]);
        $response = $this->supabase->client(admin: true)->post('/admin/users', [
            'email' => $data['email'], 'password' => $data['password'], 'email_confirm' => true,
            'app_metadata' => ['role' => $data['role']], 'user_metadata' => ['name' => $data['name']],
        ]);
        if ($response->clientError()) {
            throw ValidationException::withMessages(['email' => 'Account non creato: verifica email, password e configurazione Supabase.']);
        }
        $response->throw();

        return to_route('profile')->with('status', 'Utente creato.');
    }
}
