@extends('layouts.app')
@section('title', 'Anteprima documento')
@section('content')
<a href="{{ route('dashboard') }}">← Torna ai documenti</a><div class="card" style="margin-top:24px"><div class="eyebrow">Documento</div><h1>{{ $document['title'] }}</h1><p>{{ $document['supplier'] }} · {{ $document['date'] }}</p><span class="category">{{ $document['category'] }}</span><h2 style="margin-top:20px">€ {{ number_format($document['amount'], 2, ',', '.') }}</h2><div class="preview-panel">@if($document['has_file'] ?? false)<a class="button" href="{{ route('documents.download', $document['id']) }}" target="_blank" rel="noopener">Apri documento originale</a>@else<p>Nessun allegato.</p>@endif
@if($account->role === 'Super_user')
<form method="POST" action="{{ route('documents.destroy', $document['id']) }}">@csrf @method('DELETE')<button type="submit">Elimina documento</button></form>
@endif</div></div>
@endsection
