@extends('layouts.app')
@section('title', 'Documenti')
@section('content')
<section id="dashboard"><div class="eyebrow">Condominio EdilCise</div><div class="heading"><div><h1>I documenti, in ordine.</h1><p>Consulta le spese e i documenti condivisi dall’amministratore.</p></div></div>@if(in_array($account->role, ['Super_user', 'amministratore'], true))
<details class="card upload-panel" @if($errors->any()) open @endif><summary>Carica un documento</summary><form class="card" method="POST" enctype="multipart/form-data" action="{{ route('documents.store') }}">
@csrf
<label for="title">Titolo</label><input id="title" name="title" value="{{ old('title') }}" maxlength="150" required>
<label for="supplier">Fornitore</label><input id="supplier" name="supplier" value="{{ old('supplier') }}" maxlength="150" required>
<label for="date">Data spesa</label><input id="date" name="date" type="date" value="{{ old('date') }}" required>
<label for="upload-category">Categoria (facoltativa)</label><input id="upload-category" name="category" value="{{ old('category') }}" maxlength="50">
<label for="amount">Importo (€)</label><input id="amount" name="amount" type="number" min="0" max="99999999.99" step="0.01" value="{{ old('amount') }}" required>
<label for="file">PDF o immagine (facoltativo) · massimo 10 MB</label><input id="file" name="file" type="file" accept="application/pdf,image/jpeg,image/png">
<button type="submit">Carica documento</button></form></details>
@endif
<div class="card month-panel">
<nav class="month-navigation" aria-label="Navigazione mensile delle spese">
<a class="month-arrow" href="{{ $monthNavigation['previous'] }}" aria-label="Mese precedente"><span aria-hidden="true">←</span> Precedente</a>
<strong class="month-title" aria-live="polite">{{ $monthLabel }}</strong>
<a class="month-arrow" href="{{ $monthNavigation['next'] }}" aria-label="Mese successivo">Successivo <span aria-hidden="true">→</span></a>
</nav>
<form class="month-picker" method="GET" action="{{ route('dashboard') }}">
<input type="hidden" name="q" value="{{ request('q') }}">
<input type="hidden" name="category" value="{{ request('category') }}">
<label for="month">Vai a un mese</label>
<input id="month" name="month" type="month" value="{{ $selectedMonth }}" required>
<button type="submit">Mostra mese</button>
<a class="text-button" href="{{ $monthNavigation['current'] }}">Mese corrente</a>
</form>
</div>
<div class="stats"><div class="card stat"><span>Documenti disponibili</span><strong>{{ count($documents) }}</strong></div><div class="card stat"><span>Totale spese · {{ $monthLabel }}</span><strong>€ {{ number_format($monthlyTotal, 2, ',', '.') }}</strong></div><div class="card stat"><span>Ultimo caricamento</span><strong style="font-size:22px">{{ count($documents) ? reset($documents)['date'] : '—' }}</strong></div></div><div class="card archive-card"><div class="row"><h2>Archivio documenti · {{ $monthLabel }}</h2><span class="subtle">{{ count($documents) }} documenti</span></div><form class="toolbar" method="GET" action="{{ route('dashboard') }}"><input name="month" type="hidden" value="{{ $selectedMonth }}"><input id="search" name="q" value="{{ request('q') }}" type="search" aria-label="Cerca un documento" placeholder="Cerca per documento o fornitore…"><select id="category" name="category" aria-label="Filtra per categoria"><option value="">Tutte le categorie</option>@foreach($categories as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select><button type="submit">Filtra</button><a class="text-button" href="{{ route('dashboard', ['month' => $selectedMonth]) }}">Azzera filtri</a></form><div class="table-wrap"><table><thead><tr><th>Documento</th><th>Data spesa</th><th>Categoria</th><th>Importo</th><th><span class="subtle">Apri</span></th></tr></thead><tbody id="documents">@foreach($documents as $id => $document)<tr><td><div class="docname"><span class="file">PDF</span><div><strong>{{ $document['title'] }}</strong><small>{{ $document['supplier'] }}</small></div></div></td><td>{{ $document['date'] }}</td><td><span class="category">{{ $document['category'] }}</span></td><td style="white-space:nowrap">€ {{ number_format($document['amount'], 2, ',', '.') }}</td><td><a href="{{ route('documents.show', $id) }}" aria-label="Visualizza {{ $document['title'] }}">Visualizza</a></td></tr>@endforeach</tbody></table></div><div class="mobile-documents" aria-label="Elenco documenti">@foreach($documents as $id => $document)
<article class="document-card">
<div class="docname"><span class="file" aria-hidden="true">PDF</span><div><h3>{{ $document['title'] }}</h3><span class="subtle">{{ $document['supplier'] }}</span></div></div>
<dl><div><dt>Data spesa</dt><dd>{{ $document['date'] }}</dd></div><div><dt>Categoria</dt><dd>{{ $document['category'] }}</dd></div><div><dt>Importo</dt><dd class="document-amount">€ {{ number_format($document['amount'], 2, ',', '.') }}</dd></div></dl>
<a class="document-link" href="{{ route('documents.show', $id) }}" aria-label="Visualizza {{ $document['title'] }}">Visualizza documento <span aria-hidden="true">↗</span></a>
</article>@endforeach</div><p id="empty" @if(count($documents)) hidden @endif style="margin:24px 0">Nessun documento corrisponde alla ricerca.</p><div class="foot"><span id="count">{{ count($documents) }} documenti</span><span>Importi riferiti al condominio</span></div></div><div class="notice">I documenti sono pubblicati dall’amministratore. Per chiarimenti, contatta lo studio di amministrazione.</div></section>
@endsection