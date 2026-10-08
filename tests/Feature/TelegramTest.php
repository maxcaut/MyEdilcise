<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '11111111-1111-4111-8111-111111111111';

    private int $updateId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram.token' => 'test-token', 'services.telegram.username' => 'edilcise_test_bot',
            'services.telegram.webhook_secret' => str_repeat('s', 32), 'services.telegram.queue_connection' => 'database',
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.publishable_key' => 'public-test', 'services.supabase.secret_key' => 'secret-test',
        ]);
        Http::preventStrayRequests();
    }

    private function fakeNetwork(string $role = 'amministratore', string $content = '%PDF-1.4 sample', bool $failConfirmation = false): void
    {
        Queue::fake([ProcessTelegramUpdate::class]);
        Http::fake([
            'https://project.supabase.co/auth/v1/user' => Http::response([
                'id' => self::USER_ID, 'email' => 'admin@example.com', 'app_metadata' => ['role' => $role],
            ]),
            'https://project.supabase.co/auth/v1/admin/users/'.self::USER_ID => Http::response([
                'id' => self::USER_ID, 'app_metadata' => ['role' => $role],
            ]),
            'https://api.telegram.org/bottest-token/sendMessage' => $failConfirmation
                ? Http::sequence()->push(['ok' => true])->push([], 500)->push(['ok' => true])
                : Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
            'https://api.telegram.org/bottest-token/getFile' => Http::response([
                'ok' => true, 'result' => ['file_path' => 'documents/file.pdf', 'file_size' => strlen($content)],
            ]),
            'https://api.telegram.org/file/bottest-token/documents/file.pdf' => Http::response($content),
        ]);
    }

    private function sessionAccount(): void
    {
        $this->withSession(['supabase' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
    }

    private function linkedAccount(): void
    {
        DB::table('telegram_accounts')->insert(['user_id' => self::USER_ID, 'telegram_id' => '12345']);
    }

    /** @param array<string, mixed> $message */
    private function deliver(array $message, ?int $id = null): void
    {
        $id ??= ++$this->updateId;
        $this->postJson('/telegram/webhook', [
            'update_id' => $id,
            'message' => $message + ['chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();
        app()->call([new ProcessTelegramUpdate($id), 'handle']);
    }

    /** @return array<string, mixed> */
    private function document(string $caption = ''): array
    {
        return ['document' => ['file_id' => 'file-id', 'file_name' => 'fattura.pdf', 'file_size' => 15], 'caption' => $caption];
    }

    private function assertReplyContains(string $text): void
    {
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage') && str_contains($request['text'], $text));
    }

    public function test_guests_and_condomini_cannot_link_telegram(): void
    {
        $this->post('/profilo/telegram')->assertRedirect('/');
        $this->fakeNetwork('condomino');
        $this->sessionAccount();

        $this->post('/profilo/telegram')->assertForbidden();
        $this->get('/profilo')->assertOk()->assertDontSee('Documenti da Telegram');
        $this->assertDatabaseCount('telegram_accounts', 0);
    }

    public function test_profile_generates_a_single_use_link_and_account_can_be_disconnected(): void
    {
        $this->freezeTime();
        $this->fakeNetwork();
        $this->sessionAccount();
        $this->post('/profilo/telegram')->assertRedirect('/profilo')->assertSessionHas('telegram_link');
        $url = session('telegram_link');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['start'];
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'link_hash' => hash('sha256', $token)]);
        $this->get('/profilo')->assertOk()->assertSee('Apri Telegram e premi Avvia');

        $this->deliver(['text' => '/start '.$token]);
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'telegram_id' => '12345', 'link_hash' => null]);
        $this->deliver(['text' => '/start '.$token]);
        $this->assertReplyContains('scaduto o già utilizzato');
        $this->delete('/profilo/telegram')->assertRedirect('/profilo');
        $this->assertDatabaseCount('telegram_accounts', 0);
        Queue::assertPushed(ProcessTelegramUpdate::class, 2);
    }

    public function test_expired_link_does_not_connect_an_account(): void
    {
        $this->freezeTime();
        $this->fakeNetwork();
        DB::table('telegram_accounts')->insert([
            'user_id' => self::USER_ID, 'link_hash' => hash('sha256', str_repeat('a', 48)), 'link_expires_at' => now()->subMinute(),
        ]);

        $this->deliver(['text' => '/start '.str_repeat('a', 48)]);

        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'telegram_id' => null]);
        $this->assertReplyContains('scaduto o già utilizzato');
    }

    public function test_webhook_rejects_invalid_secret_with_403_and_ignores_groups(): void
    {
        $this->fakeNetwork();
        $this->postJson('/telegram/webhook', ['update_id' => 1])->assertForbidden();
        $this->postJson('/telegram/webhook', [
            'update_id' => 2, 'message' => ['chat' => ['id' => -123, 'type' => 'group'], 'from' => ['id' => 12345]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();

        $this->assertDatabaseCount('telegram_updates', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_webhook_enqueues_durable_work_without_contacting_external_services(): void
    {
        $this->fakeNetwork();
        $this->postJson('/telegram/webhook', [
            'update_id' => 77, 'message' => ['text' => 'ciao', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();

        $this->assertDatabaseHas('telegram_updates', ['id' => 77, 'processed_at' => null]);
        Queue::assertPushed(ProcessTelegramUpdate::class, fn ($job) => $job->updateId === 77 && $job->queue === 'telegram' && $job->connection === 'database');
        Http::assertNothingSent();
    }

    #[TestWith(['amministratore'])]
    #[TestWith(['Super_user'])]
    public function test_guided_upload_saves_only_after_confirmation_and_duplicate_delivery_is_ignored(string $role): void
    {
        $this->fakeNetwork($role);
        $this->linkedAccount();

        $this->deliver($this->document());
        $this->assertReplyContains('Chi è il fornitore?');
        $this->deliver(['text' => 'Rossi Materiali']);
        $this->deliver(['text' => '08/10/2026']);
        $this->deliver(['text' => '1.234,56']);
        $this->deliver(['text' => '/salta']);
        $this->assertReplyContains('Controlla il documento:');
        $this->assertDatabaseCount('documents', 0);
        $this->deliver(['text' => 'Conferma'], 6);
        $this->deliver(['text' => 'Conferma'], 6);

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('documents', [
            'title' => 'fattura', 'supplier' => 'Rossi Materiali', 'date' => '2026-10-08',
            'amount' => 1234.56, 'category' => null, 'uploaded_by' => self::USER_ID,
            'content' => base64_encode('%PDF-1.4 sample'), 'mime_type' => 'application/pdf', 'extension' => 'pdf',
        ]);
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'draft' => null]);
        $this->assertDatabaseHas('telegram_updates', ['id' => 6, 'payload' => null, 'reply' => null]);
        $this->assertReplyContains('Documento caricato');
    }

    public function test_complete_caption_skips_questions_and_cancel_discards_attachment(): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();

        $this->deliver($this->document("Titolo: Fattura ottobre\nFornitore: Rossi\nData: 08/10/2026\nImporto: 125,50\nCategoria: Manutenzione"));
        $this->assertReplyContains('Controlla il documento:');
        $this->deliver(['text' => 'Annulla']);
        $this->deliver(['text' => 'Conferma']);

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'draft' => null]);
        $this->assertReplyContains('Caricamento annullato');
    }

    #[TestWith(['condomino'])]
    #[TestWith(['unknown'])]
    public function test_revoked_roles_cannot_download_or_save_documents(string $role): void
    {
        $this->fakeNetwork($role);
        $this->linkedAccount();

        $this->deliver($this->document());

        $this->assertDatabaseCount('documents', 0);
        $this->assertReplyContains('non è autorizzato');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/getFile'));
    }

    public function test_unlinked_sender_cannot_download_a_document(): void
    {
        $this->fakeNetwork();

        $this->deliver($this->document());

        $this->assertReplyContains('Collega prima Telegram');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/getFile'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_html_disguised_as_pdf_is_rejected_by_content(): void
    {
        $this->fakeNetwork(content: '<html><script>alert(1)</script></html>');
        $this->linkedAccount();

        $this->deliver($this->document());

        $this->assertReplyContains('Invia un PDF, JPG o PNG valido');
        $this->assertDatabaseHas('telegram_accounts', ['draft' => null]);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_oversize_attachment_is_rejected_before_download(): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();

        $this->deliver(['document' => ['file_id' => 'file-id', 'file_size' => 10485761]]);

        $this->assertReplyContains('supera il limite di 10 MB');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/getFile'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_invalid_date_keeps_the_current_question_and_second_file_does_not_replace_draft(): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();
        $this->deliver($this->document('Fornitore: Rossi'));
        $original = DB::table('telegram_accounts')->value('draft');

        $this->deliver(['text' => '31/02/2026']);
        $this->assertReplyContains('Data non valida');
        $this->assertSame($original, DB::table('telegram_accounts')->value('draft'));
        $this->deliver($this->document());
        $this->assertReplyContains('Completa il documento in corso');
        $this->assertSame($original, DB::table('telegram_accounts')->value('draft'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_expired_draft_cannot_be_confirmed(): void
    {
        $this->freezeTime();
        $this->fakeNetwork();
        $this->linkedAccount();
        $this->deliver($this->document("Fornitore: Rossi\nData: 08/10/2026\nImporto: 10\nCategoria: Spese"));
        $this->travel(25)->hours();

        $this->deliver(['text' => 'Conferma']);

        $this->assertDatabaseCount('documents', 0);
        $this->assertReplyContains('Invia un PDF, JPG o PNG fino a 10 MB');
    }

    public function test_failed_confirmation_reply_can_be_retried_without_duplicate_documents(): void
    {
        $this->fakeNetwork(failConfirmation: true);
        $this->linkedAccount();
        $this->deliver($this->document("Fornitore: Rossi\nData: 08/10/2026\nImporto: 10\nCategoria: Spese"));

        try {
            $this->deliver(['text' => 'Conferma'], 2);
            $this->fail('La risposta Telegram deve fallire.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Telegram non disponibile. Riprova tra poco.', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 1);
        app()->call([new ProcessTelegramUpdate(2), 'handle']);

        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('telegram_updates', ['id' => 2, 'reply' => null]);
        $this->assertReplyContains('Documento caricato');
    }

    public function test_photo_asks_for_title_and_uses_the_largest_available_image(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aG3sAAAAASUVORK5CYII=');
        $this->fakeNetwork(content: $png);
        $this->linkedAccount();

        $this->deliver(['photo' => [['file_id' => 'small'], ['file_id' => 'large']]]);

        $this->assertReplyContains('Qual è il titolo');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getFile') && $request['file_id'] === 'large');
        $draft = json_decode(Crypt::decryptString(DB::table('telegram_accounts')->value('draft')), true);
        $this->assertSame('image/png', $draft['file']['mime_type']);
    }

    public function test_webhook_persists_the_job_in_the_database_queue(): void
    {
        $this->postJson('/telegram/webhook', [
            'update_id' => 99, 'message' => ['text' => 'ciao', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345]],
        ], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();

        $this->assertDatabaseHas('jobs', ['queue' => 'telegram', 'attempts' => 0]);
        $this->assertDatabaseHas('telegram_updates', ['id' => 99, 'processed_at' => null]);
        Http::assertNothingSent();
    }

    public function test_invalid_caption_preserves_attachment_and_asks_for_correction(): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();

        $this->deliver($this->document("Fornitore: Rossi\nData: 31/02/2026\nImporto: 10\nCategoria: Spese"));
        $this->assertReplyContains('Data non valida');
        $this->deliver(['text' => '08/10/2026']);
        $this->assertReplyContains('Controlla il documento:');
        $this->deliver(['text' => 'Conferma']);

        $this->assertDatabaseHas('documents', ['date' => '2026-10-08', 'content' => base64_encode('%PDF-1.4 sample')]);
    }

    public function test_role_is_checked_again_at_confirmation(): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();
        $this->deliver($this->document("Fornitore: Rossi\nData: 08/10/2026\nImporto: 10\nCategoria: Spese"));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeNetwork('condomino');

        $this->deliver(['text' => 'Conferma']);

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseHas('telegram_accounts', ['draft' => null]);
        $this->assertReplyContains('non è autorizzato');
    }

    public function test_telegram_identity_cannot_be_linked_to_two_application_accounts(): void
    {
        $this->freezeTime();
        $this->fakeNetwork();
        DB::table('telegram_accounts')->insert(['user_id' => '22222222-2222-4222-8222-222222222222', 'telegram_id' => '12345']);
        DB::table('telegram_accounts')->insert([
            'user_id' => self::USER_ID, 'link_hash' => hash('sha256', str_repeat('a', 48)), 'link_expires_at' => now()->addMinutes(10),
        ]);

        $this->deliver(['text' => '/start '.str_repeat('a', 48)]);

        $this->assertReplyContains('già collegato');
        $this->assertDatabaseHas('telegram_accounts', ['user_id' => self::USER_ID, 'telegram_id' => null]);
    }

    #[TestWith(['-1'])]
    #[TestWith(['1.234'])]
    #[TestWith(['100000000'])]
    #[TestWith(['1e3'])]
    public function test_invalid_amount_does_not_advance_the_conversation(string $amount): void
    {
        $this->fakeNetwork();
        $this->linkedAccount();
        $this->deliver($this->document("Fornitore: Rossi\nData: 08/10/2026"));
        $draft = DB::table('telegram_accounts')->value('draft');

        $this->deliver(['text' => $amount]);

        $this->assertReplyContains('Importo non valido');
        $this->assertSame($draft, DB::table('telegram_accounts')->value('draft'));
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_setup_registers_authenticated_webhook_and_limits_delivery_to_one_connection(): void
    {
        URL::forceRootUrl('https://edilcise.example');
        URL::forceScheme('https');
        Http::fake([
            'https://api.telegram.org/bottest-token/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'edilcise_test_bot']]),
            'https://api.telegram.org/bottest-token/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $this->artisan('telegram:setup')->assertSuccessful();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://edilcise.example/telegram/webhook'
            && $request['secret_token'] === str_repeat('s', 32)
            && $request['max_connections'] === 1 && $request['allowed_updates'] === ['message']);
    }

    public function test_setup_refuses_missing_credentials_without_contacting_telegram(): void
    {
        config(['services.telegram.token' => null]);

        $this->artisan('telegram:setup')->assertFailed();

        Http::assertNothingSent();
    }
}
