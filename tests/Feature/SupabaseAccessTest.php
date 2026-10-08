<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupabaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://project.supabase.co', 'services.supabase.publishable_key' => 'public-test', 'services.supabase.secret_key' => 'secret-test']);
        Http::preventStrayRequests();
    }

    private function account(string $role, array $metadata = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://project.supabase.co/auth/v1/user' => Http::response([
            'id' => '11111111-1111-4111-8111-111111111111', 'email' => 'test@example.com',
            'app_metadata' => ['role' => $role], 'user_metadata' => $metadata,
        ])]);
        $this->withSession(['supabase' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
    }

    public function test_guest_cannot_access_documents_or_profile(): void
    {
        foreach (['/dashboard', '/profilo', '/documenti/1', '/documenti/1/file'] as $path) {
            $this->get($path)->assertRedirect('/');
        }
        $this->post('/documenti')->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_login_stores_tokens_and_rejects_invalid_password(): void
    {
        Http::fake(['https://project.supabase.co/auth/v1/token?grant_type=password' => Http::sequence()->push([
            'access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 3600,
            'user' => ['id' => '11111111-1111-4111-8111-111111111111'],
        ])->push([], 400)]);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret'])
            ->assertRedirect('/dashboard')->assertSessionHas('supabase.access_token', 'new-token');
        Http::assertSent(fn ($request) => $request['email'] === 'test@example.com' && $request['password'] === 'secret');
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_condomino_cannot_upload_delete_or_create_users(): void
    {
        $this->account('condomino', ['role' => 'Super_user']);
        $this->post('/documenti')->assertForbidden();
        $this->delete('/documenti/1')->assertForbidden();
        $this->post('/utenti')->assertForbidden();
        $this->get('/dashboard')->assertOk()->assertDontSee('Carica documento');
        $this->assertDatabaseCount('documents', 0);
    }

    #[TestWith(['amministratore'])]
    #[TestWith(['Super_user'])]
    public function test_authorized_roles_upload_and_condomino_reads_file(string $role): void
    {
        $this->account($role);
        $this->post('/documenti', [
            'title' => 'Fattura', 'supplier' => 'Fornitore', 'date' => '2026-10-08',
            'category' => 'Utenze', 'amount' => '125.50',
            'file' => UploadedFile::fake()->createWithContent('fattura.pdf', '%PDF-1.4 sample'),
        ])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('documents', ['title' => 'Fattura', 'amount' => 125.50]);
        $id = DB::table('documents')->value('id');
        $this->account('condomino');
        $this->get('/documenti/'.$id.'/file')->assertOk()->assertContent('%PDF-1.4 sample');
    }

    #[TestWith([[]])]
    #[TestWith([['category' => '', 'file' => '']])]
    public function test_document_can_be_saved_without_category_or_attachment(array $optionalFields): void
    {
        $this->account('amministratore');

        $this->post('/documenti', $optionalFields + [
            'title' => 'Spesa', 'supplier' => 'Fornitore', 'date' => '2026-10-08', 'amount' => '125.50',
        ])->assertRedirect('/dashboard')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('documents', [
            'title' => 'Spesa', 'supplier' => 'Fornitore', 'date' => '2026-10-08', 'amount' => 125.50,
            'category' => null, 'content' => null, 'mime_type' => null, 'extension' => null,
        ]);
        $id = DB::table('documents')->value('id');
        $this->get('/documenti/'.$id)->assertOk()->assertSee('Nessun allegato.')->assertDontSee('Apri documento originale');
        $this->get('/documenti/'.$id.'/file')->assertNotFound();
        $this->get('/dashboard?month=2026-10')->assertOk()->assertSee('Spesa');
    }

    public function test_document_requires_only_title_supplier_date_and_amount(): void
    {
        $this->account('amministratore');

        $this->post('/documenti')->assertSessionHasErrors(['title', 'supplier', 'date', 'amount'])
            ->assertSessionDoesntHaveErrors(['category', 'file']);

        $this->assertDatabaseCount('documents', 0);
    }

    public function test_administrator_cannot_delete_or_manage_users_and_invalid_upload_is_rejected(): void
    {
        $this->account('amministratore');
        $this->delete('/documenti/1')->assertForbidden();
        $this->post('/utenti')->assertForbidden();
        $this->post('/documenti', ['file' => UploadedFile::fake()->createWithContent('payload.html', '<script>alert(1)</script>')])
            ->assertSessionHasErrors(['title', 'supplier', 'date', 'amount', 'file']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_unknown_role_cannot_promote_itself_using_user_metadata(): void
    {
        $this->account('unknown', ['role' => 'Super_user']);
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_expired_session_refreshes_tokens(): void
    {
        $this->account('condomino');
        Http::fake(['https://project.supabase.co/auth/v1/token?grant_type=refresh_token' => Http::response([
            'access_token' => 'renewed', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600,
        ])]);
        $this->withSession(['supabase' => ['access_token' => 'old', 'refresh_token' => 'refresh', 'expires_at' => 0]])
            ->get('/dashboard')->assertOk()->assertSessionHas('supabase.access_token', 'renewed');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer renewed'));
    }

    public function test_revoked_session_returns_to_login(): void
    {
        $this->withSession(['supabase' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
        Http::fake(['https://project.supabase.co/auth/v1/user' => Http::response([], 401)]);
        $this->get('/dashboard')->assertRedirect('/')->assertSessionMissing('supabase');
    }

    public function test_super_user_creates_account_with_protected_role(): void
    {
        $this->account('Super_user');
        Http::fake(['https://project.supabase.co/auth/v1/admin/users' => Http::response(['id' => 'new-user'])]);
        $this->post('/utenti', ['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'long-password-123', 'role' => 'amministratore'])
            ->assertRedirect('/profilo');
        Http::assertSent(fn ($request) => $request->url() === 'https://project.supabase.co/auth/v1/admin/users'
            && $request['app_metadata']['role'] === 'amministratore' && $request->hasHeader('apikey', 'secret-test'));
    }

    public function test_super_user_can_delete_document_and_manage_users_from_profile(): void
    {
        $this->account('Super_user');
        Http::fake(['https://project.supabase.co/auth/v1/admin/users?*' => Http::response(['users' => []])]);
        $id = DB::table('documents')->insertGetId([
            'title' => 'Riservato', 'supplier' => 'Studio', 'category' => 'Spese', 'date' => '2026-10-08',
            'amount' => 100, 'uploaded_by' => '11111111-1111-4111-8111-111111111111',
            'content' => base64_encode('%PDF-sample'), 'mime_type' => 'application/pdf', 'extension' => 'pdf',
        ]);
        $this->get('/profilo')->assertOk()->assertSee('Crea utente');
        $this->delete('/documenti/'.$id)->assertRedirect('/dashboard');
        $this->assertDatabaseMissing('documents', ['id' => $id]);
    }

    #[TestWith(['condomino'])]
    #[TestWith(['amministratore'])]
    public function test_only_super_users_can_view_and_manage_enabled_users(string $role): void
    {
        $this->account($role, ['role' => 'Super_user']);
        $id = '22222222-2222-4222-8222-222222222222';
        $this->get('/profilo')->assertOk()->assertDontSee('Utenti abilitati');
        $this->patch('/utenti/'.$id.'/ruolo', ['role' => 'Super_user'])->assertForbidden();
        $this->delete('/utenti/'.$id)->assertForbidden();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/admin/users'));
    }

    public function test_guests_cannot_manage_users(): void
    {
        $id = '22222222-2222-4222-8222-222222222222';
        $this->patch('/utenti/'.$id.'/ruolo', ['role' => 'Super_user'])->assertRedirect('/');
        $this->delete('/utenti/'.$id)->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_super_user_sees_all_pages_of_enabled_users_with_escaped_names(): void
    {
        $this->account('Super_user');
        $user = ['id' => '22222222-2222-4222-8222-222222222222', 'email' => 'resident@example.com',
            'app_metadata' => ['role' => 'condomino'], 'user_metadata' => ['name' => '<script>alert(1)</script>']];
        Http::fake(['https://project.supabase.co/auth/v1/admin/users?*' => function ($request) use ($user) {
            if ($request['page'] == 1) {
                return Http::response(['users' => array_fill(0, 100, ['id' => 'ignored', 'email' => 'unknown@example.com', 'app_metadata' => ['role' => 'unknown']])]);
            }

            return Http::response(['users' => [$user, [
                'id' => '33333333-3333-4333-8333-333333333333', 'email' => 'banned@example.com',
                'app_metadata' => ['role' => 'condomino'], 'banned_until' => '2099-01-01T00:00:00Z',
            ], [
                'id' => '11111111-1111-4111-8111-111111111111', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'Super_user'],
            ]]]);
        }]);
        $this->get('/profilo')->assertOk()->assertSee('Utenti abilitati')->assertSee('resident@example.com')
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('unknown@example.com')->assertDontSee('banned@example.com')
            ->assertSee('Il tuo account: ruolo e cancellazione protetti.')
            ->assertDontSee('/utenti/11111111-1111-4111-8111-111111111111', false);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/users?') && $request['page'] == 2 && $request->hasHeader('apikey', 'secret-test'));
    }

    #[TestWith(['condomino'])]
    #[TestWith(['amministratore'])]
    #[TestWith(['Super_user'])]
    public function test_super_user_updates_protected_role(string $role): void
    {
        $this->account('Super_user');
        $id = '22222222-2222-4222-8222-222222222222';
        Http::fake(['https://project.supabase.co/auth/v1/admin/users/'.$id => Http::response(['id' => $id])]);
        $this->patch('/utenti/'.$id.'/ruolo', ['role' => $role, 'email' => 'unexpected@example.com'])
            ->assertRedirect('/profilo')->assertSessionHas('status', 'Ruolo utente aggiornato.');
        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->data() === ['app_metadata' => ['role' => $role]] && $request->hasHeader('apikey', 'secret-test'));
    }

    public function test_super_user_deletes_other_account(): void
    {
        $this->account('Super_user');
        $id = '22222222-2222-4222-8222-222222222222';
        Http::fake(['https://project.supabase.co/auth/v1/admin/users/'.$id => Http::response(['id' => $id])]);
        $this->delete('/utenti/'.$id)->assertRedirect('/profilo')->assertSessionHas('status', 'Utente cancellato.');
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://project.supabase.co/auth/v1/admin/users/'.$id && $request->hasHeader('apikey', 'secret-test'));
    }

    public function test_super_user_cannot_delete_or_change_own_role_and_invalid_roles_are_rejected(): void
    {
        $this->account('Super_user');
        $id = '11111111-1111-4111-8111-111111111111';
        $this->delete('/utenti/'.$id)->assertForbidden();
        $this->patch('/utenti/'.$id.'/ruolo', ['role' => 'condomino'])->assertForbidden();
        $this->patch('/utenti/22222222-2222-4222-8222-222222222222/ruolo', ['role' => 'owner'])->assertSessionHasErrors('role');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/admin/users'));
    }

    public function test_supabase_rejects_user_changes_without_success_message(): void
    {
        $this->account('Super_user');
        $id = '22222222-2222-4222-8222-222222222222';
        Http::fake(['https://project.supabase.co/auth/v1/admin/users/'.$id => Http::response([], 404)]);
        $this->patch('/utenti/'.$id.'/ruolo', ['role' => 'amministratore'])->assertSessionHasErrors('role')->assertSessionMissing('status');
        $this->delete('/utenti/'.$id)->assertSessionHasErrors('user')->assertSessionMissing('status');
    }

    public function test_password_change_verifies_current_password_with_supabase(): void
    {
        $this->account('condomino');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://project.supabase.co/auth/v1/user' => function ($request) {
            if ($request->method() === 'GET') {
                return Http::response(['id' => '11111111-1111-4111-8111-111111111111', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']]);
            }

            return Http::response([], 400);
        }]);
        $this->post('/profilo/password', ['current_password' => 'wrong-password', 'password' => 'long-new-password', 'password_confirmation' => 'long-new-password'])
            ->assertSessionHasErrors('current_password');
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['current_password'] === 'wrong-password');
        $this->assertNull(session()->getOldInput('current_password'));
    }

    public function test_supabase_outage_returns_service_unavailable(): void
    {
        Http::fake(['https://project.supabase.co/auth/v1/token?grant_type=password' => Http::failedConnection()]);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret'])->assertStatus(503);
        Http::assertSentCount(1);
    }

    public function test_logout_revokes_remote_session_and_clears_local_tokens(): void
    {
        $this->account('condomino');
        Http::fake(['https://project.supabase.co/auth/v1/logout?scope=local' => Http::response([], 204)]);
        $this->post('/logout')->assertRedirect('/')->assertSessionMissing('supabase');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/logout?scope=local'));
    }

    public function test_rejected_refresh_clears_session(): void
    {
        Http::fake(['https://project.supabase.co/auth/v1/token?grant_type=refresh_token' => Http::response([], 400)]);
        $this->withSession(['supabase' => ['access_token' => 'old', 'refresh_token' => 'revoked', 'expires_at' => 0]])
            ->get('/dashboard')->assertRedirect('/')->assertSessionMissing('supabase');
        Http::assertSentCount(1);
    }
}
