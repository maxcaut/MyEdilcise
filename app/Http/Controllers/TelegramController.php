<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdate;
use App\Telegram;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class TelegramController extends Controller
{
    public function profile(Request $request, Telegram $telegram): View
    {
        return view('profile', [
            'telegramConfigured' => $telegram->configured(),
            'telegramAccount' => DB::table('telegram_accounts')->where('user_id', $request->user()->id)->first(),
        ]);
    }

    public function store(Request $request, Telegram $telegram): RedirectResponse
    {
        abort_unless(in_array($request->user()->role, ['Super_user', 'amministratore'], true), 403);
        abort_unless($telegram->configured(), 503, 'Telegram non è ancora configurato.');
        $token = Str::random(48);
        DB::table('telegram_accounts')->updateOrInsert(['user_id' => $request->user()->id], [
            'link_hash' => hash('sha256', $token), 'link_expires_at' => now()->addMinutes(10),
            'updated_at' => now(),
        ]);

        return to_route('profile')->with('telegram_link', 'https://t.me/'.config('services.telegram.username').'?start='.$token)
            ->with('status', 'Apri Telegram entro 10 minuti per collegare il tuo account.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $account = DB::table('telegram_accounts')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            if ($account) {
                DB::table('telegram_accounts')->where('id', $account->id)->delete();
            }
        });

        return to_route('profile')->with('status', 'Account Telegram scollegato.');
    }

    public function webhook(Request $request, Telegram $telegram): JsonResponse
    {
        abort_unless($telegram->configured(), 503);
        abort_unless(hash_equals(config('services.telegram.webhook_secret'), $request->header('X-Telegram-Bot-Api-Secret-Token', '')), 403);
        $data = $request->validate([
            'update_id' => ['required', 'integer', 'min:0'],
            'message' => ['sometimes', 'array'],
            'message.chat.id' => ['required_with:message', 'integer'],
            'message.chat.type' => ['required_with:message', 'string'],
            'message.from.id' => ['required_with:message', 'integer'],
            'message.text' => ['sometimes', 'string', 'max:4096'],
            'message.caption' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'message.document' => ['sometimes', 'array'],
            'message.document.file_id' => ['required_with:message.document', 'string', 'max:255'],
            'message.document.file_name' => ['sometimes', 'string', 'max:255'],
            'message.document.file_size' => ['sometimes', 'integer', 'min:0'],
            'message.photo' => ['sometimes', 'array', 'max:20'],
            'message.photo.*.file_id' => ['required', 'string', 'max:255'],
            'message.photo.*.file_size' => ['sometimes', 'integer', 'min:0'],
        ]);
        $message = $data['message'] ?? null;
        if (! $message || $message['chat']['type'] !== 'private' || (string) $message['chat']['id'] !== (string) $message['from']['id']) {
            return response()->json(['ok' => true]);
        }
        DB::transaction(function () use ($data, $message): void {
            DB::table('telegram_updates')->insertOrIgnore([
                'id' => $data['update_id'], 'chat_id' => (string) $message['chat']['id'],
                'payload' => Crypt::encryptString(json_encode($message, JSON_THROW_ON_ERROR)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! DB::table('telegram_updates')->where('id', $data['update_id'])->whereNotNull('sent_at')->exists()) {
                ProcessTelegramUpdate::dispatch((int) $data['update_id'])
                    ->onConnection(config('services.telegram.queue_connection'))->onQueue('telegram')->beforeCommit();
            }
        });

        return response()->json(['ok' => true]);
    }
}
