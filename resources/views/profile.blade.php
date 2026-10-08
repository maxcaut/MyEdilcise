@extends('layouts.app')
@section('title', 'Profilo')
@section('content')
<section id="profile"><div class="eyebrow">Il tuo account</div><h1>Il mio profilo.</h1><p>Gestisci i dati del tuo account.</p><div class="profile"><form id="info-form" class="card" method="POST" action="{{ route('profile.update') }}">@csrf<h2>Informazioni personali</h2><p class="subtle">I dati associati al tuo accesso al condominio.</p><div class="fields"><div><label for="name">Nome</label><input id="name" name="name" value="{{ old('name', $account->name) }}" required autocomplete="given-name"></div><div><label for="surname">Cognome</label><input id="surname" name="surname" value="{{ old('surname', $account->surname) }}" required autocomplete="family-name"></div><div><label for="profile-email">Email</label><input id="profile-email" name="email" type="email" value="{{ old('email', $account->email) }}" required autocomplete="email"></div><div><label for="phone">Telefono · facoltativo</label><input id="phone" name="phone" value="{{ old('phone', $account->phone) }}" type="tel" placeholder="Aggiungi un recapito" autocomplete="tel"></div></div><footer><span class="subtle">{{ $account->role }}</span><button>Salva modifiche</button></footer></form><form id="password-form" class="card" method="POST" action="{{ route('profile.password') }}">@csrf<h2>Cambia password</h2><p class="subtle">Scegli una password di almeno 12 caratteri.</p><label for="old-password">Password attuale</label><input id="old-password" name="current_password" type="password" required autocomplete="current-password"><div class="fields"><div><label for="new-password">Nuova password</label><input id="new-password" name="password" type="password" minlength="12" required autocomplete="new-password"></div><div><label for="confirm-password">Conferma nuova password</label><input id="confirm-password" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password"></div></div><footer><span class="subtle">Non usare password già utilizzate altrove.</span><button>Aggiorna password</button></footer></form></div></section>
@if(in_array($account->role, ['Super_user', 'amministratore'], true))
<section class="card" aria-labelledby="telegram-heading">
    <h2 id="telegram-heading">Documenti da Telegram</h2>
    <p class="subtle">Invia PDF o foto al bot. Ti chiederà i dati mancanti e una conferma prima di caricare il documento.</p>
    @if(!$telegramConfigured)
        <p>Il collegamento Telegram sarà disponibile quando il gestore avrà configurato il bot.</p>
    @else
        @if($telegramAccount?->telegram_id)
            <p>Account Telegram collegato.</p>
            <form method="POST" action="{{ route('profile.telegram.destroy') }}">
                @csrf @method('DELETE')
                <button type="submit" class="text-button">Scollega Telegram</button>
            </form>
        @endif
        <form method="POST" action="{{ route('profile.telegram.store') }}">
            @csrf
            <button type="submit">{{ $telegramAccount?->telegram_id ? 'Collega un altro account' : 'Collega Telegram' }}</button>
        </form>
        @if(session('telegram_link'))
            <p><a href="{{ session('telegram_link') }}" target="_blank" rel="noopener noreferrer">Apri Telegram e premi Avvia</a></p>
            <p class="subtle">Il collegamento è personale, utilizzabile una sola volta e scade dopo 10 minuti.</p>
        @endif
        <p class="subtle">PDF, JPG e PNG fino a 10 MB. Un documento alla volta; scrivi /annulla per ricominciare. I caricamenti incompleti scadono dopo 24 ore.</p>
        <details>
            <summary>Come compilare la didascalia</summary>
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
<form class="card" method="POST" action="{{ route('users.store') }}">
@csrf<h2>Crea utente</h2>
<label for="user-name">Nome</label><input id="user-name" name="name" required maxlength="100">
<label for="user-email">Email</label><input id="user-email" name="email" type="email" required>
<label for="user-password">Password iniziale</label><input id="user-password" name="password" type="password" minlength="12" autocomplete="new-password" required>
<label for="user-role">Ruolo</label><select id="user-role" name="role"><option value="condomino">Condomino</option><option value="amministratore">Amministratore</option><option value="Super_user">Super user</option></select>
<button type="submit">Crea utente</button></form>
@endif
@endsection
