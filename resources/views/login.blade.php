@extends('layouts.app')

@section('title', 'Accedi')

@section('content')
    <section id="login" class="login">
        <div class="login-story">
            <div class="brand">
                @include('components.brand')
            </div>

            <h1>La vita del condominio,<br>più trasparente.</h1>
            <p>Documenti e spese in un unico spazio, sempre a disposizione di chi abita qui.</p>

           
            
        </div>

        <div class="login-form">
            <div class="form-width">
                <div class="eyebrow">
                    Il tuo condominio, online
                </div>
                <h1>Bentornato.</h1>
                <p>Accedi per consultare i documenti del tuo condominio.</p>

                <form method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <label for="email">Indirizzo email</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" placeholder="nome@esempio.it" required autocomplete="username">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" placeholder="Inserisci la tua password" required autocomplete="current-password">
                    <button type="submit" style="width:100%;margin-top:24px">Accedi</button>
                </form>
                <p class="subtle" style="margin-top:22px;text-align:center">Non hai un account? Contatta il tuo amministratore.</p>

            </div>
        </div>
    </section>
@endsection
