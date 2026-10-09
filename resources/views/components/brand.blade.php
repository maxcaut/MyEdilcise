@if(config('condominio.logo_url'))
<img class="brand-logo" src="{{ asset(config('condominio.logo_url')) }}" alt="{{ config('condominio.name') }}{{ config('condominio.city') ? ' — '.config('condominio.city') : '' }}">
@else
<span>{{ config('condominio.name') }}</span>
@endif
