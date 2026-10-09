<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
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

    public function test_pages_use_the_configured_condominium_identity(): void
    {
        config([
            'app.name' => 'Portale Aurora',
            'condominio.name' => 'Condominio Aurora',
            'condominio.city' => 'Napoli',
            'condominio.logo_url' => 'https://images.example.com/aurora.png',
            'condominio.favicon_url' => 'https://images.example.com/favicon.png',
        ]);

        foreach (['/', '/dashboard', '/profilo', '/documenti/1'] as $path) {
            $this->get($path)->assertOk()->assertSee('Portale Aurora')->assertSee('Condominio Aurora')
                ->assertSee('Napoli')->assertSee('https://images.example.com/aurora.png')
                ->assertSee('https://images.example.com/favicon.png')
                ->assertDontSee('EdilCise')->assertDontSee('Somma Vesuviana');
        }
    }

    public function test_pages_show_the_name_when_the_logo_and_city_are_empty(): void
    {
        config([
            'condominio.name' => 'Condominio Aurora',
            'condominio.city' => '',
            'condominio.logo_url' => '',
            'condominio.favicon_url' => '',
        ]);

        $this->get('/')->assertOk()->assertSee('Condominio Aurora')
            ->assertSee('favicon.ico')->assertDontSee('img/edilcise-transparent.png')
            ->assertDontSee('Somma Vesuviana');
    }

    public function test_search_and_category_filter_together(): void
    {
        $this->get('/dashboard?month=2026-10&q=ASCENSORE&category=Manutenzione')
            ->assertOk()->assertSee('Manutenzione ascensore')->assertDontSee('Riparazione cancello');
        $this->get('/dashboard?q=inesistente')->assertOk()->assertSee('Nessun documento corrisponde');
        $this->get('/dashboard?q[]=unexpected')->assertSessionHasErrors('q');
    }

    public function test_invalid_document_returns_not_found(): void
    {
        $this->get('/documenti/999')->assertNotFound();
    }

    public function test_monthly_total_resets_at_the_start_of_the_month_in_italy(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(22, 59, 59)->utc());
        $this->get('/dashboard')->assertOk()->assertViewHas('selectedMonth', '2026-10')
            ->assertSee('€ 200,00');

        $this->travelTo(now()->setDate(2026, 10, 31)->setTime(23, 0, 0)->utc());
        $this->get('/dashboard')->assertOk()->assertViewHas('selectedMonth', '2026-11')
            ->assertSee('€ 0,00')->assertDontSee('Manutenzione ascensore');
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_monthly_archive_uses_expense_dates_and_includes_both_month_boundaries(): void
    {
        DB::table('documents')->update(['created_at' => '2027-01-01 12:00:00']);
        DB::table('documents')->where('title', 'Manutenzione ascensore')->update(['date' => '2026-10-01']);
        DB::table('documents')->where('title', 'Riparazione cancello')->update(['date' => '2026-10-31']);

        foreach (['2026-09-30', '2026-11-01', '2025-10-08'] as $date) {
            DB::table('documents')->insert([
                'title' => 'Spesa fuori mese', 'supplier' => 'Fornitore', 'amount' => 500,
                'date' => $date, 'uploaded_by' => '11111111-1111-4111-8111-111111111111',
            ]);
        }

        $this->get('/dashboard?month=2026-10')->assertOk()->assertSee('ottobre 2026')
            ->assertSee('€ 200,00')->assertSee('Manutenzione ascensore')->assertSee('Riparazione cancello')
            ->assertDontSee('Spesa fuori mese');
        $this->get('/dashboard?month=2025-10')->assertOk()->assertSee('ottobre 2025')
            ->assertSee('€ 500,00')->assertDontSee('Manutenzione ascensore');
    }

    public function test_search_and_category_do_not_change_the_monthly_total(): void
    {
        DB::table('documents')->where('title', 'Riparazione cancello')->update(['category' => 'Altro']);

        $this->get('/dashboard?month=2026-10&q=ascensore&category=Manutenzione')
            ->assertOk()->assertSee('€ 200,00')->assertSee('Manutenzione ascensore')
            ->assertDontSee('Riparazione cancello')->assertSee('value="2026-10"', false);
        $this->get('/dashboard?month=2026-10&q=inesistente')
            ->assertOk()->assertSee('€ 200,00')->assertSee('Nessun documento corrisponde');
    }

    #[TestWith(['2026-13'])]
    #[TestWith(['2026-10-01'])]
    #[TestWith(['invalid'])]
    #[TestWith([['2026-10']])]
    public function test_invalid_month_is_rejected(string|array $month): void
    {
        $this->get('/dashboard?'.http_build_query(['month' => $month]))->assertSessionHasErrors('month');
    }

    #[TestWith(['2026-01', '2025-12', '2026-02'])]
    #[TestWith(['2026-12', '2026-11', '2027-01'])]
    public function test_month_navigation_crosses_years_and_preserves_filters(string $month, string $previous, string $next): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8)->setTime(12, 0));

        $this->get('/dashboard?'.http_build_query(['q' => 'ascensore', 'category' => 'Manutenzione', 'month' => $month]))
            ->assertOk()
            ->assertSee('href="'.e(route('dashboard', ['q' => 'ascensore', 'category' => 'Manutenzione', 'month' => $previous])).'"', false)
            ->assertSee('href="'.e(route('dashboard', ['q' => 'ascensore', 'category' => 'Manutenzione', 'month' => $next])).'"', false)
            ->assertSee('href="'.e(route('dashboard', ['q' => 'ascensore', 'category' => 'Manutenzione', 'month' => '2026-10'])).'"', false)
            ->assertSee('Mese precedente')->assertSee('Mese successivo')->assertSee('Mese corrente');
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
