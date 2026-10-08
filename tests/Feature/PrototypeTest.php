<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrototypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://project.supabase.co', 'services.supabase.publishable_key' => 'test-key']);
        Http::preventStrayRequests();
        Http::fake([
            'https://project.supabase.co/auth/v1/user' => Http::response([
                'id' => '11111111-1111-4111-8111-111111111111', 'email' => 'mario@example.com',
                'app_metadata' => ['role' => 'condomino'],
            ]),
        ]);
        $this->withSession(['supabase' => ['access_token' => 'token', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
        foreach ([['Manutenzione ascensore', 'Manutenzione'], ['Riparazione cancello', 'Manutenzione']] as [$title, $category]) {
            DB::table('documents')->insert([
                'title' => $title, 'category' => $category, 'supplier' => 'Fornitore', 'amount' => 100,
                'date' => '2026-10-08', 'uploaded_by' => '11111111-1111-4111-8111-111111111111',
                'content' => base64_encode('%PDF-example'), 'mime_type' => 'application/pdf', 'extension' => 'pdf',
            ]);
        }
    }

    public function test_pages_render_with_transparent_logo(): void
    {
        foreach (['/', '/dashboard', '/profilo', '/documenti/1'] as $path) {
            $this->get($path)->assertOk()->assertSee('img/edilcise-transparent.png')->assertDontSee('PROTOTIPO');
        }
    }

    public function test_search_and_category_filter_together(): void
    {
        $this->get('/dashboard?q=ASCENSORE&category=Manutenzione')
            ->assertOk()->assertSee('Manutenzione ascensore')->assertDontSee('Riparazione cancello');
        $this->get('/dashboard?q=inesistente')->assertOk()->assertSee('Nessun documento corrisponde');
        $this->get('/dashboard?q[]=unexpected')->assertSessionHasErrors('q');
    }

    public function test_invalid_document_returns_not_found(): void
    {
        $this->get('/documenti/999')->assertNotFound();
    }

    public function test_password_confirmation_is_required_and_secrets_are_not_flashed(): void
    {
        $this->from('/profilo')->post('/profilo/password', [
            'current_password' => 'inventata-attuale',
            'password' => 'nuova-inventata-123',
            'password_confirmation' => 'diversa-inventata',
        ])->assertRedirect('/profilo')->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('current_password'));
    }

    public function test_valid_profile_form_saves_to_supabase(): void
    {
        $this->post('/profilo', ['name' => 'Mario', 'surname' => 'Rossi', 'email' => 'mario@example.com'])
            ->assertRedirect('/profilo')->assertSessionHas('status', 'Profilo aggiornato. Se hai cambiato email, conferma i messaggi inviati da Supabase.');
    }
}
