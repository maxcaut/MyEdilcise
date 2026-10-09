<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Area condominiale') · {{ config('app.name') }}</title><link rel="icon" href="{{ asset(config('condominio.favicon_url') ?: 'favicon.ico') }}"><link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}"></head><body>
@if(!request()->routeIs('login', 'identity.confirm'))
<div class="shell"><aside><div class="brand">@include('components.brand')</div><div class="building"><strong>{{ config('condominio.name') }}</strong>@if(config('condominio.city'))<div class="subtle">{{ config('condominio.city') }}</div>@endif</div><nav class="side-nav" aria-label="Navigazione principale"><a class="{{ request()->routeIs('dashboard', 'documents.show') ? 'active' : '' }}" href="{{ route('dashboard') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg><span>Documenti</span></a><a class="{{ request()->routeIs('profile*') ? 'active' : '' }}" href="{{ route('profile') }}"><svg class="nav-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/></svg><span>Il mio profilo</span></a></nav><div class="side-bottom"><span class="avatar">MR</span><span class="account-name">{{ $account->name }} {{ $account->surname }}</span><div class="subtle">{{ $account->role }}</div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Esci</button></form></div></aside><main class="main"><div class="topline"><span class="subtle">Il tuo spazio condominiale</span><span class="pill">{{ $account->role }}</span></div>
@endif
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><strong>Controlla i dati inseriti.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
@if(!request()->routeIs('login', 'identity.confirm'))</main></div>@endif
@if(!request()->routeIs('login', 'identity.confirm'))
<div id="navigation-loading" class="navigation-loading" role="status" aria-live="polite" aria-atomic="true" hidden>
    <div class="navigation-loading-panel">
        <span class="navigation-loading-spinner" aria-hidden="true"></span>
        <strong id="navigation-loading-label"></strong>
        <span class="subtle">Attendi un momento, stiamo aprendo la schermata.</span>
    </div>
</div>
<script>
    const navigationLoading = document.getElementById('navigation-loading');
    const navigationLoadingLabel = document.getElementById('navigation-loading-label');
    const navigationLinks = document.querySelectorAll('.side-nav a');

    const resetNavigation = () => {
        navigationLoading.hidden = true;
        navigationLoadingLabel.textContent = '';
        navigationLinks.forEach((link) => {
            link.classList.remove('is-loading');
            link.removeAttribute('aria-busy');
        });
    };

    navigationLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank') {
                return;
            }

            if (link.href === window.location.href) {
                event.preventDefault();
                return;
            }

            resetNavigation();
            link.classList.add('is-loading');
            link.setAttribute('aria-busy', 'true');
            navigationLoadingLabel.textContent = `Apertura ${link.textContent.trim()}…`;
            navigationLoading.hidden = false;
        });
    });

    window.addEventListener('pageshow', resetNavigation);
    window.addEventListener('pagehide', resetNavigation);
</script>
@endif
</body></html>
