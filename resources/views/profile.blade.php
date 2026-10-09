@extends('layouts.app')
@section('title', 'Profilo')
@section('content')
<section id="profile"><div class="eyebrow">Il tuo account</div><h1>Il mio profilo.</h1><p>Gestisci i dati del tuo account.</p><div class="profile"><form id="info-form" class="card" method="POST" action="{{ route('profile.update') }}">@csrf<h2>Informazioni personali</h2><p class="subtle">I dati associati al tuo accesso al condominio.</p><div class="fields"><div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $account->name) }}" required autocomplete="given-name"></div><div><label for="surname">Cognome</label><input id="surname" name="surname" value="{{ old('surname', $account->surname) }}" required autocomplete="family-name"></div><div><label for="profile-email">Email</label><input id="profile-email" name="email" type="email" value="{{ old('email', $account->email) }}" required autocomplete="email"></div><div><label for="phone">Telefono · facoltativo</label><input id="phone" name="phone" value="{{ old('phone', $account->phone) }}" type="tel" placeholder="Aggiungi un recapito" autocomplete="tel"></div></div><footer><span class="subtle">{{ $account->role }}</span><button>Salva modifiche</button></footer></form><form id="password-form" class="card" method="POST" action="{{ route('profile.password') }}">@csrf<h2>Cambia password</h2><p class="subtle">Scegli una password di almeno 12 caratteri.</p><label for="old-password">Password attuale</label><input id="old-password" name="current_password" type="password" required autocomplete="current-password"><div class="fields"><div><label for="new-password">Nuova password</label><input id="new-password" name="password" type="password" minlength="12" required autocomplete="new-password"></div><div><label for="confirm-password">Conferma nuova password</label><input id="confirm-password" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password"></div></div><footer><span class="subtle">Non usare password già utilizzate altrove.</span><button>Aggiorna password</button></footer></form>
@if(in_array($account->role, ['Super_user', 'amministratore'], true))
<section class="card telegram-card" aria-labelledby="telegram-heading">
    <h2 id="telegram-heading">Documenti da Telegram</h2>
    <p class="subtle">Invia PDF o foto al bot. Ti chiederà i dati mancanti e una conferma prima di caricare il documento.</p>
    @if(!$telegramConfigured)
        <p>Il collegamento Telegram sarà disponibile quando il gestore avrà configurato il bot.</p>
    @else
        @if($telegramAccount?->telegram_id)
            <p class="subtle">Account Telegram collegato.</p>
        @endif
        <div class="user-actions">
        @if($telegramAccount?->telegram_id)
            <form method="POST" action="{{ route('profile.telegram.destroy') }}">
                @csrf @method('DELETE')
                <button type="submit" class="text-button">Scollega Telegram</button>
            </form>
        @endif
        <form method="POST" action="{{ route('profile.telegram.store') }}">
            @csrf
            <button type="submit">{{ $telegramAccount?->telegram_id ? 'Collega un altro account' : 'Collega Telegram' }}</button>
        </form>
        </div>
        @if(session('telegram_link'))
            <p><a href="{{ session('telegram_link') }}" target="_blank" rel="noopener noreferrer">Apri Telegram e premi Avvia</a></p>
            <p class="subtle">Il collegamento è personale, utilizzabile una sola volta e scade dopo 10 minuti.</p>
        @endif
        <details class="telegram-help">
            <summary>Formati e istruzioni di invio</summary>
            <p class="subtle">PDF, JPG e PNG fino a 10 MB. Un documento alla volta; scrivi /annulla per ricominciare. I caricamenti incompleti scadono dopo 24 ore.</p>
            <h3>Come compilare la didascalia</h3>
            <p>Puoi anticipare i dati scrivendo una voce per riga:</p>
            <pre>Titolo: Fattura ottobre
Fornitore: Rossi Materiali
Data: 08/10/2026
Importo: 125,50
Categoria: Manutenzione</pre>
        </details>
    @endif
</section>
@endif
@if($account->role === 'Super_user')
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
@endif
</div></section>
@endsection
