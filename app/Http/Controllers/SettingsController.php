<?php

namespace App\Http\Controllers;

use App\AppSettings;
use App\Supabase;
use App\Telegram;
use App\TelegramMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(Request $request, Telegram $telegram, Supabase $supabase, AppSettings $settings): View
    {
        abort_unless($request->user()->role === 'Super_user', 403);
        $enabledUsers = $supabase->enabledUsers();
        $accessesResetAt = Cache::get('supabase.accesses_reset_at.'.$request->user()->id);

        return view('settings', [
            'enabledUsers' => $enabledUsers,
            'accessedUsers' => collect($enabledUsers)->filter(fn (array $user): bool => ! empty($user['last_sign_in_at'])
                && ($accessesResetAt === null || Carbon::parse($user['last_sign_in_at'])->greaterThan($accessesResetAt)))->sortByDesc('last_sign_in_at'),
            'linkedTelegramAccounts' => DB::table('telegram_accounts')->select('id', 'user_id', 'telegram_id')->whereNotNull('telegram_id')->orderBy('id')->get(),
            'telegramConfigured' => $telegram->configured(),
            'telegramEnabled' => $settings->telegramEnabled(),
            'telegramMessageDefinitions' => TelegramMessages::DEFINITIONS,
            'savedSettings' => $settings->values(),
        ]);
    }

    public function update(Request $request, AppSettings $settings): RedirectResponse
    {
        abort_unless($request->user()->role === 'Super_user', 403);
        $imagePathRules = ['nullable', 'string', 'max:2048', 'regex:~^(?:https?://[^\s]+|/?[a-zA-Z0-9_/-]+\.[a-zA-Z0-9]+)$~D'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:150'],
            'logo_url' => $imagePathRules,
            'favicon_url' => $imagePathRules,
            'telegram_enabled' => ['required', 'boolean'],
            'telegram_messages' => ['sometimes', 'array:'.implode(',', array_keys(TelegramMessages::DEFINITIONS))],
            'telegram_messages.*' => ['nullable', 'string', 'max:1500'],
        ], [
            'telegram_messages.*.max' => 'Ogni messaggio Telegram può contenere al massimo 1500 caratteri.',
            'telegram_messages.*.string' => 'Inserisci un testo valido per il messaggio Telegram.',
            'name.required' => 'Inserisci il nome del condominio.',
            'logo_url.regex' => 'Inserisci un URL http/https o un percorso immagine valido per il logo.',
            'favicon_url.regex' => 'Inserisci un URL http/https o un percorso immagine valido per la favicon.',
        ]);
        $data['telegram_enabled'] = $request->boolean('telegram_enabled') ? '1' : '0';
        foreach ($data['telegram_messages'] ?? [] as $key => $value) {
            $data['telegram_message_'.$key] = $value;
        }
        unset($data['telegram_messages']);
        $settings->save($data);

        return to_route('settings')->with('status', 'Impostazioni aggiornate.');
    }
}
