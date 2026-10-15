<?php

namespace Tests\Feature;

use App\AppSettings;
use App\Jobs\ProcessTelegramUpdate;
use App\TelegramMessages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'Super_user'): void
    {
        config([
            'services.supabase.url' => 'https://project.supabase.co',
            'services.supabase.publishable_key' => 'public-test', 'services.supabase.secret_key' => 'secret-test',
            'services.telegram.token' => 'test-token', 'services.telegram.username' => 'test_bot',
            'services.telegram.webhook_secret' => str_repeat('s', 32),
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://project.supabase.co/auth/v1/user' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111', 'email' => 'test@example.com',
                'app_metadata' => ['role' => $role],
            ]),
            'https://project.supabase.co/auth/v1/admin/users?*' => Http::response(['users' => []]),
        ]);
        $this->withSession(['supabase' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
    }

    /** @return array<string, string> */
    private function values(): array
    {
        return ['name' => 'Condominio Aurora', 'city' => 'Napoli', 'logo_url' => 'https://images.example.com/logo.png',
            'favicon_url' => 'img/icon.png', 'telegram_enabled' => '0'];
    }

    public function test_guests_cannot_read_or_save_settings(): void
    {
        $this->get('/impostazioni')->assertRedirect('/');
        $this->put('/impostazioni', $this->values())->assertRedirect('/');
        $this->assertDatabaseCount('app_settings', 0);
    }

    #[TestWith(['amministratore'])]
    #[TestWith(['condomino'])]
    public function test_other_roles_cannot_read_or_save_settings(string $role): void
    {
        $this->account($role);
        $this->get('/impostazioni')->assertForbidden();
        $this->put('/impostazioni', $this->values())->assertForbidden();
        $this->get('/profilo')->assertOk()->assertDontSee('href="'.route('settings').'"', false);
        $this->assertDatabaseCount('app_settings', 0);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/admin/users'));
    }

    public function test_super_user_saves_global_identity_and_sees_administration_in_settings(): void
    {
        $this->account();
        $this->put('/impostazioni', $this->values())->assertRedirect('/impostazioni')->assertSessionHas('status', 'Impostazioni aggiornate.');
        $this->assertDatabaseHas('app_settings', ['key' => 'name', 'value' => 'Condominio Aurora']);
        $this->assertDatabaseHas('app_settings', ['key' => 'city', 'value' => 'Napoli']);
        $this->assertDatabaseHas('app_settings', ['key' => 'telegram_enabled', 'value' => '0']);
        $this->get('/impostazioni')->assertOk()->assertSee('Crea utente')->assertSee('Accessi all’app')->assertSee('Condominio Aurora');
        $this->get('/profilo')->assertOk()->assertDontSee('Crea utente')->assertDontSee('Accessi all’app');
        $this->get('/')->assertOk()->assertSee('Condominio Aurora')->assertSee('Napoli')
            ->assertSee('https://images.example.com/logo.png')->assertSee('img/icon.png');
    }

    #[TestWith(['name', ''])]
    #[TestWith(['logo_url', 'javascript:alert(1)'])]
    #[TestWith(['favicon_url', '//unsafe.example/icon.png'])]
    #[TestWith(['telegram_enabled', 'unexpected'])]
    public function test_invalid_settings_are_not_saved(string $field, string $value): void
    {
        $this->account();
        $this->from('/impostazioni')->put('/impostazioni', array_replace($this->values(), [$field => $value]))
            ->assertRedirect('/impostazioni')->assertSessionHasErrors($field);
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_empty_optional_identity_fields_use_name_and_default_icon(): void
    {
        $this->account();
        $this->put('/impostazioni', array_replace($this->values(), ['city' => '', 'logo_url' => '', 'favicon_url' => '']))->assertRedirect('/impostazioni');
        $this->get('/')->assertOk()->assertSee('Condominio Aurora')->assertSee('favicon.ico')->assertDontSee('img/edilcise-transparent.png');
    }

    public function test_disabling_telegram_blocks_links_and_webhook_without_removing_accounts(): void
    {
        $this->account('amministratore');
        (new AppSettings)->save(['telegram_enabled' => '0']);
        DB::table('telegram_accounts')->insert(['user_id' => '11111111-1111-4111-8111-111111111111', 'telegram_id' => '12345']);
        Queue::fake([ProcessTelegramUpdate::class]);
        $this->post('/profilo/telegram')->assertStatus(503);
        $this->postJson('/telegram/webhook', ['update_id' => 1], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();
        $this->get('/profilo')->assertOk()->assertSee('Telegram è disattivato dal gestore.')->assertDontSee('Collega Telegram');
        $this->assertDatabaseHas('telegram_accounts', ['telegram_id' => '12345']);
        $this->assertDatabaseCount('telegram_updates', 0);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }

    public function test_disabling_telegram_stops_queued_uploads_and_reactivation_accepts_messages(): void
    {
        $this->account();
        (new AppSettings)->save(['telegram_enabled' => '0']);
        DB::table('telegram_updates')->insert(['id' => 1, 'chat_id' => '12345', 'payload' => Crypt::encryptString('{}')]);
        app()->call([new ProcessTelegramUpdate(1), 'handle']);
        $this->assertDatabaseHas('telegram_updates', ['id' => 1, 'payload' => null, 'reply' => null]);
        $this->assertNotNull(DB::table('telegram_updates')->value('sent_at'));
        $this->assertDatabaseCount('documents', 0);
        Http::assertNothingSent();
        (new AppSettings)->save(['telegram_enabled' => '1']);
        Queue::fake([ProcessTelegramUpdate::class]);
        $this->postJson('/telegram/webhook', ['update_id' => 2, 'message' => [
            'text' => 'ciao', 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => str_repeat('s', 32)])->assertOk();
        Queue::assertPushed(ProcessTelegramUpdate::class);
    }

    public function test_super_user_saves_messages_and_settings_escape_the_custom_text(): void
    {
        $this->account();
        $custom = '</textarea><script>alert(1)</script> Benvenuto su {app_name}';
        $this->put('/impostazioni', $this->values() + ['telegram_messages' => ['not_linked' => $custom]])
            ->assertRedirect('/impostazioni');
        $this->assertDatabaseHas('app_settings', ['key' => 'telegram_message_not_linked', 'value' => $custom]);
        $this->get('/impostazioni')->assertOk()->assertSee('Messaggi Telegram')->assertSee($custom)
            ->assertDontSee('</textarea><script>alert(1)</script>', false);
    }

    public function test_empty_message_restores_default_without_resetting_other_custom_messages(): void
    {
        $this->account();
        (new AppSettings)->save(['telegram_message_not_linked' => 'Personalizzato', 'telegram_message_cancelled' => 'Annullato!']);
        $this->put('/impostazioni', $this->values() + ['telegram_messages' => ['not_linked' => '']])->assertRedirect('/impostazioni');
        config(['app.name' => 'Portale Aurora']);
        $this->assertSame('Collega prima Telegram dal tuo profilo Portale Aurora.', app(TelegramMessages::class)->text('not_linked'));
        $this->assertDatabaseHas('app_settings', ['key' => 'telegram_message_cancelled', 'value' => 'Annullato!']);
    }

    #[TestWith(['amministratore'])]
    #[TestWith(['condomino'])]
    public function test_other_roles_cannot_save_telegram_messages(string $role): void
    {
        $this->account($role);
        $this->put('/impostazioni', $this->values() + ['telegram_messages' => ['linked' => 'Modificato']])->assertForbidden();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_oversized_messages_are_rejected_without_changing_settings(): void
    {
        $this->account();
        $this->put('/impostazioni', $this->values() + ['telegram_messages' => ['linked' => str_repeat('a', 1501)]])
            ->assertSessionHasErrors(['telegram_messages.linked' => 'Ogni messaggio Telegram può contenere al massimo 1500 caratteri.']);
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_unknown_message_keys_cannot_override_other_settings(): void
    {
        $this->account();
        $this->put('/impostazioni', $this->values() + ['telegram_messages' => ['telegram_enabled' => '1']])
            ->assertSessionHasErrors('telegram_messages');
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_saving_settings_refreshes_cached_values_and_preserves_other_fields(): void
    {
        $settings = app(AppSettings::class);
        $settings->save(['name' => 'Prima', 'city' => 'Napoli']);
        $this->assertSame('Prima', $settings->values()['name']);

        $settings->save(['name' => 'Dopo']);

        $this->assertSame(['city' => 'Napoli', 'name' => 'Dopo'], collect($settings->values())->sortKeys()->all());
        $this->assertDatabaseCount('app_settings', 2);
    }

    public function test_settings_reuse_user_list_until_expiry(): void
    {
        $this->account();
        $this->freezeTime();
        $this->get('/impostazioni')->assertOk();
        $this->get('/impostazioni')->assertOk();
        Http::assertSentCount(3);

        $this->travel(21)->seconds();
        $this->get('/impostazioni')->assertOk();
        Http::assertSentCount(5);
    }
}
