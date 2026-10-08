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

                <form id="login-form" method="POST" action="{{ route('login.store') }}">
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
    <div id="login-loading" class="login-loading" role="status" aria-live="polite" aria-atomic="true" hidden>
        <div class="login-loading-content">
            <div class="login-loading-logo" aria-hidden="true">
                @include('components.brand')
            </div>
            <span class="login-loading-spinner" aria-hidden="true"></span>
            <p>Accesso in corso…</p>
        </div>
    </div>

    <script>
        const loginForm = document.getElementById('login-form');
        const loginLoading = document.getElementById('login-loading');
        const loginButton = loginForm.querySelector('button[type="submit"]');

        loginForm.addEventListener('submit', (event) => {
            if (loginButton.disabled) {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            loginLoading.hidden = false;
            loginForm.setAttribute('aria-busy', 'true');
            loginButton.disabled = true;
            loginButton.textContent = 'Accesso in corso…';

            requestAnimationFrame(() => {
                setTimeout(() => HTMLFormElement.prototype.submit.call(loginForm), 150);
            });
        });

        window.addEventListener('pageshow', () => {
            loginLoading.hidden = true;
            loginForm.removeAttribute('aria-busy');
            loginButton.disabled = false;
            loginButton.textContent = 'Accedi';
        });
    </script>
@endsection
