{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
    Troppi tentativi (429): la risposta dei limiti di rotta, per le prove della password attuale, i moduli per
    ospiti e il login. Chi la riceve legge che cosa è successo e quanto aspettare, nella lingua
    dell'installazione: il tempo è quello del limite che si è fermato (`Retry-After`, in secondi).
    Le chiamate JSON non arrivano qui: ricevono il JSON del framework, e la pagina che le fa scrive il suo
    messaggio. Nella veste comune delle pagine d'errore (1.12.0-beta.14): «Riprova» si accende da solo quando il tempo è passato, e ricarica la pagina intera
    da cui è partita l'operazione.
--}}
@extends('errors.minimal')

@php
    $secondi = isset($exception) && method_exists($exception, 'getHeaders')
        ? (int) ($exception->getHeaders()['Retry-After'] ?? 0)
        : 0;
    $minuti = $secondi > 0 ? (int) ceil($secondi / 60) : 0;
    // `checkPermissionTo` e non `hasPermissionTo`: una pagina d'errore non deve fallire a sua volta (dentro `paginaIniziale()`).
    $utenteDelLimite = rescue(fn () => auth()->user(), null, false);
    $paginaIniziale = \App\Support\Errori\PaginaDiErrore::paginaIniziale($utenteDelLimite instanceof \App\Models\User ? $utenteDelLimite : null);
@endphp

@section('title', __('errors.429_title'))
@section('code', '429')
@section('tono', 'am')
@section('message', __('errors.429_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></svg>
@endsection
@section('corpo')
    <p class="lead">
        {{ __('errors.429_message') }}
        <b>{{ $minuti > 0 ? trans_choice('errors.429_retry_minutes', $minuti) : __('errors.429_retry_later') }}</b>
    </p>
@endsection
@section('azioni')
    <a class="eb" href="{{ $paginaIniziale }}" target="_top">
        <svg class="i s" viewBox="0 0 24 24"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>{{ $utenteDelLimite ? __('errors.pagina_iniziale') : __('errors.back_to_login') }}
    </a>
    <button type="button" class="eb pri" data-ricarica @if ($secondi > 0) disabled data-fra-secondi="{{ $secondi }}" @endif
            data-attesa="{{ __('errors.riprova_fra', ['tempo' => ':tempo']) }}" data-pronto="{{ __('errors.riprova') }}">
        <svg class="i s" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg><span data-etichetta>{{ __('errors.riprova') }}</span>
    </button>
@endsection
