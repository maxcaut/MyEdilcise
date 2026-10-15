@extends('layouts.app')
@section('title', 'Impostazioni')
@section('content')
<section id="settings">
<div class="eyebrow">Amministrazione</div><h1>Impostazioni.</h1><p>Personalizza l’app e gestisci gli accessi al condominio.</p>
<div class="profile">
<form class="card" method="POST" action="{{ route('settings.update') }}">
@csrf @method('PUT')
<h2>Identità del condominio</h2><p class="subtle">Le modifiche si applicano a tutti gli utenti.</p>
<div class="fields">
<div><label for="condominio-name">Nome del condominio</label><input id="condominio-name" name="name" value="{{ old('name', config('condominio.name')) }}" maxlength="150" required></div>
<div><label for="condominio-city">Città · facoltativa</label><input id="condominio-city" name="city" value="{{ old('city', config('condominio.city')) }}" maxlength="150"></div>
</div>
<label for="logo-url">Logo · URL o percorso</label><input id="logo-url" name="logo_url" value="{{ old('logo_url', config('condominio.logo_url')) }}" maxlength="2048" aria-describedby="identity-help">
<label for="favicon-url">Favicon · URL o percorso</label><input id="favicon-url" name="favicon_url" value="{{ old('favicon_url', config('condominio.favicon_url')) }}" maxlength="2048" aria-describedby="identity-help">
<p id="identity-help" class="subtle">Usa un URL http/https oppure il percorso di un’immagine già presente, ad esempio img/edilcise-transparent.png. Lasciando vuoto il logo verrà mostrato il nome; per la favicon verrà usata l’icona predefinita.</p>
<h2>Integrazione Telegram</h2>
<input type="hidden" name="telegram_enabled" value="0">
<label class="settings-toggle" for="telegram-enabled"><input id="telegram-enabled" type="checkbox" role="switch" name="telegram_enabled" value="1" @checked(old('telegram_enabled', $telegramEnabled)) aria-describedby="telegram-help"><span>Abilita Telegram</span></label>
<p id="telegram-help" class="subtle">Disattivando Telegram si bloccano nuovi collegamenti e caricamenti dal bot per tutti. Gli account collegati restano salvati e torneranno utilizzabili alla riattivazione.</p>
@if(!$telegramConfigured)<p class="subtle">Il bot non è ancora configurato: l’attivazione richiede anche la configurazione del servizio.</p>@endif
<footer><span class="subtle">Le impostazioni vengono salvate per tutta l’app.</span><button type="submit">Salva impostazioni</button></footer>
</form>
<details class="card user-management profile-disclosure" aria-labelledby="accesses-heading">
    <summary><h2 id="accesses-heading">Accessi all’app</h2></summary>
    <form method="POST" action="{{ route('profile.accesses.reset') }}">
        @csrf
        <button type="submit">Azzera visualizzazione</button>
    </form>
    <p class="subtle">Il reset nasconde gli accessi precedenti. Gli accessi successivi compariranno nella tabella.</p>
    <div class="table-wrap access-table">
        <table>
            <thead><tr><th scope="col">Nome utente</th><th scope="col">Email</th><th scope="col">Ultimo accesso · ora italiana</th></tr></thead>
            <tbody>
                @forelse($accessedUsers as $accessedUser)
                    <tr>
                        <td>{{ trim(($accessedUser['user_metadata']['name'] ?? '').' '.($accessedUser['user_metadata']['surname'] ?? '')) ?: '—' }}</td>
                        <td>{{ $accessedUser['email'] ?? '—' }}</td>
                        <td>{{ \Illuminate\Support\Carbon::parse($accessedUser['last_sign_in_at'])->timezone('Europe/Rome')->format('d/m/Y H:i:s') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3">Nessun accesso registrato.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</details>
<section class="card user-management" aria-labelledby="telegram-accounts-heading">
    <h2 id="telegram-accounts-heading">Account collegati a Telegram</h2>
    <p class="subtle">Revoca un collegamento per interrompere l’accesso al bot e annullare eventuali caricamenti incompleti. L’utente potrà collegarsi nuovamente dal proprio profilo.</p>
    <div class="user-list">
        @forelse($linkedTelegramAccounts as $linkedAccount)
            @php($linkedUser = collect($enabledUsers)->firstWhere('id', $linkedAccount->user_id))
            <article class="user-entry">
                <div>
                    <strong>{{ trim(($linkedUser['user_metadata']['name'] ?? '').' '.($linkedUser['user_metadata']['surname'] ?? '')) ?: ($linkedUser['email'] ?? $linkedAccount->user_id) }}</strong>
                    <div class="subtle">{{ $linkedUser['email'] ?? 'Utente non più presente tra gli utenti abilitati' }}</div>
                    <span class="pill">Telegram ID: {{ $linkedAccount->telegram_id }}</span>
                </div>
                <form method="POST" action="{{ route('profile.telegram.revoke', $linkedAccount->id) }}" onsubmit="return confirm('Revocare questo collegamento Telegram? Eventuali caricamenti incompleti saranno annullati.');">
                    @csrf @method('DELETE')
                    <button type="submit" class="text-button user-delete">Revoca collegamento Telegram</button>
                </form>
            </article>
        @empty
            <p>Nessun account collegato a Telegram.</p>
        @endforelse
    </div>
</section>
<details class="card user-management profile-disclosure" aria-labelledby="users-heading">
    <summary><h2 id="users-heading">Utenti abilitati</h2></summary>
    <p class="subtle">Gestisci gli account che possono accedere alla web app e assegna il ruolo desiderato.</p>
    <div class="user-list">
        @forelse($enabledUsers as $enabledUser)
            <article class="user-entry">
                <div>
                    <strong>{{ trim(($enabledUser['user_metadata']['name'] ?? '').' '.($enabledUser['user_metadata']['surname'] ?? '')) ?: 'Utente' }}</strong>
                    <div class="subtle">{{ $enabledUser['email'] ?? '' }}</div>
                    <span class="pill">{{ $enabledUser['app_metadata']['role'] }}</span>
                </div>
                @if($enabledUser['id'] === $account->id)
                    <p class="subtle">Il tuo account: ruolo e cancellazione protetti.</p>
                @else
                    <form class="user-role-form" method="POST" action="{{ route('users.role.update', $enabledUser['id']) }}">
                        @csrf @method('PATCH')
                        <label for="role-{{ $enabledUser['id'] }}">Ruolo di {{ $enabledUser['email'] ?? 'utente' }}</label>
                        <div class="user-actions">
                            <select id="role-{{ $enabledUser['id'] }}" name="role">
                                @foreach(['condomino' => 'Condomino', 'amministratore' => 'Amministratore', 'Super_user' => 'Super user'] as $roleValue => $roleLabel)
                                    <option value="{{ $roleValue }}" @selected($enabledUser['app_metadata']['role'] === $roleValue)>{{ $roleLabel }}</option>
                                @endforeach
                            </select>
                            <button type="submit">Aggiorna ruolo</button>
                        </div>
                    </form>
                    <form method="POST" action="{{ route('users.destroy', $enabledUser['id']) }}" onsubmit="return confirm('Cancellare definitivamente questo utente? Non potrà più accedere alla web app.');">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-button user-delete" aria-label="Cancella utente {{ $enabledUser['email'] ?? '' }}">Cancella utente</button>
                    </form>
                @endif
            </article>
        @empty
            <p>Nessun utente abilitato.</p>
        @endforelse
    </div>
</details>
<form class="card" method="POST" action="{{ route('users.store') }}">
@csrf<h2>Crea utente</h2>
<label for="user-name">Nome</label><input id="user-name" name="name" required maxlength="100">
<label for="user-email">Email</label><input id="user-email" name="email" type="email" required>
<label for="user-password">Password iniziale</label><input id="user-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
<label for="user-role">Ruolo</label><select id="user-role" name="role"><option value="condomino">Condomino</option><option value="amministratore">Amministratore</option><option value="Super_user">Super user</option></select>
<button type="submit">Crea utente</button></form>
</div></section>
@endsection
