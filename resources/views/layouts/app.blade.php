<!doctype html>
<html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'EdilCise') · Area condominiale</title><link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}"></head><body>
@if(!request()->routeIs('login'))
<div class="shell"><aside><div class="brand">@include('components.brand')</div><div class="building"><strong>Condominio EdilCise</strong><div class="subtle">Somma Vesuviana</div></div><nav class="side-nav" aria-label="Navigazione principale"><a class="{{ request()->routeIs('dashboard', 'documents.show') ? 'active' : '' }}" href="{{ route('dashboard') }}">Documenti</a><a class="{{ request()->routeIs('profile*') ? 'active' : '' }}" href="{{ route('profile') }}">Il mio profilo</a></nav><div class="side-bottom"><span class="avatar">MR</span>{{ $account->name }} {{ $account->surname }}<div class="subtle">{{ $account->role }}</div><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-button">Esci</button></form></div></aside><main class="main"><div class="topline"><span class="subtle">Il tuo spazio condominiale</span><span class="pill">{{ $account->role }}</span></div>
@endif
@if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><strong>Controlla i dati inseriti.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
@if(!request()->routeIs('login'))</main></div>@endif
</body></html>