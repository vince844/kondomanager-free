{{--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
--}}
{{--
  «Manutenzione in corso» (bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scena 6): il programma in manutenzione (`php artisan down`).
  Fino alla 1.12.0-beta.13 usciva la pagina di Laravel, in inglese («Service Unavailable»). La pagina si ricarica da sola (ogni `Retry-After` secondi, o
  ogni 30); non legge chi è collegato (in manutenzione la sessione non c'è) e non stampa il messaggio. Il ripristino di un backup ha la sua pagina
  (`restore/in-progress`).
--}}
@extends('errors.minimal')

@php
    $attesa = isset($exception) && method_exists($exception, 'getHeaders') ? (int) ($exception->getHeaders()['Retry-After'] ?? 0) : 0;
    $attesa = $attesa > 0 ? min($attesa, 300) : 30;
    $paginaIniziale = url('/');
@endphp

@push('testa')
    <meta http-equiv="refresh" content="{{ $attesa }}">
@endpush

@section('title', __('errors.503_title'))
@section('code', '503')
@section('tono', 'am')
@section('message', __('errors.503_heading'))
@section('icona')
    <svg class="i l" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
@endsection
@section('corpo')
    <p class="lead">{{ __('errors.503_message') }}</p>
    <div class="ep-nota">
        <span class="spin" aria-hidden="true"></span>
        <div>{{ __('errors.503_ricarica', ['secondi' => $attesa]) }} <b>{{ __('errors.503_ultimo_controllo', ['ora' => now()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i')]) }}</b></div>
    </div>
@endsection
@section('azioni')
    <a class="eb" href="" data-riprova>
        <svg class="i s" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>{{ __('errors.riprova_adesso') }}
    </a>
@endsection
