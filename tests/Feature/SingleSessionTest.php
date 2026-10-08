<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SingleSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.supabase.url' => 'https://project.supabase.co', 'services.supabase.publishable_key' => 'public-test']);
        Http::preventStrayRequests();
    }

    public function test_new_login_disconnects_previous_device_and_refresh_does_not_restore_it(): void
    {
        $user = ['id' => 'user-one', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']];
        Http::fake([
            'https://project.supabase.co/auth/v1/token?grant_type=password' => Http::response([
                'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'user' => $user,
            ]),
            'https://project.supabase.co/auth/v1/token?grant_type=refresh_token' => Http::response([
                'access_token' => 'renewed', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600,
            ]),
            'https://project.supabase.co/auth/v1/user' => Http::response($user),
        ]);
        $credentials = ['email' => 'test@example.com', 'password' => 'secret'];
        $this->post('/login', $credentials)->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $previousSession = session()->all();
        session()->invalidate();

        $this->post('/login', $credentials)->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $currentSession = session()->all();

        session()->flush();
        $previousSession['supabase']['expires_at'] = 0;
        $this->withSession($previousSession)->get('/dashboard')->assertRedirect('/')
            ->assertSessionMissing('supabase')->assertSessionHasErrors('email');
        $this->post('/documenti')->assertRedirect('/');

        session()->flush();
        $currentSession['supabase']['expires_at'] = 0;
        $this->withSession($currentSession)->get('/dashboard')->assertOk()
            ->assertSessionHas('supabase.access_token', 'renewed');
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer renewed'));
    }

    public function test_failed_login_does_not_disconnect_existing_device(): void
    {
        $user = ['id' => 'user-one', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']];
        Http::fake([
            'https://project.supabase.co/auth/v1/token?grant_type=password' => Http::sequence()
                ->push(['access_token' => 'access', 'refresh_token' => 'refresh', 'user' => $user])
                ->push([], 400),
            'https://project.supabase.co/auth/v1/user' => Http::response($user),
        ]);
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret'])->assertRedirect('/dashboard');
        $previousSession = session()->all();
        session()->invalidate();
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        session()->flush();
        $this->withSession($previousSession)->get('/dashboard')->assertOk();
        Http::assertSentCount(3);
    }
}
