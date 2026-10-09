@extends('layouts.app')

@section('title', 'Conferma la tua identità')

@section('content')
    <div class="identity-backdrop">
        <section class="identity-popup" role="dialog" aria-modal="true" aria-labelledby="identity-title" aria-describedby="identity-description">
            <div class="brand">@include('components.brand')</div>
            <h1 id="identity-title">Sei proprio tu?</h1>
            <p id="identity-description">Hai effettuato l’accesso come <strong>{{ trim($account->name.' '.$account->surname) ?: $account->email }}</strong> ({{ $account->email }}). Per proteggere la tua privacy e i dati del condominio, conferma di essere il titolare di questo account prima di continuare.</p>
            <form method="POST" action="{{ route('identity.confirm.store') }}">
                @csrf
                <label class="identity-checkbox" for="identity-confirmed">
                    <input id="identity-confirmed" name="identity_confirmed" type="checkbox" value="1" required autofocus>
                    <span>Confermo di essere il titolare dell’account che ha effettuato l’accesso.</span>
                </label>
                @error('identity_confirmed')
                    <p role="alert">{{ $message }}</p>
                @enderror
                <button type="submit">Conferma e accedi</button>
            </form>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-button" type="submit">Non sono io</button>
            </form>
        </section>
    </div>
@endsection
