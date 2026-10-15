<?php

namespace App;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use stdClass;

class TelegramConversation
{
    public function __construct(private Telegram $telegram, private Supabase $supabase, private TelegramMessages $messages) {}

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
            return ['text' => $this->messages->text('not_linked')];
        }
        if (! $this->authorized($account->user_id)) {
            DB::table('telegram_accounts')->where('id', $account->id)->update(['draft' => null, 'draft_expires_at' => null]);

            return ['text' => $this->messages->text('unauthorized')];
        }
        if (in_array(mb_strtolower($text), ['/annulla', 'annulla'], true)) {
            $this->saveDraft($account, null);

            return ['text' => $this->messages->text('cancelled')];
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
                return ['text' => $this->messages->text('draft_pending')];
            }
            if (($file['file_size'] ?? 0) > Telegram::MAX_FILE_SIZE) {
                return ['text' => $this->messages->text('file_too_large')];
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

                return ['text' => $this->messages->text('uploaded')."\n".route('documents.show', ['id' => $id])];
            }
            $draft['data'][$field] = $this->validateField($field, $text);
        } else {
            return ['text' => $this->messages->text('instructions')];
        }
        $this->saveDraft($account, $draft);
        $field = $this->nextField($draft['data']);
        if ($field === null) {
            return $this->summary($draft['data']);
        }

        return ['text' => ($errors ? implode("\n", $errors)."\n\n" : '').match ($field) {
            'title' => $this->messages->text('ask_title'),
            'supplier' => $this->messages->text('ask_supplier'),
            'date' => $this->messages->text('ask_date'),
            'amount' => $this->messages->text('ask_amount'),
            'category' => $this->messages->text('ask_category'),
        }];
    }

    /** @return array{text: string} */
    private function link(string $telegramId, string $token): array
    {
        $account = DB::table('telegram_accounts')->where('link_hash', hash('sha256', $token))->lockForUpdate()->first();
        if (! $account || ! $account->link_expires_at || $account->link_expires_at <= now()->toDateTimeString()) {
            return ['text' => $this->messages->text('expired_link')];
        }
        if (! $this->authorized($account->user_id)) {
            return ['text' => $this->messages->text('unauthorized')];
        }
        if (DB::table('telegram_accounts')->where('telegram_id', $telegramId)->where('id', '!=', $account->id)->exists()) {
            return ['text' => $this->messages->text('already_linked')];
        }
        DB::table('telegram_accounts')->where('id', $account->id)->update([
            'telegram_id' => $telegramId, 'link_hash' => null, 'link_expires_at' => null,
            'draft' => null, 'draft_expires_at' => null, 'updated_at' => now(),
        ]);

        return ['text' => $this->messages->text('linked')];
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
                    throw ValidationException::withMessages(['amount' => $this->messages->text('invalid_amount_format')]);
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
                'title' => $this->messages->text('invalid_title'),
                'supplier' => $this->messages->text('invalid_supplier'),
                'date' => $this->messages->text('invalid_date'),
                'amount' => $this->messages->text('invalid_amount'),
                'category' => $this->messages->text('invalid_category'),
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
            'text' => $this->messages->text('summary_heading')."\nTitolo: {$data['title']}\nFornitore: {$data['supplier']}\nData: {$data['date']}\nImporto: {$data['amount']} €\nCategoria: ".($data['category'] ?? '—')."\n\n".$this->messages->text('summary_footer'),
            'markup' => ['keyboard' => [[['text' => 'Conferma'], ['text' => 'Annulla']]], 'resize_keyboard' => true, 'one_time_keyboard' => true],
        ];
    }
}
