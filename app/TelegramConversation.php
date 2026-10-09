<?php

namespace App;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

class TelegramConversation
{
    public function __construct(private Telegram $telegram, private Supabase $supabase) {}

    /** @param array<string, mixed> $message
     * @return array{text: string, markup?: array<string, mixed>}
     */
    public function handle(array $message): array
    {
        $telegramId = (string) $message['from']['id'];
        $text = trim($message['text'] ?? '');
        if (preg_match('/^\/start ([a-zA-Z0-9]{48})$/D', $text, $matches)) {
            return $this->link($telegramId, $matches[1]);
        }
        $account = DB::table('telegram_accounts')->where('telegram_id', $telegramId)->lockForUpdate()->first();
        if (! $account) {
            return ['text' => 'Collega prima Telegram dal tuo profilo '.config('app.name').'.'];
        }
        if (! $this->authorized($account->user_id)) {
            DB::table('telegram_accounts')->where('id', $account->id)->update(['draft' => null, 'draft_expires_at' => null]);

            return ['text' => 'Il tuo account non è autorizzato a caricare documenti.'];
        }
        if (in_array(mb_strtolower($text), ['/annulla', 'annulla'], true)) {
            $this->saveDraft($account, null);

            return ['text' => 'Caricamento annullato. Puoi inviare un nuovo documento.'];
        }
        $draft = $account->draft && $account->draft_expires_at > now()->toDateTimeString()
            ? json_decode(Crypt::decryptString($account->draft), true, flags: JSON_THROW_ON_ERROR) : null;
        if ($account->draft && ! $draft) {
            $this->saveDraft($account, null);
        }
        $errors = [];
        $file = $message['document'] ?? (! empty($message['photo']) ? end($message['photo']) : null);
        if ($file) {
            if ($draft) {
                return ['text' => 'Completa il documento in corso oppure scrivi /annulla prima di inviarne un altro.'];
            }
            if (($file['file_size'] ?? 0) > Telegram::MAX_FILE_SIZE) {
                return ['text' => 'Il file supera il limite di 10 MB.'];
            }
            $draft = ['file' => $this->telegram->download($file['file_id']), 'data' => $this->caption($message['caption'] ?? '')];
            if (! isset($draft['data']['title']) && isset($file['file_name'])) {
                $draft['data']['title'] = mb_substr(pathinfo($file['file_name'], PATHINFO_FILENAME), 0, 150);
            }
            foreach ($draft['data'] as $field => $value) {
                try {
                    $draft['data'][$field] = $this->validateField($field, $value);
                } catch (ValidationException $exception) {
                    unset($draft['data'][$field]);
                    $errors[] = collect($exception->errors())->flatten()->implode("\n");
                }
            }
        } elseif ($draft) {
            $field = $this->nextField($draft['data']);
            if ($field === null) {
                if (mb_strtolower($text) !== 'conferma') {
                    return $this->summary($draft['data']);
                }
                $id = DB::table('documents')->insertGetId($draft['data'] + $draft['file'] + [
                    'uploaded_by' => $account->user_id, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->saveDraft($account, null);

                return ['text' => 'Documento caricato su '.config('app.name').' ✅'."\n".route('documents.show', ['id' => $id])];
            }
            $draft['data'][$field] = $this->validateField($field, $text);
        } else {
            return ['text' => "Invia un PDF, JPG o PNG fino a 10 MB.\nPuoi aggiungere una didascalia, una voce per riga:\nTitolo: Fattura ottobre\nFornitore: Rossi Materiali\nData: 08/10/2026\nImporto: 125,50\nCategoria: Manutenzione\n\nTi chiederò i dati mancanti. /annulla interrompe il caricamento."];
        }
        $this->saveDraft($account, $draft);
        $field = $this->nextField($draft['data']);
        if ($field === null) {
            return $this->summary($draft['data']);
        }

        return ['text' => ($errors ? implode("\n", $errors)."\n\n" : '').match ($field) {
            'title' => 'Qual è il titolo del documento?',
            'supplier' => 'Chi è il fornitore?',
            'date' => 'Qual è la data del documento? Scrivi GG/MM/AAAA.',
            'amount' => 'Qual è l’importo in euro? Esempio: 125,50.',
            'category' => 'Qual è la categoria? Scrivi /salta per lasciarla vuota.',
        }];
    }

    /** @return array{text: string} */
    private function link(string $telegramId, string $token): array
    {
        $account = DB::table('telegram_accounts')->where('link_hash', hash('sha256', $token))->lockForUpdate()->first();
        if (! $account || ! $account->link_expires_at || $account->link_expires_at <= now()->toDateTimeString()) {
            return ['text' => 'Collegamento scaduto o già utilizzato. Generane uno nuovo dal tuo profilo '.config('app.name').'.'];
        }
        if (! $this->authorized($account->user_id)) {
            return ['text' => 'Il tuo account non è autorizzato a caricare documenti.'];
        }
        if (DB::table('telegram_accounts')->where('telegram_id', $telegramId)->where('id', '!=', $account->id)->exists()) {
            return ['text' => 'Questo account Telegram è già collegato. Scollegalo dal profilo precedente prima di continuare.'];
        }
        DB::table('telegram_accounts')->where('id', $account->id)->update([
            'telegram_id' => $telegramId, 'link_hash' => null, 'link_expires_at' => null,
            'draft' => null, 'draft_expires_at' => null, 'updated_at' => now(),
        ]);

        return ['text' => 'Telegram collegato a '.config('app.name').' ✅ Invia un PDF, JPG o PNG fino a 10 MB per iniziare.'];
    }

    private function authorized(string $userId): bool
    {
        $response = $this->supabase->client(admin: true)->get('/admin/users/'.$userId);
        if (in_array($response->status(), [404, 410], true)) {
            return false;
        }
        $data = $response->throw()->json();

        return ($data['id'] ?? null) === $userId
            && (empty($data['banned_until']) || strtotime($data['banned_until']) <= now()->timestamp)
            && empty($data['deleted_at'])
            && in_array($data['app_metadata']['role'] ?? null, ['Super_user', 'amministratore'], true);
    }

    /** @return array<string, string> */
    private function caption(string $caption): array
    {
        $fields = ['titolo' => 'title', 'fornitore' => 'supplier', 'data' => 'date', 'importo' => 'amount', 'categoria' => 'category'];
        $data = [];
        foreach (preg_split('/\R/u', $caption) as $line) {
            $parts = explode(':', $line, 2);
            $field = $fields[mb_strtolower(trim($parts[0]))] ?? null;
            if ($field && count($parts) === 2) {
                $data[$field] = trim($parts[1]);
            }
        }

        return $data;
    }

    private function validateField(string $field, string $value): ?string
    {
        if ($field === 'date' && preg_match('~^(\d{2})/(\d{2})/(\d{4})$~D', $value, $parts)) {
            $value = $parts[3].'-'.$parts[2].'-'.$parts[1];
        }
        if ($field === 'amount') {
            $value = trim(str_replace('€', '', $value));
            if (str_contains($value, ',')) {
                if (! preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/D', $value)) {
                    throw ValidationException::withMessages(['amount' => 'Importo non valido. Scrivi ad esempio 125,50.']);
                }
                $value = str_replace(',', '.', str_replace('.', '', $value));
            }
        }
        if ($field === 'category' && $value === '/salta') {
            return null;
        }
        $rules = match ($field) {
            'title', 'supplier' => ['required', 'string', 'max:150'],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/D'],
            'category' => ['required', 'string', 'max:50'],
        };
        $validator = Validator::make([$field => $value], [$field => $rules]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$field => match ($field) {
                'title' => 'Scrivi un titolo da 1 a 150 caratteri.',
                'supplier' => 'Scrivi un fornitore da 1 a 150 caratteri.',
                'date' => 'Data non valida. Scrivi una data reale nel formato GG/MM/AAAA.',
                'amount' => 'Importo non valido. Scrivi un valore da 0 a 99999999,99 con al massimo due decimali.',
                'category' => 'Scrivi una categoria fino a 50 caratteri oppure /salta.',
            }]);
        }

        return $value;
    }

    /** @param array<string, mixed>|null $draft */
    private function saveDraft(stdClass $account, ?array $draft): void
    {
        DB::table('telegram_accounts')->where('id', $account->id)->update([
            'draft' => $draft ? Crypt::encryptString(json_encode($draft, JSON_THROW_ON_ERROR)) : null,
            'draft_expires_at' => $draft ? now()->addDay() : null, 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function nextField(array $data): ?string
    {
        foreach (['title', 'supplier', 'date', 'amount', 'category'] as $field) {
            if (! array_key_exists($field, $data)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data
     * @return array{text: string, markup: array<string, mixed>}
     */
    private function summary(array $data): array
    {
        return [
            'text' => "Controlla il documento:\nTitolo: {$data['title']}\nFornitore: {$data['supplier']}\nData: {$data['date']}\nImporto: {$data['amount']} €\nCategoria: ".($data['category'] ?? '—')."\n\nPremi Conferma per caricarlo oppure Annulla.",
            'markup' => ['keyboard' => [[['text' => 'Conferma'], ['text' => 'Annulla']]], 'resize_keyboard' => true, 'one_time_keyboard' => true],
        ];
    }
}
