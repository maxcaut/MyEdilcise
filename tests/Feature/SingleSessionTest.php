<?php

namespace Tests\Feature;

use App\Supabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    public function test_database_session_and_active_access_survive_reloading_the_stores(): void
    {
        config(['session.driver' => 'database', 'session.encrypt' => true, 'cache.default' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $userId = '11111111-1111-4111-8111-111111111111';
        $user = ['id' => $userId, 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']];
        Http::fake([
            'https://project.supabase.co/auth/v1/token?grant_type=password' => Http::response([
                'access_token' => 'access', 'refresh_token' => 'refresh', 'expires_in' => 3600, 'user' => $user,
            ]),
            'https://project.supabase.co/auth/v1/user' => Http::response($user),
        ]);

        $response = $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret'])
            ->assertRedirect('/conferma-identita');
        $cookieName = config('session.cookie');
        $cookie = $response->getCookie($cookieName, decrypt: false);
        $this->assertNotNull($cookie);
        $response = $this->withUnencryptedCookie($cookieName, $cookie->getValue())
            ->post('/conferma-identita', ['identity_confirmed' => '1'])->assertRedirect('/dashboard');
        $cookie = $response->getCookie($cookieName, decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertDatabaseCount('sessions', 1);
        $this->assertStringNotContainsString('access_token', base64_decode(DB::table('sessions')->value('payload'), true));

        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Cache::forgetDriver('database');

        $this->withUnencryptedCookie($cookieName, $cookie->getValue())->get('/dashboard')->assertOk();
        $this->assertDatabaseHas('sessions', ['user_id' => null]);
        $this->assertNotNull(Cache::get('supabase.active_session.'.$userId));
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
        $this->post('/login', $credentials)->assertRedirect('/conferma-identita');
        $this->post('/conferma-identita', ['identity_confirmed' => '1'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertOk();
        $previousSession = session()->all();
        session()->invalidate();

        $this->post('/login', $credentials)->assertRedirect('/conferma-identita');
        $this->post('/conferma-identita', ['identity_confirmed' => '1'])->assertRedirect('/dashboard');
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
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'secret'])->assertRedirect('/conferma-identita');
        $this->post('/conferma-identita', ['identity_confirmed' => '1'])->assertRedirect('/dashboard');
        $previousSession = session()->all();
        session()->invalidate();
        $this->post('/login', ['email' => 'test@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        session()->flush();
        $this->withSession($previousSession)->get('/dashboard')->assertOk();
        Http::assertSentCount(3);
    }

    public function test_navigation_reuses_user_until_expiry_and_writes_always_check_again(): void
    {
        $this->freezeTime();
        Http::fake(['https://project.supabase.co/auth/v1/user' => Http::sequence()
            ->push(['id' => 'user-one', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']])
            ->push(['id' => 'user-one', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']])
            ->push([], 401)]);
        $this->withSession(['supabase' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);

        $this->get('/dashboard')->assertOk();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(1);
        $this->travel(21)->seconds();
        $this->get('/dashboard')->assertOk();
        Http::assertSentCount(2);
        $this->post('/documenti')->assertRedirect('/')->assertSessionMissing('supabase');
        Http::assertSentCount(3);
    }

    public function test_user_invalidation_rejects_cached_access_immediately(): void
    {
        Http::fake(['https://project.supabase.co/auth/v1/user' => Http::sequence()
            ->push(['id' => 'user-one', 'email' => 'test@example.com', 'app_metadata' => ['role' => 'condomino']])
            ->push([], 401)]);
        $this->withSession(['supabase' => ['access_token' => 'access', 'refresh_token' => 'refresh', 'expires_at' => time() + 3600]]);
        $this->get('/dashboard')->assertOk();

        app(Supabase::class)->invalidateUsers('user-one');

        $this->get('/dashboard')->assertRedirect('/')->assertSessionMissing('supabase');
        Http::assertSentCount(2);
    }
}
